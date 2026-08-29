<?php

namespace App\Services;

use App\Exceptions\GoogleOAuthException;
use App\Models\GoogleConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The OAuth2 handshake with Google, and the token refresh that keeps it alive.
 *
 * The admin authenticates on Google's own site; this application never sees their
 * password. What comes back is a short-lived access token plus a refresh token
 * that is the durable credential.
 *
 * Reading the master sheet only needs spreadsheets.readonly. That scope cannot
 * write to the sheet at all, which is the point — the sync must never be able to
 * modify a file someone else owns.
 */
class GoogleOAuth
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public const SCOPES = [
        'https://www.googleapis.com/auth/spreadsheets.readonly',
        'openid',
        'email',
    ];

    /**
     * Session key holding the pending CSRF state, written on redirect out and
     * consumed on callback.
     */
    public const SESSION_KEY = 'google_oauth_state';

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function clientId(): ?string
    {
        return config('services.google.oauth.client_id');
    }

    protected function clientSecret(): ?string
    {
        return config('services.google.oauth.client_secret');
    }

    /**
     * Must match the redirect URI registered in the Cloud console character for
     * character, including scheme and any trailing path.
     */
    public function redirectUri(): string
    {
        return config('services.google.oauth.redirect')
            ?: route('google.integration.callback');
    }

    public function newState(): string
    {
        return Str::random(40);
    }

    /**
     * access_type=offline and prompt=consent together are what make Google return
     * a refresh token. Without prompt=consent it is issued only on the very first
     * authorization for the client, so a reconnect would come back with no
     * refresh token and the sync would die an hour later.
     */
    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Trade the one-time code for an access token and a refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     *
     * @throws GoogleOAuthException
     */
    public function exchangeCode(string $code): array
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
            Log::error('Google OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new GoogleOAuthException(
                'Google rejected the authorization code (HTTP '.$response->status().'). Please try connecting again.'
            );
        }

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleOAuthException('Google did not return an access token.');
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            // Storing a connection without one would work for an hour and then
            // fail every sync from then on, so refuse it here where the admin is
            // still watching and can retry.
            throw new GoogleOAuthException(
                'Google did not return a refresh token. Remove this app at myaccount.google.com/permissions and connect again.'
            );
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => (int) ($response->json('expires_in') ?: 3600),
        ];
    }

    /**
     * Exchange the stored refresh token for a fresh access token and persist it.
     *
     * Google omits refresh_token from this response — the stored one stays valid
     * and must not be overwritten with null.
     *
     * @throws GoogleOAuthException
     */
    public function refresh(GoogleConnection $connection): GoogleConnection
    {
        $response = Http::asForm()
            ->timeout(15)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $connection->refresh_token,
            ]);

        if ($response->failed()) {
            Log::error('Google OAuth token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // invalid_grant means the refresh token is dead for good — revoked,
            // expired on a testing-mode client, or the password changed. Retrying
            // will never fix it, so flag it for a human instead.
            if ($response->json('error') === 'invalid_grant') {
                $connection->markNeedsReconnect();

                throw new GoogleOAuthException(
                    'Google has revoked this connection. Reconnect the Google account to resume syncing.'
                );
            }

            throw new GoogleOAuthException(
                'Could not refresh the Google access token (HTTP '.$response->status().').'
            );
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleOAuthException('Google did not return a refreshed access token.');
        }

        $connection->forceFill([
            'access_token' => $accessToken,
            'token_expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?: 3600)),
            'status' => GoogleConnection::STATUS_ACTIVE,
        ])->save();

        return $connection;
    }

    /**
     * Identify the account behind a token, so the settings page can show which
     * Google login the sync is reading as. Best effort — a connection is still
     * usable if this fails.
     */
    public function accountEmail(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->get(self::USERINFO_URL);
        } catch (\Throwable $e) {
            Log::warning('Google userinfo lookup threw', ['message' => $e->getMessage()]);

            return null;
        }

        return $response->successful() ? $response->json('email') : null;
    }

    /**
     * Revoke with Google so disconnecting here also ends the authorization there.
     * Returns false if Google refused — the caller decides what to tell the admin
     * rather than this failing silently.
     */
    public function revoke(string $token): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable $e) {
            Log::error('Google OAuth revoke threw', ['message' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Google OAuth revoke failed', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}
