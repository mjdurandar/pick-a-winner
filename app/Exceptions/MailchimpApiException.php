<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

/**
 * A Marketing API call that did not succeed.
 *
 * The status code and Mailchimp's own detail string are kept rather than
 * flattened into a message, because every row-level failure has to be recorded
 * with the actual response code — errors are never swallowed here.
 */
class MailchimpApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $detail = null,
        public readonly bool $needsReconnect = false,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        // Mailchimp returns RFC 7807 problem documents: title, detail, status.
        $detail = $response->json('detail') ?: $response->json('title') ?: $response->body();

        return new self(
            'Mailchimp returned HTTP '.$response->status().': '.$detail,
            $response->status(),
            is_string($detail) ? $detail : null,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }
}
