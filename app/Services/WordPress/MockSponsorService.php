<?php

namespace App\Services\WordPress;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A stand-in for a film site, for working on the dashboard without touching one.
 *
 * It is reached only when SPONSOR_DRIVER=mock is set explicitly. Nothing ever
 * falls back to this: a live request that fails raises, because a dashboard
 * that quietly showed invented partners would be worse than one that errors.
 *
 * The seed mirrors the real WAFT structure — groups of partners tagged to a
 * region and season — so anything built against the mock meets no surprises
 * against the live site.
 *
 * State lives in the cache under its own prefix with a short TTL. Logos go to a
 * throwaway local disk directory, not the WordPress media library.
 */
class MockSponsorService implements SponsorRepository
{
    private const KEY = 'sponsor_mock:groups';

    private const TTL_MINUTES = 240;

    /** Under public/storage/, beside the event banners. */
    private const LOGO_DIR = 'mock-sponsor-logos';

    private const PAGES_KEY = 'sponsor_mock:pages';

    /** Mirrors the taxonomy terms the real WAFT site returns. */
    private const REGIONS = [
        ['id' => 2, 'name' => 'Australia', 'slug' => 'au'],
        ['id' => 10, 'name' => 'New Zealand', 'slug' => 'new-zealand'],
        ['id' => 7, 'name' => 'North America', 'slug' => 'us'],
        ['id' => 13, 'name' => 'United Kingdom', 'slug' => 'uk'],
    ];

    private const SEASONS = [
        ['id' => 84, 'name' => '2026', 'slug' => '2026'],
        ['id' => 35, 'name' => '2025', 'slug' => '2025'],
    ];

    public function driver(): string
    {
        return 'mock';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function assertWritable(): void
    {
        // Always writable: it is a cache store on this machine.
    }

    public function groups(array $filters = []): array
    {
        $groups = collect($this->store());

        if (filled($filters['region'] ?? null)) {
            $groups = $groups->filter(fn ($g) => in_array((int) $filters['region'], $g['regions'], true));
        }

        if (filled($filters['season'] ?? null)) {
            $groups = $groups->filter(fn ($g) => in_array((int) $filters['season'], $g['seasons'], true));
        }

        if (filled($filters['search'] ?? null)) {
            $needle = mb_strtolower($filters['search']);
            $groups = $groups->filter(fn ($g) => str_contains(mb_strtolower($g['title'].' '.$g['heading']), $needle));
        }

        return $groups->sortBy('title')->values()->all();
    }

    public function group(int $id): ?array
    {
        return collect($this->store())->firstWhere('id', $id);
    }

    public function createGroup(array $data): array
    {
        $store = $this->store();

        $group = [
            'id' => (int) (collect($store)->max('id') ?? 2000) + 1,
            'title' => (string) ($data['title'] ?? ''),
            'heading' => (string) ($data['heading'] ?? $data['title'] ?? ''),
            'status' => $data['status'] ?? 'publish',
            'regions' => array_values(array_map('intval', $data['regions'] ?? [])),
            'seasons' => array_values(array_map('intval', $data['seasons'] ?? [])),
            'link' => null,
            'partners' => [],
        ];

        $store[] = $group;
        $this->put($store);

        return $group;
    }

    public function updateGroup(int $id, array $data): array
    {
        return $this->mutate($id, function (array $group) use ($data) {
            foreach (['title', 'heading', 'status'] as $k) {
                if (array_key_exists($k, $data)) {
                    $group[$k] = $data[$k];
                }
            }

            foreach (['regions', 'seasons'] as $k) {
                if (isset($data[$k])) {
                    $group[$k] = array_values(array_map('intval', $data[$k]));
                }
            }

            return $group;
        });
    }

    public function deleteGroup(int $id): void
    {
        $this->put(array_values(array_filter($this->store(), fn ($g) => $g['id'] !== $id)));
    }

    public function addPartner(int $groupId, array $partner): array
    {
        return $this->mutate($groupId, function (array $group) use ($partner) {
            $group['partners'][] = $this->row($partner);

            return $this->reindex($group);
        });
    }

    public function updatePartner(int $groupId, int $index, array $partner): array
    {
        return $this->mutate($groupId, function (array $group) use ($index, $partner, $groupId) {
            if (! array_key_exists($index, $group['partners'])) {
                throw new RuntimeException("Partner {$index} is no longer in group {$groupId}.");
            }

            $group['partners'][$index] = $this->row($partner, $group['partners'][$index]);

            return $this->reindex($group);
        });
    }

    public function removePartner(int $groupId, int $index): void
    {
        $this->mutate($groupId, function (array $group) use ($index, $groupId) {
            if (! array_key_exists($index, $group['partners'])) {
                throw new RuntimeException("Partner {$index} is no longer in group {$groupId}.");
            }

            unset($group['partners'][$index]);
            $group['partners'] = array_values($group['partners']);

            return $this->reindex($group);
        });
    }

    public function reorderPartners(int $groupId, array $indices): array
    {
        return $this->mutate($groupId, function (array $group) use ($indices) {
            $rows = array_values($group['partners']);

            $expected = range(0, count($rows) - 1);
            $given = $indices;
            sort($given);

            if (count($rows) === 0 || $given !== $expected) {
                throw new RuntimeException('This group has changed since the page was loaded — reload and try again.');
            }

            $group['partners'] = array_map(fn (int $i) => $rows[$i], $indices);

            return $this->reindex($group);
        });
    }

    public function reorderPage(int $pageId, array $groupIds): void
    {
        $pages = $this->pages();

        foreach ($pages as $i => $page) {
            if ($page['id'] !== $pageId) {
                continue;
            }

            $a = $page['group_ids'];
            $b = $groupIds;
            sort($a);
            sort($b);

            if ($a !== $b) {
                throw new RuntimeException('This page has changed since it was loaded — reload and try again.');
            }

            $pages[$i]['group_ids'] = array_values($groupIds);
            Cache::put(self::PAGES_KEY, $pages, now()->addMinutes(self::TTL_MINUTES));

            return;
        }

        throw new RuntimeException("No mock partners page {$pageId}.");
    }

    public function pages(): array
    {
        return Cache::get(self::PAGES_KEY) ?? $this->seedPages();
    }

    public function placeGroup(int $pageId, int $groupId): void
    {
        $this->setGroupOnPage($pageId, $groupId, true);
    }

    public function unplaceGroup(int $pageId, int $groupId): void
    {
        $this->setGroupOnPage($pageId, $groupId, false);
    }

    private function setGroupOnPage(int $pageId, int $groupId, bool $present): void
    {
        $pages = $this->pages();

        foreach ($pages as $i => $page) {
            if ($page['id'] !== $pageId) {
                continue;
            }

            $ids = array_values(array_filter($page['group_ids'], fn ($id) => $id !== $groupId));
            if ($present) {
                $ids[] = $groupId;
            }

            $pages[$i]['group_ids'] = $ids;
            Cache::put(self::PAGES_KEY, $pages, now()->addMinutes(self::TTL_MINUTES));

            return;
        }

        throw new RuntimeException("No mock partners page {$pageId}.");
    }

    /** @return array<int, array<string, mixed>> */
    private function seedPages(): array
    {
        $pages = [
            ['id' => 383, 'title' => 'AU Partners', 'link' => null, 'group_ids' => [2001, 2002]],
            ['id' => 384, 'title' => 'NZ Partners', 'link' => null, 'group_ids' => [2003]],
        ];

        Cache::put(self::PAGES_KEY, $pages, now()->addMinutes(self::TTL_MINUTES));

        return $pages;
    }

    public function uploadLogo(UploadedFile $file): array
    {
        // A real upload would go to the WordPress media library. Here it goes to
        // a local throwaway directory so the dashboard can show the image back.
        //
        // Written into public/ directly, the same way event banners are: this
        // app has no storage:link — public/storage is a real directory — so
        // anything put on the 'public' disk is saved but never served.
        $destination = public_path('storage/'.self::LOGO_DIR);
        if (! file_exists($destination)) {
            mkdir($destination, 0755, true);
        }

        $filename = time().'_'.Str::random(8).'.'.$file->getClientOriginalExtension();
        $file->move($destination, $filename);

        return ['id' => random_int(9000, 9999), 'url' => url('storage/'.self::LOGO_DIR.'/'.$filename)];
    }

    public function taxonomies(): array
    {
        return ['region' => self::REGIONS, 'season' => self::SEASONS];
    }

    // -- store ---------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function store(): array
    {
        return Cache::get(self::KEY) ?? $this->seed();
    }

    /** @param array<int, array<string, mixed>> $store */
    private function put(array $store): void
    {
        Cache::put(self::KEY, array_values($store), now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function mutate(int $id, callable $change): array
    {
        $store = $this->store();

        foreach ($store as $i => $group) {
            if ($group['id'] === $id) {
                $store[$i] = $change($group);
                $this->put($store);

                return $store[$i];
            }
        }

        throw new RuntimeException("No mock partner group {$id}.");
    }

    /**
     * @param  array<string, mixed>  $partner
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function row(array $partner, ?array $existing = null): array
    {
        return [
            'index' => 0, // set by reindex()
            'logo_id' => (int) ($partner['logo_id'] ?? $existing['logo_id'] ?? 0),
            'logo_url' => $partner['logo_url'] ?? $existing['logo_url'] ?? null,
            'website_url' => (string) ($partner['website_url'] ?? $existing['website_url'] ?? ''),
            'text' => (string) ($partner['text'] ?? $existing['text'] ?? ''),
            'max_height' => (int) ($partner['max_height'] ?? $existing['max_height'] ?? 100),
            'line_break' => (string) ($partner['line_break'] ?? $existing['line_break'] ?? 'none'),
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function reindex(array $group): array
    {
        foreach (array_values($group['partners']) as $i => $row) {
            $row['index'] = $i;
            $group['partners'][$i] = $row;
        }

        return $group;
    }

    /**
     * Shaped on the groups really present on the WAFT site, so the dashboard is
     * exercised against something representative.
     *
     * @return array<int, array<string, mixed>>
     */
    private function seed(): array
    {
        $group = fn (int $id, string $title, string $heading, array $regions, array $seasons, array $partners, string $status = 'publish') => [
            'id' => $id,
            'title' => $title,
            'heading' => $heading,
            'status' => $status,
            'regions' => $regions,
            'seasons' => $seasons,
            'link' => null,
            'partners' => array_values(array_map(fn ($p, $i) => [
                'index' => $i,
                'logo_id' => $p['logo_id'] ?? 0,
                'logo_url' => $p['logo_url'] ?? null,
                'website_url' => $p['website_url'] ?? '',
                'text' => $p['text'] ?? '',
                'max_height' => $p['max_height'] ?? 100,
                'line_break' => 'none',
            ], $partners, array_keys($partners))),
        ];

        $store = [
            $group(2001, 'WAFT AU – PRESENTING PARTNER 2026', 'PRESENTING PARTNER', [2], [84], [
                ['logo_id' => 9101, 'website_url' => 'https://www.jeanhailes.org.au', 'text' => 'Jean Hailes'],
            ]),
            $group(2002, 'National Tour Partners – AU – 2026', 'NATIONAL TOUR PARTNERS', [2], [84], [
                ['logo_id' => 9102, 'website_url' => 'https://example.com/combi', 'text' => 'Combi'],
                ['logo_id' => 9103, 'website_url' => 'https://www.spelean.com.au', 'text' => 'Petzl'],
            ]),
            $group(2003, 'WAFT NZ – SUPPORTING PARTNERS 2026', 'SUPPORTING PARTNERS', [10], [84], [
                ['logo_id' => 9104, 'website_url' => 'https://example.com/nz-supporter'],
            ]),
            $group(2004, 'Non-Profit Partner – AU – 2025', 'NON-PROFIT PARTNER', [2], [35], [
                ['logo_id' => 9105, 'website_url' => 'https://www.australianhimalayanfoundation.org.au'],
            ], 'draft'),
        ];

        $this->put($store);

        return $store;
    }
}
