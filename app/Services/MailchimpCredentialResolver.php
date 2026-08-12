<?php

namespace App\Services;

use App\Exceptions\MailchimpApiException;
use App\Models\MailchimpConnection;
use Illuminate\Support\Str;

/**
 * Decides which credential an account uses.
 *
 * An OAuth connection wins when one exists and is healthy, because it carries an
 * audit trail — who granted access and when. The static API keys in the server
 * environment are the fallback, which is what the rest of this application has
 * always used.
 */
class MailchimpCredentialResolver
{
    /**
     * @throws MailchimpApiException
     */
    public function resolve(string $account): MailchimpCredentials
    {
        $credentials = $this->resolveOrNull($account);

        if (! $credentials) {
            throw new MailchimpApiException(
                "No Mailchimp credentials for the {$account} account. Configure an API key for it "
                .'in the server environment.'
            );
        }

        return $credentials;
    }

    public function resolveOrNull(string $account): ?MailchimpCredentials
    {
        $connection = MailchimpConnection::where('account', $account)->first();

        if ($connection && $connection->isActive()) {
            return MailchimpCredentials::fromConnection($connection);
        }

        // A connection needing reconnection deliberately does NOT fall through to the
        // API key. Silently switching credentials would hide the broken connection
        // and make the reconnect banner meaningless.
        if ($connection) {
            return null;
        }

        return $this->fromEnvironment($account);
    }

    /**
     * How this account is currently reachable, for the settings and wizard pages.
     * Never returns the secret itself.
     *
     * @return array{source: ?string, datacenter: ?string, usable: bool}
     */
    public function describe(string $account): array
    {
        $connection = MailchimpConnection::where('account', $account)->first();

        if ($connection && ! $connection->isActive()) {
            return ['source' => MailchimpCredentials::SOURCE_OAUTH, 'datacenter' => $connection->datacenter, 'usable' => false];
        }

        $credentials = $this->resolveOrNull($account);

        return [
            'source' => $credentials?->source,
            'datacenter' => $credentials?->datacenter,
            'usable' => $credentials !== null,
        ];
    }

    protected function fromEnvironment(string $account): ?MailchimpCredentials
    {
        $key = config("services.mailchimp.{$account}.key");

        if (! filled($key)) {
            return null;
        }

        $datacenter = config("services.mailchimp.{$account}.server") ?: $this->datacenterFromKey($key);

        if (! $datacenter) {
            return null;
        }

        return MailchimpCredentials::fromApiKey($account, $key, $datacenter);
    }

    /**
     * Mailchimp API keys carry their datacenter as a suffix — "...-us13" — so a key
     * configured without its matching server prefix is still usable.
     */
    protected function datacenterFromKey(string $key): ?string
    {
        if (! str_contains($key, '-')) {
            return null;
        }

        return Str::afterLast($key, '-') ?: null;
    }
}
