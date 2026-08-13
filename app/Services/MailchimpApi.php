<?php

namespace App\Services;

use App\Exceptions\MailchimpApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Marketing API client for one account, authorized by either an OAuth token or
 * the configured API key — MailchimpCredentials knows which.
 *
 * Every call is addressed at that credential's datacenter. A 401 means the
 * credential was rejected outright, which no retry fixes: an OAuth connection is
 * flipped to needs_reconnect so the settings page can say so and running jobs can
 * stop, and a bad API key is reported as server configuration.
 */
class MailchimpApi
{
    /** Mailchimp caps the members endpoint at 1000 records per page. */
    private const MEMBER_PAGE_SIZE = 1000;

    /** Mailchimp allows ten simultaneous connections per account. */
    private const LOOKUP_CONCURRENCY = 10;

    public function __construct(protected MailchimpCredentials $credentials) {}

    public static function for(MailchimpCredentials $credentials): self
    {
        return new self($credentials);
    }

    public function credentials(): MailchimpCredentials
    {
        return $this->credentials;
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

    /**
     * Every address already in the audience, as lowercase email => Mailchimp status.
     *
     * The dry run has to answer "is this contact already here, and in what state"
     * for every row of the file. Asking per address would be one request per row;
     * paging the audience once is a request per thousand members and gives the
     * classifier a plain array to look up in.
     *
     * Archived members are excluded from the default listing and have to be asked
     * for by name. They matter: an archived address is not a new contact, and
     * importing it resurrects rather than creates.
     *
     * Pages are fetched a connection-limit at a time rather than one after another.
     * Against a 275,000-member audience that is the difference between roughly half
     * an hour and four minutes, and the whole scan runs before a single contact is
     * written, so it was most of what an import cost.
     *
     * @return array<string, string>
     *
     * @throws MailchimpApiException
     */
    public function memberStatusIndex(string $listId): array
    {
        $index = [];

        foreach ([null, 'archived'] as $status) {
            $offset = 0;
            $reachedEnd = false;

            while (! $reachedEnd) {
                $offsets = [];

                for ($i = 0; $i < self::LOOKUP_CONCURRENCY; $i++) {
                    $offsets[] = $offset;
                    $offset += self::MEMBER_PAGE_SIZE;
                }

                $responses = $this->poolGet(collect($offsets)
                    ->mapWithKeys(fn (int $pageOffset) => [(string) $pageOffset => [
                        "/lists/{$listId}/members",
                        array_filter([
                            'count' => self::MEMBER_PAGE_SIZE,
                            'offset' => $pageOffset,
                            'status' => $status,
                            // total_items is deliberately not asked for. Mailchimp
                            // recomputes it per call — it measured two seconds a page
                            // on its own — and the loop below no longer needs it.
                            'fields' => 'members.email_address,members.status',
                        ], fn ($value) => $value !== null),
                    ]])
                    ->all());

                foreach ($offsets as $pageOffset) {
                    $members = $responses[(string) $pageOffset]->json('members') ?? [];

                    // A page short of the limit is the last one. Overshooting the end
                    // by up to nine pages is the price of not knowing the total: they
                    // come back empty, in parallel, and cost one round trip between
                    // them.
                    if (count($members) < self::MEMBER_PAGE_SIZE) {
                        $reachedEnd = true;
                    }

                    foreach ($members as $member) {
                        $email = strtolower(trim((string) ($member['email_address'] ?? '')));

                        if ($email === '') {
                            continue;
                        }

                        // The archived pass runs second and wins: Mailchimp keeps the
                        // pre-archive status on the member, so an archived contact would
                        // otherwise read as whatever they were before.
                        $index[$email] = $status ?? ($member['status'] ?? 'subscribed');
                    }
                }
            }
        }

        return $index;
    }

    /**
     * How many pages memberStatusIndex() would have to read for this audience.
     *
     * The dry run uses this to choose between scanning the audience and asking about
     * each address in the file, so it has to be cheap: two requests that ask for
     * nothing but the count.
     *
     * @throws MailchimpApiException
     */
    public function memberIndexPages(string $listId): int
    {
        $members = 0;

        foreach ([null, 'archived'] as $status) {
            $response = $this->get("/lists/{$listId}/members", array_filter([
                'count' => 1,
                'status' => $status,
                'fields' => 'total_items',
            ], fn ($value) => $value !== null));

            $members += (int) $response->json('total_items');
        }

        return (int) ceil($members / self::MEMBER_PAGE_SIZE);
    }

    /**
     * Add up to 500 contacts in one call.
     *
     * update_existing stays false deliberately. Anyone already on the list is
     * reported back as an error rather than silently overwritten, which keeps this
     * call incapable of changing an existing member's status — resubscribing is a
     * separate, deliberate act handled by resubscribe() below.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array{new: array<int, string>, errors: array<int, array{email: string, message: string, code: ?string}>}
     *
     * @throws MailchimpApiException
     */
    public function batchSubscribe(string $listId, array $members): array
    {
        $response = $this->post("/lists/{$listId}", [
            'members' => $members,
            'update_existing' => false,
        ]);

        return [
            'new' => collect($response->json('new_members') ?? [])
                ->pluck('email_address')
                ->map(fn ($email) => strtolower((string) $email))
                ->all(),
            'errors' => collect($response->json('errors') ?? [])
                ->map(fn (array $error) => [
                    'email' => strtolower((string) ($error['email_address'] ?? '')),
                    'message' => (string) ($error['error'] ?? 'Mailchimp rejected this contact.'),
                    'code' => $error['error_code'] ?? null,
                ])
                ->all(),
        ];
    }

    /**
     * Put an existing member back to subscribed.
     *
     * Contacts Mailchimp holds in a compliance state cannot be restored through the
     * API at any privilege level — only the contact can opt back in, through
     * Mailchimp's own hosted form. That refusal is a documented outcome rather than
     * an error, so it is reported instead of thrown; every other failure still
     * raises.
     *
     * @return string|null Mailchimp's own words when it refuses, null when the
     *                     contact is back
     *
     * @throws MailchimpApiException
     */
    public function resubscribe(string $listId, string $email): ?string
    {
        try {
            // skip_merge_validation matches what the sign-up form has always sent:
            // a contact coming back should not be blocked by a merge field that was
            // made required after they first subscribed.
            $this->patch(
                "/lists/{$listId}/members/{$this->subscriberHash($email)}?skip_merge_validation=true",
                ['status' => 'subscribed'],
            );

            return null;
        } catch (MailchimpApiException $e) {
            // Reported verbatim rather than replaced with wording of our own: when
            // an admin asks why a contact could not be recovered, the answer should
            // be the one Mailchimp gave.
            if ($e->statusCode === 400 && $this->isComplianceRefusal((string) $e->detail)) {
                return $e->detail ?: 'Mailchimp refused this contact on compliance grounds.';
            }

            throw $e;
        }
    }

    /**
     * Mailchimp phrases this refusal several ways depending on the endpoint — the
     * title "Forgotten Email Not Subscribed", a detail line reading "... is in a
     * compliance state due to unsubscribe, bounce, or compliance review", and for a
     * contact that was erased, "... was permanently deleted and cannot be
     * re-imported". Only the detail reaches here, and for the erased case it is the
     * last of those, which the first two patterns do not match — so that contact
     * used to raise and take the whole run down with it. The case is not stable
     * between them either, so the match is deliberately loose: every one of these is
     * a refusal about one contact, never a reason to abandon the rest.
     */
    protected function isComplianceRefusal(string $detail): bool
    {
        $detail = strtolower($detail);

        return str_contains($detail, 'compliance state')
            || str_contains($detail, 'forgotten email')
            || str_contains($detail, 'permanently deleted');
    }

    /**
     * Tags are not settable through the member PATCH, so a resubscribed contact
     * needs this second call to carry the import's tag.
     *
     * @throws MailchimpApiException
     */
    public function tagMember(string $listId, string $email, string $tag): void
    {
        $this->post("/lists/{$listId}/members/{$this->subscriberHash($email)}/tags", [
            'tags' => [['name' => $tag, 'status' => 'active']],
        ]);
    }

    /**
     * Mailchimp addresses a member by the MD5 of their lowercased address.
     */
    public function subscriberHash(string $email): string
    {
        return md5(strtolower(trim($email)));
    }

    /**
     * Status of specific addresses, asked for one by one and in parallel.
     *
     * Cheaper than memberStatusIndex() whenever the file is small: checking forty
     * addresses against a fifty-thousand-member audience costs forty small requests
     * here against fifty full pages there. The caller picks; see
     * MailchimpDryRun::TARGETED_LOOKUP_LIMIT.
     *
     * @param  array<int, string>  $emails  lowercase
     * @return array<string, string> lowercase email => status, absent when not a member
     *
     * @throws MailchimpApiException
     */
    public function memberStatusesFor(string $listId, array $emails): array
    {
        $index = [];

        foreach (array_chunk(array_values($emails), self::LOOKUP_CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => collect($chunk)
                ->map(fn (string $email) => $this->credentials
                    ->authorize($pool->as($email)->acceptJson()->timeout(30))
                    ->get(
                        $this->url("/lists/{$listId}/members/".$this->subscriberHash($email)),
                        ['fields' => 'status']
                    ))
                ->all());

            foreach ($chunk as $email) {
                $response = $responses[$email] ?? null;

                // A pooled request can come back as the exception itself rather than
                // a response; either way this address is simply unknown to us.
                if (! $response instanceof Response) {
                    continue;
                }

                // The pool bypasses send(), so a rejected credential has to be
                // recognised here too — otherwise every address in the file would
                // silently look like a new contact.
                if ($response->status() === 401) {
                    $this->rejectCredential();
                }

                // 404 is the ordinary answer for "not in this audience".
                if ($response->successful() && filled($response->json('status'))) {
                    $index[$email] = $response->json('status');
                }
            }
        }

        return $index;
    }

    /**
     * Several GETs at once, up to the connection limit Mailchimp allows.
     *
     * Strict where memberStatusesFor() is forgiving: a caller paging an audience has
     * no way to tell a page that failed from a page that was empty, and a silently
     * short index would classify real members as new contacts. Any failure raises.
     *
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $requests  key => [path, query]
     * @return array<string, Response>
     *
     * @throws MailchimpApiException
     */
    protected function poolGet(array $requests): array
    {
        $responses = Http::pool(fn (Pool $pool) => collect($requests)
            ->map(fn (array $request, string $key) => $this->credentials
                ->authorize($pool->as($key)->acceptJson()->timeout(60))
                ->get($this->url($request[0]), $request[1]))
            ->all());

        foreach (array_keys($requests) as $key) {
            $response = $responses[$key] ?? null;

            // A pooled request can come back as the exception itself rather than a
            // response — a timeout or a dropped connection.
            if (! $response instanceof Response) {
                throw new MailchimpApiException(
                    'Mailchimp did not answer while reading the audience. The import can be run again.',
                    null,
                    $response instanceof \Throwable ? $response->getMessage() : null,
                );
            }

            // The pool bypasses send(), so a rejected credential has to be recognised
            // here too.
            if ($response->status() === 401) {
                $this->rejectCredential();
            }

            if ($response->failed()) {
                throw MailchimpApiException::fromResponse($response);
            }
        }

        return $responses;
    }

    /**
     * Mark the credential unusable and say so. A 401 is never worth retrying: the
     * token or key itself was refused.
     *
     * @throws MailchimpApiException
     */
    protected function rejectCredential(): never
    {
        $this->credentials->markRejected();

        MailchimpAuditLog::record(MailchimpAuditLog::NEEDS_RECONNECT, [
            'account' => $this->credentials->account,
            'credential_source' => $this->credentials->source,
        ]);

        throw new MailchimpApiException(
            $this->credentials->rejectionMessage(),
            401,
            'credential rejected',
            // Only an OAuth connection can be repaired from the settings page.
            $this->credentials->isOAuth(),
        );
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->get($this->url($path), $query));
    }

    protected function post(string $path, array $payload): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->post($this->url($path), $payload));
    }

    protected function patch(string $path, array $payload): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->patch($this->url($path), $payload));
    }

    protected function url(string $path): string
    {
        return $this->credentials->baseUrl().$path;
    }

    protected function request(): PendingRequest
    {
        // Bearer for an OAuth token, basic auth for an API key. The OAuth metadata
        // endpoint is the odd one out and wants "OAuth <token>", which is why it
        // lives in MailchimpOAuth rather than here.
        return $this->credentials->authorize(
            Http::acceptJson()->timeout(30)
        );
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
            // A bad API key needs an environment change rather than a reconnect, so
            // the UI must not send the admin to a button that cannot help;
            // rejectCredential() carries that distinction.
            $this->rejectCredential();
        }

        if ($response->failed()) {
            throw MailchimpApiException::fromResponse($response);
        }

        return $response;
    }
}
