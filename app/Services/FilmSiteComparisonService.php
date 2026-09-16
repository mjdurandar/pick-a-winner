<?php

namespace App\Services;

use App\Exceptions\FilmSiteException;
use App\Models\FilmSiteCheck;
use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Compares the shows a film site publishes with an event's locations.
 *
 * READ-ONLY against both sides: every call to the site is a GET on its public
 * WordPress REST API, and locations are only read. The result is a list of
 * compared screenings, each with a severity, for FilmSiteCheckRun to store.
 *
 * Grew out of scripts/check-film-site.php, with three things the spike got
 * wrong fixed:
 *   - adv-film/get-sessions ignores region and season and returns every show
 *     worldwide, so it is narrowed to the session ids wp/v2 lists for the
 *     region+season. Without that a UK show reads as missing from an AU event.
 *   - two shows in the same town were keyed on the place alone and overwrote
 *     each other; they are now paired by date.
 *   - wp/v2 is paginated (100 a page) and only the first page was read.
 */
class FilmSiteComparisonService
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_NOTICE = 'notice';

    public const SEVERITY_OK = 'ok';

    /** Both sides list it, on different days. */
    public const TYPE_DATE_MISMATCH = 'date_mismatch';

    /** A Win App location the site does not list. */
    public const TYPE_NOT_ON_SITE = 'not_on_site';

    /** A show on the site with no Win App location — nobody can sign up to it. */
    public const TYPE_NOT_IN_APP = 'not_in_app';

    /** Both sides list it, but the site publishes no date (or TBC). */
    public const TYPE_NO_SITE_DATE = 'no_site_date';

    public const TYPE_MATCHED = 'matched';

    public const SEVERITIES = [
        self::TYPE_DATE_MISMATCH => self::SEVERITY_ERROR,
        self::TYPE_NOT_ON_SITE => self::SEVERITY_WARNING,
        self::TYPE_NOT_IN_APP => self::SEVERITY_WARNING,
        self::TYPE_NO_SITE_DATE => self::SEVERITY_NOTICE,
        self::TYPE_MATCHED => self::SEVERITY_OK,
    ];

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws FilmSiteException
     */
    public function compare(FilmSiteCheck $check): array
    {
        $site = $this->normaliseSiteUrl($check->site_url);

        [$shows, $meta] = $this->siteShows($site, $check->region, $check->season);

        $ours = Location::where('event_id', $check->event_id)
            ->orderBy('date')
            ->get(['id', 'name', 'date', 'time', 'status', 'state']);

        $items = $this->diff($ours, $shows);

        return [
            'items' => $items,
            'meta' => $meta + [
                'app_locations' => $ours->count(),
                'site_shows' => count($shows),
            ],
        ];
    }

    /**
     * The regions and seasons a site offers, for the add-a-check picker.
     *
     * @return array{site_url: string, regions: array<int, array{slug: string, name: string}>, seasons: array<int, array{slug: string, name: string}>}
     *
     * @throws FilmSiteException
     */
    public function taxonomies(string $siteUrl): array
    {
        $site = $this->normaliseSiteUrl($siteUrl);

        $this->assertReadable($site);

        $terms = fn (string $tax) => collect($this->get($site, "/wp-json/wp/v2/{$tax}", ['per_page' => 100, '_fields' => 'id,name,slug']) ?? [])
            ->map(fn ($t) => ['slug' => (string) $t['slug'], 'name' => html_entity_decode((string) $t['name'], ENT_QUOTES | ENT_HTML5)])
            ->values()
            ->all();

        return [
            'site_url' => $site,
            'regions' => $terms('region'),
            'seasons' => collect($terms('season'))->sortByDesc('slug')->values()->all(),
        ];
    }

    public function normaliseSiteUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        // Only the site root is meaningful; a pasted page URL is cut back to it.
        $parts = parse_url($url);

        if (empty($parts['host'])) {
            throw new FilmSiteException('That does not look like a website address.');
        }

        return strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host']);
    }

    /**
     * The shows the site publishes for a region and season, keyed nowhere yet.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     *
     * @throws FilmSiteException
     */
    protected function siteShows(string $site, ?string $region, ?string $season): array
    {
        $this->assertReadable($site);

        // Several regions are allowed because one Win App event can span what the
        // site files separately — WAFT holds Australia and New Zealand together,
        // the site has 'au' and 'new-zealand'. WordPress ORs comma-separated ids.
        $regionId = $region ? $this->termIds($site, 'region', $region) : null;
        $seasonId = $season ? $this->termIds($site, 'season', $season) : null;

        // Every show in scope — but wp/v2 carries no dates (they are ACF fields
        // the site does not publish to REST).
        $listed = $this->paged($site, '/wp-json/wp/v2/session', array_filter([
            'region' => $regionId,
            'season' => $seasonId,
            '_fields' => 'id,title,link',
        ]));

        // The site's own endpoint carries the date, venue and ticket status, but
        // for every region and season at once — and only for shows still to
        // come. A show that has happened stays in wp/v2 with no date anywhere.
        $dated = $this->get($site, '/wp-json/adv-film/get-sessions')['data'] ?? [];

        if (! $listed && ! $dated) {
            throw new FilmSiteException('The site returned no shows for that region and season.');
        }

        $filtered = $regionId || $seasonId;
        $inScope = collect($listed)->keyBy('id');

        $shows = [];

        foreach ($dated as $d) {
            $id = (int) ($d['ID'] ?? 0);

            if ($filtered && ! $inScope->has($id)) {
                continue;
            }

            $f = $d['fields'] ?? [];

            // Despite the name, date_time_start_utc holds the show's local wall
            // time (a 3:30pm AEST show reads 15:30:00), so its date part is the
            // calendar day the audience sees.
            $start = (string) ($f['date_time_start_utc'] ?? '');
            $tbc = (bool) ($f['tbc'] ?? false);

            $shows[$id] = [
                'id' => $id,
                'title' => $this->decode((string) ($f['location'] ?? $d['post_title'] ?? '')),
                'date' => ! $tbc && $start !== '' ? substr($start, 0, 10) : null,
                'time' => ! $tbc && strlen($start) >= 16 ? substr($start, 11, 5) : null,
                'tbc' => $tbc,
                // get-sessions only carries shows still to come.
                'upcoming' => true,
                'venue' => isset($f['venue']) ? $this->decode((string) $f['venue']) : null,
                'status' => $f['status']['label'] ?? null,
                'link' => $inScope[$id]['link'] ?? null,
                'ticket_link' => $f['action_link'] ?? null,
            ];
        }

        foreach ($listed as $l) {
            $id = (int) $l['id'];

            if (isset($shows[$id])) {
                continue;
            }

            $shows[$id] = [
                'id' => $id,
                'title' => $this->decode((string) ($l['title']['rendered'] ?? '')),
                'date' => null,
                'time' => null,
                'tbc' => false,
                'upcoming' => false,
                'venue' => null,
                'status' => null,
                'link' => $l['link'] ?? null,
                'ticket_link' => null,
            ];
        }

        return [array_values($shows), [
            'site_url' => $site,
            'region_id' => $regionId,
            'season_id' => $seasonId,
            'listed_sessions' => count($listed),
            'dated_sessions' => count($dated),
        ]];
    }

    /**
     * Pair our locations with the site's shows and grade each pairing.
     *
     * Only the place is reliably shared — both sides name a town then a venue,
     * spelled differently — so that is the join. Within one place, shows on the
     * same day pair first; whatever is left pairs in date order.
     *
     * A second pass then catches Win App names written venue-first ("Urban
     * Climb - Newstead"), trying each later half of the name as the place. Only
     * leftovers take part, so it can never steal a pairing the first pass made.
     * Anything still unpaired is on one side only.
     *
     * @param  Collection<int, Location>  $ours
     * @param  array<int, array<string, mixed>>  $theirs
     * @return array<int, array<string, mixed>>
     */
    public function diff(Collection $ours, array $theirs): array
    {
        $app = $ours
            ->filter(fn (Location $l) => self::placeKey((string) $l->name) !== '')
            ->groupBy(fn (Location $l) => self::placeKey((string) $l->name));

        $site = collect($theirs)
            ->filter(fn ($s) => self::placeKey($s['title']) !== '')
            ->groupBy(fn ($s) => self::placeKey($s['title']));

        $items = [];
        $leftApp = [];
        $leftSite = [];

        foreach ($app->keys()->merge($site->keys())->unique()->sort() as $place) {
            [$o, $t] = $this->pair(
                ($app[$place] ?? collect())->all(),
                ($site[$place] ?? collect())->all(),
                $place,
                $items
            );

            array_push($leftApp, ...array_values($o));
            array_push($leftSite, ...array_values($t));
        }

        foreach ($leftApp as $ai => $loc) {
            $alternatives = self::alternativePlaceKeys((string) $loc->name);

            $candidates = array_filter(
                $leftSite,
                fn ($show) => in_array(self::placeKey($show['title']), $alternatives, true)
            );

            if (! $candidates) {
                continue;
            }

            [$o, $t] = $this->pair([$loc], $candidates, self::placeKey(reset($candidates)['title']), $items);

            foreach (array_diff_key($candidates, $t) as $si => $_) {
                unset($leftSite[$si]);
            }

            if (! $o) {
                unset($leftApp[$ai]);
            }
        }

        foreach ($leftApp as $loc) {
            $items[] = $this->item(self::TYPE_NOT_ON_SITE, self::placeKey((string) $loc->name), $loc, null);
        }

        foreach ($leftSite as $show) {
            $items[] = $this->item(self::TYPE_NOT_IN_APP, self::placeKey($show['title']), null, $show);
        }

        return $items;
    }

    /**
     * Pair one place's locations with its shows, appending graded items, and
     * hand back whatever is left on each side (keys preserved).
     *
     * @param  array<int|string, Location>  $o
     * @param  array<int|string, array<string, mixed>>  $t
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int|string, Location>, 1: array<int|string, array<string, mixed>>}
     */
    protected function pair(array $o, array $t, string $place, array &$items): array
    {
        // Dated before undated, so an undated placeholder post is the one left
        // over rather than the real show.
        uasort($o, fn ($a, $b) => [$a->date === null, (string) $a->date] <=> [$b->date === null, (string) $b->date]);
        uasort($t, fn ($a, $b) => [$a['date'] === null, (string) $a['date']] <=> [$b['date'] === null, (string) $b['date']]);

        // Same day first.
        foreach ($o as $oi => $loc) {
            $day = $this->day($loc->date);

            foreach ($t as $ti => $show) {
                if ($day !== null && $show['date'] === $day) {
                    $items[] = $this->item(self::TYPE_MATCHED, $place, $loc, $show);
                    unset($o[$oi], $t[$ti]);
                    break;
                }
            }
        }

        // Then by position.
        $oKeys = array_keys($o);
        $tKeys = array_keys($t);

        for ($i = 0, $pairs = min(count($oKeys), count($tKeys)); $i < $pairs; $i++) {
            $show = $t[$tKeys[$i]];

            $items[] = $this->item(
                $show['date'] === null ? self::TYPE_NO_SITE_DATE : self::TYPE_DATE_MISMATCH,
                $place,
                $o[$oKeys[$i]],
                $show
            );

            unset($o[$oKeys[$i]], $t[$tKeys[$i]]);
        }

        return [$o, $t];
    }

    /**
     * "Sydney North NSW - Roseville" -> "sydney north"; "Halls Gap - 2025" -> "halls gap".
     */
    public static function placeKey(string $name): string
    {
        return self::normalisePlace(self::nameParts($name)[0]);
    }

    /**
     * The later halves of a name, as places: "Urban Climb - Newstead" -> ["newstead"].
     *
     * @return array<int, string>
     */
    public static function alternativePlaceKeys(string $name): array
    {
        return array_values(array_filter(array_map(
            fn ($part) => self::normalisePlace($part),
            array_slice(self::nameParts($name), 1)
        )));
    }

    /**
     * Split on a dash with a space on at least one side, so "Nevada City- Nevada
     * City Elementary" splits but a hyphenated place name does not.
     *
     * @return array<int, string>
     */
    protected static function nameParts(string $name): array
    {
        return preg_split('/\s+[-–—]\s*|\s*[-–—]\s+/u', html_entity_decode($name, ENT_QUOTES | ENT_HTML5));
    }

    protected static function normalisePlace(string $name): string
    {
        $name = preg_replace('/\([^)]*\)/', '', $name);                 // "(2)", "(6pm)"
        $name = preg_replace('/\b(19|20)\d{2}\b/', '', $name);          // "2026"
        $name = preg_replace('/\s\d{1,2}(\/\d{1,2})?\s*$/', '', $name); // trailing "26", "3/27"
        $name = preg_replace('/\b(ACT|NSW|NT|QLD|SA|TAS|VIC|WA)\b/i', '', $name);
        $name = str_replace(['.', ','], ' ', $name);                    // "St. Helens" = "St Helens"

        return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
    }

    /**
     * @param  array<string, mixed>|null  $show
     * @return array<string, mixed>
     */
    protected function item(string $type, string $place, ?Location $loc, ?array $show): array
    {
        $appDay = $loc ? $this->day($loc->date) : null;
        $appPast = $appDay !== null && $appDay < now()->toDateString();

        $message = match ($type) {
            self::TYPE_MATCHED => 'Same day on the site and in the Win App.',
            self::TYPE_DATE_MISMATCH => "The site says {$show['date']}, the Win App says ".($appDay ?? 'no date').'.'
                // A whole year out is almost always the check pointed at last
                // year's event rather than a real scheduling mistake.
                .($appDay && abs(strtotime($show['date']) - strtotime($appDay)) / 86400 > 330
                    ? ' About a year apart — is this check compared with the right year\'s event?'
                    : ''),
            self::TYPE_NO_SITE_DATE => match (true) {
                $show['tbc'] => 'The site lists this show as TBC.',
                $appPast => 'Already happened — the site no longer publishes a date for it.',
                default => 'The site lists this show without a date.',
            },
            self::TYPE_NOT_ON_SITE => $appPast
                ? "In the Win App ({$appDay}, already happened) but not listed on the site."
                : 'In the Win App'.($appDay ? " ({$appDay})" : '').' but not listed on the site.',
            self::TYPE_NOT_IN_APP => match (true) {
                $show['date'] !== null => "Listed on the site ({$show['date']}) but there is no Win App location for it.",
                $show['upcoming'] => 'Listed on the site as TBC but there is no Win App location for it.',
                default => 'Listed on the site with no date (already happened, or never scheduled) and not in the Win App.',
            },
        };

        // Only what someone can still act on is a warning. The site stops
        // publishing a show's date once it has happened, so a past show that is
        // on one side only is history, not a problem — a notice.
        $severity = match ($type) {
            self::TYPE_NOT_IN_APP => $show['upcoming'] ? self::SEVERITY_WARNING : self::SEVERITY_NOTICE,
            self::TYPE_NOT_ON_SITE => $appPast ? self::SEVERITY_NOTICE : self::SEVERITY_WARNING,
            default => self::SEVERITIES[$type],
        };

        return [
            // Stable across runs while the same pairing persists, so a run can
            // say which problems are new and which have been fixed.
            'key' => implode(':', [$type, $place, $loc?->id ?? '-', $show['id'] ?? '-']),
            'type' => $type,
            'severity' => $severity,
            'place' => $place,
            'message' => $message,
            'app' => $loc ? [
                'id' => $loc->id,
                'name' => $loc->name,
                'date' => $appDay,
                'time' => $loc->time,
                'status' => $loc->status,
            ] : null,
            'site' => $show,
        ];
    }

    /** Location.date is a plain Y-m-d string, deliberately uncast. */
    protected function day(mixed $date): ?string
    {
        $day = substr((string) $date, 0, 10);

        return $day === '' ? null : $day;
    }

    protected function decode(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
    }

    /** @throws FilmSiteException */
    protected function assertReadable(string $site): void
    {
        if ($this->get($site, '/wp-json/wp/v2/types') === null) {
            throw new FilmSiteException("Could not read the WordPress API at {$site}/wp-json. Check the address, or whether the site is down.");
        }
    }

    /**
     * "au,new-zealand" -> "2,10".
     *
     * @throws FilmSiteException
     */
    protected function termIds(string $site, string $taxonomy, string $matches): string
    {
        $terms = $this->get($site, "/wp-json/wp/v2/{$taxonomy}", ['per_page' => 100, '_fields' => 'id,name,slug']) ?? [];
        $ids = [];

        foreach (array_filter(array_map('trim', explode(',', $matches))) as $match) {
            $term = collect($terms)->first(
                fn ($t) => strcasecmp((string) $t['slug'], $match) === 0 || strcasecmp((string) $t['name'], $match) === 0
            );

            if (! $term) {
                $available = collect($terms)->pluck('slug')->implode(', ');

                throw new FilmSiteException("The site has no {$taxonomy} called '{$match}'".($available ? " (it has: {$available})." : '.'));
            }

            $ids[] = (int) $term['id'];
        }

        return implode(',', $ids);
    }

    /**
     * Every page of a wp/v2 collection.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function paged(string $site, string $path, array $query): array
    {
        $rows = [];
        $page = 1;

        do {
            $response = $this->request($site, $path, $query + ['per_page' => 100, 'page' => $page]);

            if (! $response?->successful()) {
                break;
            }

            $rows = array_merge($rows, $response->json() ?? []);
            $pages = (int) $response->header('X-WP-TotalPages');
            $page++;
        } while ($page <= $pages && $page <= 20);

        return $rows;
    }

    protected function get(string $site, string $path, array $query = []): mixed
    {
        $response = $this->request($site, $path, $query);

        return $response?->successful() ? $response->json() : null;
    }

    protected function request(string $site, string $path, array $query): ?\Illuminate\Http\Client\Response
    {
        try {
            return Http::timeout(30)->acceptJson()->get($site.$path, $query);
        } catch (ConnectionException) {
            return null;
        }
    }
}
