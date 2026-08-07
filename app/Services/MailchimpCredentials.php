<?php

namespace App\Services;

use App\Models\MailchimpConnection;
use Illuminate\Http\Client\PendingRequest;

/**
 * What it takes to talk to one Mailchimp account: a datacenter and a secret.
 *
 * The secret is either an OAuth access token the account owner granted, or the
 * static API key already configured for this application. Callers do not care
 * which — this decides how the request is authorized and what happens when
 * Mailchimp rejects it.
 */
class MailchimpCredentials
{
    public const SOURCE_OAUTH = 'oauth';

    public const SOURCE_API_KEY = 'api_key';

    protected function __construct(
        public readonly string $account,
        public readonly string $datacenter,
        protected readonly string $secret,
        public readonly string $source,
        public readonly ?MailchimpConnection $connection = null,
    ) {}

    public static function fromConnection(MailchimpConnection $connection): self
    {
        return new self(
            $connection->account,
            $connection->datacenter,
            $connection->access_token,
            self::SOURCE_OAUTH,
            $connection,
        );
    }

    public static function fromApiKey(string $account, string $key, string $datacenter): self
    {
        return new self($account, $datacenter, $key, self::SOURCE_API_KEY);
    }

    public function isOAuth(): bool
    {
        return $this->source === self::SOURCE_OAUTH;
    }

    public function baseUrl(): string
    {
        return "https://{$this->datacenter}.api.mailchimp.com/3.0";
    }

    /**
     * OAuth tokens go in as a bearer token; API keys use the basic-auth form
     * Mailchimp documents and that MailchimpService has always used here.
     */
    public function authorize(PendingRequest $request): PendingRequest
    {
        return $this->isOAuth()
            ? $request->withToken($this->secret)
            : $request->withBasicAuth('anystring', $this->secret);
    }

    /**
     * Mailchimp rejected the credential. An OAuth token can be re-granted from the
     * settings page, so the connection is flagged for it. A bad API key is server
     * configuration and no amount of clicking in the app will fix it.
     */
    public function markRejected(): void
    {
        $this->connection?->markNeedsReconnect();
    }

    public function rejectionMessage(): string
    {
        return $this->isOAuth()
            ? 'Mailchimp rejected the stored credentials. Reconnect the account to continue.'
            : "Mailchimp rejected the API key configured for the {$this->account} account. Check the key in the server environment.";
    }
}
