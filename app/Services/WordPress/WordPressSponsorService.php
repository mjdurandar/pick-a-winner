<?php

namespace App\Services\WordPress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The live film site, over the WordPress REST API.
 *
 * Partners live in `partner-group` posts. One post is a whole tier block — a
 * heading plus an ACF repeater of partner rows — not a single partner, so
 * adding a partner is a read-modify-write of one group's repeater rather than
 * creating a record. See SponsorRepository for the shapes.
 *
 * The post type is not exposed to REST by the adv-film theme; the Win App
 * Sponsors plugin (integrations/wordpress/win-app-sponsors) publishes it and
 * its `partner_group` field. Without that plugin every call here 404s, which is
 * what assertWritable() reports before anything is uploaded.
 */
class WordPressSponsorService implements SponsorRepository
{
    /** The partner records on the adv-film theme. */
    public const POST_TYPE = 'partner-group';

    /** The theme's group field: { title, partners[] }. */
    private const FIELD = 'partner_group';

    /** The pages that display groups. One per region on the film sites. */
    private const PAGE_TYPE = 'partner-page';

    /** The group id list inside a page's partners block. Group 1 is the key, group 2 the ids. */
    private const GROUP_LIST_PATTERN = '/("regions_\d+_partner_groups":)\[([^\]]*)\]/';

    /** Seconds. Saving a page runs the theme's own hooks and is far slower than saving a record. */
    private const PAGE_WRITE_TIMEOUT = 90;

    /** Defaults the theme uses for a row, matched to what real rows carry. */
    private const ROW_DEFAULTS = ['text' => '', 'max_height' => 100, 'line_break' => 'none'];

    public function __construct(
        protected string $siteUrl,
        protected string $user,
        protected string $appPassword,
    ) {}

    public function driver(): string
    {
        return 'wordpress';
    }

    public function isLive(): bool
    {
        return true;
    }

    public function assertWritable(): void
    {
        $response = $this->request()->get($this->endpoint('/wp/v2/'.self::POST_TYPE), ['per_page' => 1]);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json(), $response->status(), '/wp/v2/'.self::POST_TYPE));
        }
    }

    public function groups(array $filters = []): array
    {
        $query = array_filter([
            'per_page' => 100,
            'status' => 'publish,draft',
            'search' => $filters['search'] ?? null,
            'region' => $filters['region'] ?? null,
            'season' => $filters['season'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return array_map($this->present(...), $this->get('/wp/v2/'.self::POST_TYPE, $query));
    }

    public function group(int $id): ?array
    {
        $response = $this->request()->get($this->endpoint('/wp/v2/'.self::POST_TYPE.'/'.$id));

        return $response->ok() ? $this->present($response->json()) : null;
    }

    public function createGroup(array $data): array
    {
        return $this->present($this->send('post', '/wp/v2/'.self::POST_TYPE, [
            'title' => $data['title'] ?? '',
            'status' => $data['status'] ?? 'publish',
            'region' => array_values(array_map('intval', $data['regions'] ?? [])),
            'season' => array_values(array_map('intval', $data['seasons'] ?? [])),
            // No 'partners' key: sending an empty repeater makes ACF store one
            // blank row, which then shows up as a partner with no logo and
            // shifts every index after it.
            'acf' => [self::FIELD => [
                'title' => $data['heading'] ?? ($data['title'] ?? ''),
            ]],
        ]));
    }

    public function updateGroup(int $id, array $data): array
    {
        $current = $this->group($id) ?? throw new RuntimeException("No partner group {$id} on this site.");

        $body = array_filter([
            'title' => $data['title'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        foreach (['regions' => 'region', 'seasons' => 'season'] as $from => $to) {
            if (isset($data[$from])) {
                $body[$to] = array_values(array_map('intval', $data[$from]));
            }
        }

        // The heading lives inside the group field, alongside the rows — so it
        // can only be written by sending the rows back with it untouched.
        if (array_key_exists('heading', $data)) {
            $body['acf'] = [self::FIELD => [
                'title' => (string) $data['heading'],
                'partners' => $this->writable($current['partners']),
            ]];
        }

        return $this->present($this->send('post', '/wp/v2/'.self::POST_TYPE.'/'.$id, $body));
    }

    public function deleteGroup(int $id): void
    {
        // force=false sends it to the WordPress trash rather than erasing it, so
        // a mistaken delete can be undone from wp-admin.
        $this->send('delete', '/wp/v2/'.self::POST_TYPE.'/'.$id, ['force' => false]);
    }

    public function addPartner(int $groupId, array $partner): array
    {
        $group = $this->group($groupId) ?? throw new RuntimeException("No partner group {$groupId} on this site.");

        $rows = $this->writable($group['partners']);
        $rows[] = $this->row($partner);

        return $this->putRows($groupId, $group['heading'], $rows);
    }

    public function updatePartner(int $groupId, int $index, array $partner): array
    {
        $group = $this->group($groupId) ?? throw new RuntimeException("No partner group {$groupId} on this site.");

        $rows = $this->writable($group['partners']);
        $this->assertRow($rows, $index, $groupId);

        // Merged, not replaced: a save that changes only the website must not
        // blank the logo just because no new file was picked.
        $rows[$index] = $this->row($partner, $rows[$index]);

        return $this->putRows($groupId, $group['heading'], $rows);
    }

    public function removePartner(int $groupId, int $index): void
    {
        $group = $this->group($groupId) ?? throw new RuntimeException("No partner group {$groupId} on this site.");

        $rows = $this->writable($group['partners']);
        $this->assertRow($rows, $index, $groupId);

        unset($rows[$index]);

        $this->putRows($groupId, $group['heading'], array_values($rows));
    }

    public function reorderPartners(int $groupId, array $indices): array
    {
        $group = $this->group($groupId) ?? throw new RuntimeException("No partner group {$groupId} on this site.");

        $rows = $this->writable($group['partners']);

        // The order must name every row exactly once. Anything else means the
        // page was looking at a group someone has since changed, and applying
        // it would drop or duplicate a partner.
        $expected = range(0, count($rows) - 1);
        $given = $indices;
        sort($given);

        if (count($rows) === 0 || $given !== $expected) {
            throw new RuntimeException('This group has changed since the page was loaded — reload and try again.');
        }

        return $this->putRows($groupId, $group['heading'], array_map(fn (int $i) => $rows[$i], $indices));
    }

    public function reorderPage(int $pageId, array $groupIds): void
    {
        $this->rewriteGroupList(
            $pageId,
            function (array $ids) use ($groupIds) {
                $a = $ids;
                $b = $groupIds;
                sort($a);
                sort($b);

                if ($a !== $b) {
                    throw new RuntimeException('This page has changed since it was loaded — reload and try again.');
                }

                return array_values($groupIds);
            },
            fn (array $ids) => $ids === array_values($groupIds),
        );
    }

    public function pages(): array
    {
        $pages = $this->get('/wp/v2/'.self::PAGE_TYPE, ['per_page' => 50, 'context' => 'edit']);

        return array_map(fn (array $p) => [
            'id' => (int) $p['id'],
            'title' => html_entity_decode($p['title']['rendered'] ?? $p['title']['raw'] ?? ''),
            'link' => $p['link'] ?? null,
            'group_ids' => $this->groupIdsIn((string) ($p['content']['raw'] ?? '')),
        ], $pages);
    }

    public function placeGroup(int $pageId, int $groupId): void
    {
        $this->setGroupOnPage($pageId, $groupId, true);
    }

    public function unplaceGroup(int $pageId, int $groupId): void
    {
        $this->setGroupOnPage($pageId, $groupId, false);
    }

    public function uploadLogo(UploadedFile $file): array
    {
        $response = $this->request()
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$file->getClientOriginalName().'"',
                'Content-Type' => $file->getMimeType(),
            ])
            ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
            ->post($this->endpoint('/wp/v2/media'));

        if ($response->failed()) {
            throw new RuntimeException('WordPress refused the logo upload: '.$this->reason($response->json(), $response->status()));
        }

        return ['id' => (int) $response->json('id'), 'url' => (string) $response->json('source_url')];
    }

    public function taxonomies(): array
    {
        $terms = fn (string $tax) => array_map(
            fn (array $t) => ['id' => (int) $t['id'], 'name' => html_entity_decode($t['name']), 'slug' => $t['slug']],
            $this->get('/wp/v2/'.$tax, ['per_page' => 100, '_fields' => 'id,name,slug'])
        );

        return ['region' => $terms('region'), 'season' => $terms('season')];
    }

    // -- rows ----------------------------------------------------------------

    /**
     * Write a whole repeater back.
     *
     * ACF has no append: the field is set as one array, so every change is a
     * read, a change in memory, and a write of the result. Two people editing
     * the same group at the same moment would have the later save win, which is
     * acceptable for a handful of admins and worth knowing about.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function putRows(int $groupId, string $heading, array $rows): array
    {
        return $this->present($this->send('post', '/wp/v2/'.self::POST_TYPE.'/'.$groupId, [
            'acf' => [self::FIELD => ['title' => $heading, 'partners' => array_values($rows)]],
        ]));
    }

    /**
     * One row in the shape ACF accepts.
     *
     * @param  array<string, mixed>  $partner
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function row(array $partner, ?array $existing = null): array
    {
        $existing ??= self::ROW_DEFAULTS + ['image' => 0, 'url' => ''];

        return [
            'image' => (int) ($partner['logo_id'] ?? $existing['image'] ?? 0),
            'url' => (string) ($partner['website_url'] ?? $existing['url'] ?? ''),
            'text' => (string) ($partner['text'] ?? $existing['text'] ?? self::ROW_DEFAULTS['text']),
            'max_height' => (int) ($partner['max_height'] ?? $existing['max_height'] ?? self::ROW_DEFAULTS['max_height']),
            'line_break' => (string) ($partner['line_break'] ?? $existing['line_break'] ?? self::ROW_DEFAULTS['line_break']),
        ];
    }

    /**
     * Turn presented rows back into what ACF accepts.
     *
     * Reading gives `image` as a whole attachment object; writing wants the
     * attachment id. Round-tripping without this silently empties every logo in
     * the group.
     *
     * @param  array<int, array<string, mixed>>  $partners
     * @return array<int, array<string, mixed>>
     */
    private function writable(array $partners): array
    {
        return array_values(array_map(fn (array $p) => [
            'image' => (int) ($p['logo_id'] ?? 0),
            'url' => (string) ($p['website_url'] ?? ''),
            'text' => (string) ($p['text'] ?? ''),
            'max_height' => (int) ($p['max_height'] ?? self::ROW_DEFAULTS['max_height']),
            'line_break' => (string) ($p['line_break'] ?? self::ROW_DEFAULTS['line_break']),
        ], $partners));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function assertRow(array $rows, int $index, int $groupId): void
    {
        if (! array_key_exists($index, $rows)) {
            throw new RuntimeException("Partner {$index} is no longer in group {$groupId} — it may have been removed since this page was loaded.");
        }
    }

    // -- placing a group on a page -------------------------------------------

    /**
     * The group ids a partners page lists, read out of its block content.
     *
     * The page is built from Gutenberg blocks, and the partners block stores
     * its selection as an ACF list inside the block's own comment — there is no
     * field on the page to read it from. Matching the one key is deliberately
     * narrow: everything else in that content is left alone.
     *
     * @return array<int, int>
     */
    private function groupIdsIn(string $raw): array
    {
        preg_match_all(self::GROUP_LIST_PATTERN, $raw, $matches);

        $ids = [];
        foreach ($matches[2] ?? [] as $list) {
            foreach (explode(',', $list) as $id) {
                $id = trim($id, "\" \t\n\r");
                if ($id !== '') {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Add or remove one group in a page's partners block.
     *
     * This edits a page's layout, so it is written defensively: the change is
     * confined to the bracketed id list by the pattern, the result is read back
     * and checked, and a page whose block is not in the expected shape is
     * refused rather than guessed at. Only the first region list is touched —
     * the film sites use one per page.
     */
    /** Add or remove one group in a page's list. */
    private function setGroupOnPage(int $pageId, int $groupId, bool $present): void
    {
        $this->rewriteGroupList(
            $pageId,
            function (array $ids) use ($groupId, $present) {
                $ids = array_values(array_filter($ids, fn ($id) => $id !== $groupId));

                if ($present) {
                    $ids[] = $groupId;
                }

                return $ids;
            },
            fn (array $ids) => in_array($groupId, $ids, true) === $present,
        );
    }

    /**
     * Rewrite the group id list in a page's partners block.
     *
     * This edits a page's layout, so it is written defensively: the change is
     * confined to the bracketed id list by the pattern, the result is read back
     * and checked by the caller's own test, and a page whose block is not in
     * the expected shape is refused rather than guessed at. Only the first
     * region list is touched — the film sites use one per page.
     *
     * @param  callable(array<int, int>): array<int, int>  $transform
     * @param  callable(array<int, int>): bool  $verify
     */
    private function rewriteGroupList(int $pageId, callable $transform, callable $verify): void
    {
        $response = $this->request()->get($this->endpoint('/wp/v2/'.self::PAGE_TYPE.'/'.$pageId), ['context' => 'edit']);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json(), $response->status(), self::PAGE_TYPE));
        }

        $original = (string) $response->json('content.raw');

        if (! preg_match(self::GROUP_LIST_PATTERN, $original)) {
            throw new RuntimeException(
                'That page has no partners block this app can edit. Change it in WordPress instead, '
                .'then it will show here.'
            );
        }

        $updated = preg_replace_callback(self::GROUP_LIST_PATTERN, function (array $m) use ($transform) {
            $ids = array_values(array_filter(
                array_map(fn ($id) => (int) trim($id, "\" \t\n\r"), explode(',', $m[2])),
                fn ($id) => $id > 0,
            ));

            return $m[1].'['.implode(',', array_map(fn ($id) => '"'.$id.'"', $transform($ids))).']';
        }, $original, 1);

        if ($updated === null || $updated === $original) {
            return; // Nothing to do.
        }

        // Saving a page is much slower than saving a partner record, and WP
        // Engine returns a 504 well before it gives up — often having applied
        // the change anyway. So the response is not trusted either way: what
        // counts is what the page says afterwards.
        $failure = null;

        try {
            $write = $this->request(self::PAGE_WRITE_TIMEOUT)
                ->post($this->endpoint('/wp/v2/'.self::PAGE_TYPE.'/'.$pageId), ['content' => $updated]);

            if ($write->failed()) {
                $failure = $this->reason($write->json(), $write->status(), self::PAGE_TYPE);
            }
        } catch (ConnectionException $e) {
            $failure = 'The connection to WordPress timed out: '.$e->getMessage();
        }

        $after = $this->request(self::PAGE_WRITE_TIMEOUT)
            ->get($this->endpoint('/wp/v2/'.self::PAGE_TYPE.'/'.$pageId), ['context' => 'edit']);

        if ($verify($this->groupIdsIn((string) $after->json('content.raw')))) {
            return; // However the write reported, the page is as asked.
        }

        if ($failure !== null) {
            // Nothing was applied, so there is nothing to undo.
            throw new RuntimeException($failure.' The page was left unchanged — try again.');
        }

        $this->request(self::PAGE_WRITE_TIMEOUT)
            ->post($this->endpoint('/wp/v2/'.self::PAGE_TYPE.'/'.$pageId), ['content' => $original]);

        throw new RuntimeException('WordPress did not keep that change to the page, so it has been put back as it was.');
    }

    // -- plumbing ------------------------------------------------------------

    /**
     * The WordPress post shape, flattened to what the dashboard needs.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    protected function present(array $post): array
    {
        $group = $post['acf'][self::FIELD] ?? [];

        $partners = [];
        foreach ((array) ($group['partners'] ?? []) as $row) {
            $image = $row['image'] ?? null;
            $logoId = is_array($image) ? (int) ($image['ID'] ?? $image['id'] ?? 0) : (int) ($image ?: 0);
            $url = (string) ($row['url'] ?? '');
            $text = (string) ($row['text'] ?? '');

            // ACF pads a repeater with an empty row in places (a saved group
            // with no partners, a row deleted in wp-admin). It is not a
            // partner, and counting it would shift every index after it. Any
            // write from here stores the filtered list, so it clears itself.
            if ($logoId === 0 && $url === '' && $text === '') {
                continue;
            }

            $partners[] = [
                'index' => count($partners),
                'logo_id' => $logoId,
                'logo_url' => is_array($image) ? ($image['url'] ?? null) : null,
                'website_url' => $url,
                'text' => $text,
                'max_height' => (int) ($row['max_height'] ?? self::ROW_DEFAULTS['max_height']),
                'line_break' => (string) ($row['line_break'] ?? self::ROW_DEFAULTS['line_break']),
            ];
        }

        return [
            'id' => (int) ($post['id'] ?? 0),
            'title' => html_entity_decode($post['title']['rendered'] ?? $post['title']['raw'] ?? ''),
            'heading' => (string) ($group['title'] ?? ''),
            'status' => $post['status'] ?? 'publish',
            'regions' => array_map('intval', $post['region'] ?? []),
            'seasons' => array_map('intval', $post['season'] ?? []),
            'link' => $post['link'] ?? null,
            'partners' => $partners,
        ];
    }

    protected function endpoint(string $path): string
    {
        return rtrim($this->siteUrl, '/').'/wp-json'.$path;
    }

    protected function request(int $timeout = 30): PendingRequest
    {
        return Http::withBasicAuth($this->user, $this->appPassword)
            ->acceptJson()
            ->timeout($timeout);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($this->endpoint($path), $query);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json(), $response->status(), $path));
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $body): array
    {
        $response = $this->request()->{$method}($this->endpoint($path), $body);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json(), $response->status(), $path));
        }

        return $response->json() ?? [];
    }

    /** WordPress's own words where it gives them — far more useful than a status code. */
    protected function reason(?array $body, int $status, string $path = ''): string
    {
        if ($status === 404 && str_contains($path, self::POST_TYPE)) {
            return "WordPress has no '".self::POST_TYPE."' endpoint (404). The post type is not published to the REST API yet — install the Win App Sponsors plugin (integrations/wordpress/win-app-sponsors).";
        }

        return 'WordPress returned '.$status.': '.($body['message'] ?? $body['code'] ?? 'no detail given');
    }
}
