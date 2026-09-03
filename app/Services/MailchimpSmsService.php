<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The handful of Mailchimp calls the SMS scheduler needs.
 *
 * Kept apart from MailchimpService, which is about audience imports. This one is
 * about campaigns: resolve a tag to a segment id, create an SMS campaign against
 * it, give it a body, and ask Mailchimp to schedule it. Nothing here sends —
 * the send action exists in the API and is deliberately not wrapped.
 *
 * SMS campaigns are created against the usual ten-character list id (they
 * read back with the numeric web_id) and target recipients by segment id. A tag is a static
 * segment, so a SHOW tag can be used directly; an "A & B" intersection needs a
 * saved segment created for it.
 */
class MailchimpSmsService
{
    protected string $apiKey;

    protected string $serverPrefix;

    protected string $baseUrl;

    /** Ten-character id of the audience SMS goes to. */
    protected string $listId;

    /** Per-request memo of tag lookups, so a batch of rows for one city is one call. */
    protected array $tagCache = [];

    public function __construct(string $account = 'anz')
    {
        $this->apiKey = (string) Config::get("services.mailchimp.{$account}.key");
        $this->serverPrefix = (string) Config::get("services.mailchimp.{$account}.server");
        $this->listId = (string) Config::get("services.mailchimp.{$account}.sms_list_id");
        $this->baseUrl = "https://{$this->serverPrefix}.api.mailchimp.com/3.0";
    }

    public function serverPrefix(): string
    {
        return $this->serverPrefix;
    }

    public function listId(): string
    {
        return $this->listId;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->serverPrefix !== '' && $this->listId !== '';
    }

    /**
     * Exact-name tag lookup: ['id' => int, 'name' => string] or null.
     *
     * tag-search is a prefix search, so "SHOW - MELBOURNE" also returns
     * MELBOURNE CITY / EAST / NORTH / WEST. Only a name-for-name match counts.
     */
    public function findTag(string $name): ?array
    {
        $key = mb_strtoupper(trim($name));

        if (array_key_exists($key, $this->tagCache)) {
            return $this->tagCache[$key];
        }

        $tags = $this->get("/lists/{$this->listId}/tag-search", ['name' => trim($name)])->json()['tags'] ?? [];

        $match = null;
        foreach ($tags as $tag) {
            if (mb_strtoupper(trim($tag['name'])) === $key) {
                $match = ['id' => (int) $tag['id'], 'name' => $tag['name']];
                break;
            }
        }

        return $this->tagCache[$key] = $match;
    }

    /**
     * A saved segment of members who carry every one of the given tags.
     * Reused by name if it already exists, so re-running never piles up twins.
     * Returns ['id' => int, 'name' => string].
     */
    public function findOrCreateIntersectionSegment(string $name, array $tagIds): array
    {
        $existing = $this->get("/lists/{$this->listId}/segments", [
            'type' => 'saved',
            'count' => 1000,
            'fields' => 'segments.id,segments.name',
        ])->json()['segments'] ?? [];

        foreach ($existing as $segment) {
            if (mb_strtoupper(trim($segment['name'])) === mb_strtoupper(trim($name))) {
                return ['id' => (int) $segment['id'], 'name' => $segment['name']];
            }
        }

        $conditions = array_map(fn (int $id) => [
            'condition_type' => 'StaticSegment',
            'field' => 'static_segment',
            'op' => 'static_is',
            'value' => $id,
        ], array_values($tagIds));

        $created = $this->post("/lists/{$this->listId}/segments", [
            'name' => $name,
            'options' => ['match' => 'all', 'conditions' => $conditions],
        ])->json();

        return ['id' => (int) $created['id'], 'name' => $created['name']];
    }

    /** Creates a draft SMS campaign targeting one segment, minus any excluded ones. Returns the campaign id. */
    public function createCampaign(string $name, int $segmentId, array $excludedSegmentIds = []): string
    {
        // The schema calls list_id numeric and every campaign reads back with the
        // numeric web_id — but creating with the web_id fails with "List is
        // inactive". The ten-character list id is what actually works.
        $campaign = $this->post('/sms-campaigns', [
            'name' => $name,
            'list_id' => $this->listId,
            'segments' => [$segmentId],
            'excluded_segments' => array_values($excludedSegmentIds),
        ])->json();

        return $campaign['id'] ?? throw new RuntimeException('Mailchimp created the SMS campaign but returned no id.');
    }

    public function updateCampaign(string $campaignId, string $name, int $segmentId, array $excludedSegmentIds = []): void
    {
        $this->request()->patch("{$this->baseUrl}/sms-campaigns/{$campaignId}", [
            'name' => $name,
            'segments' => [$segmentId],
            'excluded_segments' => array_values($excludedSegmentIds),
        ])->throw();
    }

    public function setContent(string $campaignId, string $messageBody): array
    {
        return $this->request()
            ->put("{$this->baseUrl}/sms-campaigns/{$campaignId}/content", ['message_body' => $messageBody])
            ->throw()
            ->json();
    }

    /** Asks Mailchimp to send at the given moment. The time is sent as UTC. */
    public function schedule(string $campaignId, CarbonInterface $sendAtUtc): void
    {
        $this->post("/sms-campaigns/{$campaignId}/actions/schedule", [
            'schedule_time' => $sendAtUtc->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** Pulls a scheduled campaign back to draft. Only works before it starts sending. */
    public function cancel(string $campaignId): void
    {
        $this->post("/sms-campaigns/{$campaignId}/actions/cancel-send");
    }

    public function delete(string $campaignId): void
    {
        $this->request()->delete("{$this->baseUrl}/sms-campaigns/{$campaignId}")->throw();
    }

    public function getCampaign(string $campaignId): array
    {
        $campaign = $this->get("/sms-campaigns/{$campaignId}")->json();
        unset($campaign['_links']);

        return $campaign;
    }

    // -- HTTP ----------------------------------------------------------------

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth('anystring', $this->apiKey)
            ->acceptJson()
            ->timeout(30);
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->request()->get($this->baseUrl.$path, $query)->throw();
    }

    protected function post(string $path, array $body = []): Response
    {
        $response = $this->request()->post($this->baseUrl.$path, $body);

        if ($response->failed()) {
            // Mailchimp's error body carries a human "detail" line that is far
            // more useful than the status code; surface that.
            $detail = $response->json('detail') ?? $response->json('title') ?? $response->body();
            // Validation failures name the field in an errors array; that is the
            // part an admin can act on, so append it.
            $fields = collect($response->json('errors') ?? [])
                ->map(fn ($e) => trim(($e['field'] ?? '').': '.($e['message'] ?? ''), ': '))
                ->filter()
                ->implode('; ');
            throw new RuntimeException("Mailchimp {$response->status()}: {$detail}".($fields ? " [{$fields}]" : ''));
        }

        return $response;
    }
}
