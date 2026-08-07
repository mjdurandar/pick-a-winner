<?php

namespace App\Services;

use App\Exceptions\MailchimpApiException;
use App\Models\MailchimpConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Marketing API client bound to one OAuth connection.
 *
 * Every call is addressed at the datacenter stored with the connection. A 401
 * means the token was revoked or expired on Mailchimp's side, which no retry
 * fixes — the connection is flipped to needs_reconnect so the settings page can
 * say so and running jobs can stop instead of burning attempts.
 */
class MailchimpApi
{
    public function __construct(protected MailchimpConnection $connection) {}

    public static function for(MailchimpConnection $connection): self
    {
        return new self($connection);
    }

    public function connection(): MailchimpConnection
    {
        return $this->connection;
    }

    /**
     * Audiences for the audience dropdown.
     *
     * @return array<int, array{id: string, name: string, member_count: int}>
     */
    public function lists(): array
    {
        $response = $this->get('/lists', [
            'count' => 100,
            'fields' => 'lists.id,lists.name,lists.stats.member_count',
        ]);

        return collect($response->json('lists') ?? [])
            ->map(fn (array $list) => [
                'id' => $list['id'],
                'name' => $list['name'],
                'member_count' => $list['stats']['member_count'] ?? 0,
            ])
            ->all();
    }

    /**
     * One audience. double_optin decides both the preview wording and the member
     * status the import sends, so it is read before anything is written.
     *
     * @return array{id: string, name: string, double_optin: bool, subscribe_url: ?string}
     */
    public function list(string $listId): array
    {
        $response = $this->get("/lists/{$listId}", [
            'fields' => 'id,name,double_optin,subscribe_url_long,subscribe_url_short',
        ]);

        return [
            'id' => $response->json('id'),
            'name' => $response->json('name'),
            'double_optin' => (bool) $response->json('double_optin'),
            // Handed to compliance-locked contacts, who can only opt back in
            // themselves through Mailchimp's hosted form.
            'subscribe_url' => $response->json('subscribe_url_long') ?: $response->json('subscribe_url_short'),
        ];
    }

    /**
     * Merge fields for the column mapping UI. EMAIL is not a merge field in
     * Mailchimp's model — it lives on the member record — so it is prepended here
     * to give the mapping UI something to bind the required email column to.
     *
     * @return array<int, array{tag: string, name: string, required: bool, type: string}>
     */
    public function mergeFields(string $listId): array
    {
        $response = $this->get("/lists/{$listId}/merge-fields", [
            'count' => 100,
            'fields' => 'merge_fields.tag,merge_fields.name,merge_fields.required,merge_fields.type',
        ]);

        $fields = collect($response->json('merge_fields') ?? [])
            ->map(fn (array $field) => [
                'tag' => $field['tag'],
                'name' => $field['name'],
                'required' => (bool) ($field['required'] ?? false),
                'type' => $field['type'] ?? 'text',
            ])
            ->all();

        return array_merge([[
            'tag' => 'EMAIL',
            'name' => 'Email Address',
            'required' => true,
            'type' => 'email',
        ]], $fields);
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->get($this->url($path), $query));
    }

    protected function url(string $path): string
    {
        return $this->connection->apiBaseUrl().$path;
    }

    protected function request(): PendingRequest
    {
        // Bearer is what Mailchimp's own SDK sends for an OAuth access token. The
        // metadata endpoint is the odd one out and wants "OAuth <token>", which is
        // why it lives in MailchimpOAuth rather than here.
        return Http::withToken($this->connection->access_token)
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     *
     * @throws MailchimpApiException
     */
    protected function send(callable $callback): Response
    {
        $response = $callback($this->request());

        if ($response->status() === 401) {
            $this->connection->markNeedsReconnect();

            MailchimpAuditLog::record(MailchimpAuditLog::NEEDS_RECONNECT, [
                'account' => $this->connection->account,
            ]);

            throw MailchimpApiException::needsReconnect();
        }

        if ($response->failed()) {
            throw MailchimpApiException::fromResponse($response);
        }

        return $response;
    }
}
