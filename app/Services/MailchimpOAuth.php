<?php

namespace App\Services;

use App\Exceptions\MailchimpOAuthException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The OAuth2 handshake with Mailchimp.
 *
 * The admin authenticates on Mailchimp's own site; this application never sees
 * their password. What comes back is an access token that does not expire, plus
 * a datacenter prefix from the metadata endpoint — the token alone cannot address
 * an account, so both are stored together.
 */
class MailchimpOAuth
{
    public const AUTHORIZE_URL = 'https://login.mailchimp.com/oauth2/authorize';

    public const TOKEN_URL = 'https://login.mailchimp.com/oauth2/token';

    public const METADATA_URL = 'https://login.mailchimp.com/oauth2/metadata';

    public const REVOKE_URL = 'https://login.mailchimp.com/oauth2/revoke';

    /**
     * Session key holding the pending CSRF state and the account slot being
     * connected, written on redirect out and consumed on callback.
     */
    public const SESSION_KEY = 'mailchimp_oauth_state';

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function clientId(): ?string
    {
        return config('services.mailchimp.oauth.client_id');
    }

    protected function clientSecret(): ?string
    {
        return config('services.mailchimp.oauth.client_secret');
    }

    /**
     * Must match the redirect URI registered with Mailchimp character for
     * character, including scheme and any trailing path.
     */
    public function redirectUri(): string
    {
        return config('services.mailchimp.oauth.redirect')
            ?: route('mailchimp.integration.callback');
    }

    public function newState(): string
    {
        return Str::random(40);
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * Trade the one-time code for an access token.
     *
     * @throws MailchimpOAuthException
     */
    public function exchangeCode(string $code): string
    {
        $response = Http::asForm()
            ->timeout(15)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);

        if ($response->failed()) {
            // The body can echo the client secret back, so it goes to the log at
            // error level and never to the admin's screen.
            Log::error('Mailchimp OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new MailchimpOAuthException(
                'Mailchimp rejected the authorization code (HTTP '.$response->status().'). Please try connecting again.'
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new MailchimpOAuthException('Mailchimp did not return an access token.');
        }

        return $token;
    }

    /**
     * Identify the account behind a token. Returns the datacenter prefix, the
     * Mailchimp account id and its display name.
     *
     * @return array{datacenter: string, account_id: ?string, account_name: ?string}
     *
     * @throws MailchimpOAuthException
     */
    public function metadata(string $token): array
    {
        $response = Http::withHeaders(['Authorization' => 'OAuth '.$token])
            ->timeout(15)
            ->get(self::METADATA_URL);

        if ($response->failed()) {
            Log::error('Mailchimp OAuth metadata lookup failed', [
                'status' => $response->status(),
            ]);

            throw new MailchimpOAuthException(
                'Could not read the Mailchimp account details (HTTP '.$response->status().').'
            );
        }

        $datacenter = $response->json('dc') ?: $this->datacenterFromEndpoint($response->json('api_endpoint'));

        if (! $datacenter) {
            // Without this every later API call would be addressed at nothing, so
            // refuse to store a half-usable connection.
            throw new MailchimpOAuthException('Mailchimp did not return a datacenter for this account.');
        }

        $accountId = $response->json('user_id') ?? $response->json('login.login_id');

        return [
            'datacenter' => $datacenter,
            'account_id' => $accountId !== null ? (string) $accountId : null,
            'account_name' => $response->json('accountname') ?: $response->json('login.login_email'),
        ];
    }

    /**
     * Revoke the token with Mailchimp so disconnecting here also ends the
     * authorization there. Returns false if Mailchimp refused — the caller
     * decides what to tell the admin rather than this failing silently.
     */
    public function revoke(string $token): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post(self::REVOKE_URL, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'token' => $token,
                ]);
        } catch (\Throwable $e) {
            Log::error('Mailchimp OAuth revoke threw', ['message' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Mailchimp OAuth revoke failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * api_endpoint looks like https://us14.api.mailchimp.com — used as a fallback
     * when the metadata response omits `dc`.
     */
    protected function datacenterFromEndpoint(?string $endpoint): ?string
    {
        if (! $endpoint) {
            return null;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        if (! $host || ! str_contains($host, '.')) {
            return null;
        }

        return Str::before($host, '.') ?: null;
    }
}
