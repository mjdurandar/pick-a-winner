<?php

namespace App\Http\Controllers;

use App\Services\WordPress\SponsorRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The Partner Management dashboard: a thin, authenticated proxy onto the film
 * site's own partner records.
 *
 * Nothing is persisted here. Every read and write goes to the repository, which
 * is WordPress in production. The Win App keeps no partner rows, no logos and
 * no event-partner relationships of its own — WordPress owns all of it.
 *
 * The film sites model partners as **groups**: one post per tier per region per
 * season, holding a repeater of logo-and-link rows. Uploading a partner logo
 * therefore means appending a row to a group, which is why the routes below
 * are group-scoped. Films do not appear in that model at all; the event filter
 * translates a film into the region and season already recorded for it in
 * film_site_checks.
 *
 * Credentials never leave the server: the browser talks to these routes, and
 * only these routes talk to WordPress.
 */
class SponsorDashboardController extends Controller
{
    /** Largest logo accepted, in kilobytes. */
    public const MAX_LOGO_KB = 4096;

    /** Region and season terms change about once a year; reading them is slow. */
    private const TAXONOMY_CACHE_MINUTES = 30;

    public function __construct(protected SponsorRepository $partners) {}

    public function index(Request $request): Response
    {
        // Which film site, region and season an event publishes to is already
        // recorded for the film-site check. Reused rather than duplicated.
        $sites = DB::table('film_site_checks')
            ->join('events', 'events.id', '=', 'film_site_checks.event_id')
            ->orderBy('events.event_name')
            ->get([
                'film_site_checks.id',
                'film_site_checks.event_id',
                'film_site_checks.site_url',
                'film_site_checks.region',
                'film_site_checks.season',
                'events.event_name',
            ]);

        $filters = [
            'region' => $request->query('region') ?: null,
            'season' => $request->query('season') ?: null,
            'search' => $request->query('search') ?: null,
        ];

        // Arriving from a film's settings page. The film sites tag partners by
        // region and season, never by event, so a film is translated into the
        // region and season already recorded for it rather than filtered on
        // directly. Only used when the request did not name them itself.
        $film = $sites->firstWhere('event_id', (int) $request->query('film'));

        if ($film && ! $filters['region'] && ! $filters['season']) {
            // film_site_checks stores slugs ('au', '2026'); the API filters on
            // term ids, so the site's own terms decide the mapping rather than
            // a table of guesses here.
            $filters['region'] = $this->termId('region', $film->region);
            $filters['season'] = $this->termId('season', $film->season);
        }

        // Each of these is a round trip to WordPress taking a second or more,
        // so they are closures: an Inertia partial reload then pays only for
        // the ones it actually asked for. A drag refetches the groups and
        // leaves the pages alone.
        $failed = null;
        $guard = function (callable $fetch, $fallback) use (&$failed) {
            try {
                return $fetch();
            } catch (Throwable $e) {
                // Reported, never papered over with substitute data.
                $failed ??= $e->getMessage();

                return $fallback;
            }
        };

        return Inertia::render('SponsorDashboard', [
            'driver' => $this->partners->driver(),
            'isLive' => $this->partners->isLive(),
            'sites' => $sites,
            'filters' => $filters,
            // The film that was asked for, so the screen can say whose partners
            // these are and offer a way back.
            'film' => $film ? [
                'event_id' => $film->event_id,
                'event_name' => $film->event_name,
                'region' => $film->region,
                'season' => $film->season,
            ] : null,
            // Region and season terms are reference data that changes once a
            // year at most, and reading them costs two seconds. Cached, keyed
            // by driver so mock and live never share an entry.
            'taxonomies' => fn () => $guard(fn () => $this->cachedTaxonomies(), ['region' => [], 'season' => []]),
            'groups' => fn () => $guard(fn () => $this->partners->groups($filters), []),
            // Which pages exist and which groups each shows. A group on no page
            // is invisible however it is tagged, so the screen has to say so.
            'pages' => fn () => $guard(fn () => $this->partners->pages(), []),
            // Declared after the closures above so it sees whether they failed.
            'loadError' => fn () => $failed,
            'maxLogoKb' => self::MAX_LOGO_KB,
            // For deep links into wp-admin. The site address only, never the
            // credentials that go with it.
            'siteUrl' => $this->partners->isLive()
                ? rtrim((string) config('services.wordpress.waft.url'), '/')
                : null,
        ]);
    }

    // -- groups --------------------------------------------------------------

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'heading' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:publish,draft'],
            'regions' => ['array'],
            'regions.*' => ['integer'],
            'seasons' => ['array'],
            'seasons.*' => ['integer'],
        ]);

        try {
            $this->partners->createGroup($data);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Partner group created in '.$this->where().'.');
    }

    public function updateGroup(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'heading' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:publish,draft'],
            'regions' => ['sometimes', 'array'],
            'regions.*' => ['integer'],
            'seasons' => ['sometimes', 'array'],
            'seasons.*' => ['integer'],
        ]);

        try {
            $this->partners->updateGroup($group, $data);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Partner group updated in '.$this->where().'.');
    }

    public function destroyGroup(int $group): RedirectResponse
    {
        try {
            $this->partners->deleteGroup($group);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Group deleted. On a live site it goes to the WordPress trash and can be restored there.');
    }

    /**
     * Put a group on a partners page, or take it off.
     *
     * Publishing a group only allows it to be shown; this is what makes a page
     * actually show it. It edits that page's layout, so the repository backs
     * out its own change if WordPress does not keep it.
     */
    public function placeGroup(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate([
            'page_id' => ['required', 'integer'],
            'show' => ['required', 'boolean'],
        ]);

        try {
            $data['show']
                ? $this->partners->placeGroup($data['page_id'], $group)
                : $this->partners->unplaceGroup($data['page_id'], $group);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['show']
            ? 'Group added to that page — it shows there now.'
            : 'Group taken off that page. Nothing in it was deleted.');
    }

    // -- partners within a group ---------------------------------------------

    public function storePartner(Request $request, int $group): RedirectResponse
    {
        $data = $this->validatedPartner($request, logoRequired: true);

        try {
            // Before the logo: an upload that lands while the group cannot be
            // written would orphan an image in the media library.
            $this->partners->assertWritable();

            if ($logo = $this->uploadLogo($request)) {
                $data = array_merge($data, $logo);
            }

            $this->partners->addPartner($group, $data);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Partner added in '.$this->where().'.');
    }

    public function updatePartner(Request $request, int $group, int $index): RedirectResponse
    {
        $data = $this->validatedPartner($request);

        try {
            $this->partners->assertWritable();

            if ($logo = $this->uploadLogo($request)) {
                $data = array_merge($data, $logo);
            }

            $this->partners->updatePartner($group, $index, $data);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Partner updated in '.$this->where().'.');
    }

    /** Set the order of a group's partners, as dragged on the dashboard. */
    public function reorderPartners(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'min:0'],
        ]);

        try {
            $this->partners->reorderPartners($group, array_map('intval', $data['order']));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Partner order saved.');
    }

    /** Set the order of the groups on one page. */
    public function reorderPage(Request $request, int $page): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'min:1'],
        ]);

        try {
            $this->partners->reorderPage($page, array_map('intval', $data['order']));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Page order saved.');
    }

    public function destroyPartner(int $group, int $index): RedirectResponse
    {
        try {
            $this->partners->removePartner($group, $index);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Partner removed from the group. The logo stays in the WordPress media library.');
    }

    // -- helpers -------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validatedPartner(Request $request, bool $logoRequired = false): array
    {
        return $request->validate([
            'website_url' => ['nullable', 'url', 'max:1024'],
            'text' => ['nullable', 'string', 'max:255'],
            'max_height' => ['nullable', 'integer', 'between:20,600'],
            'logo' => [$logoRequired ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,gif,svg,webp', 'max:'.self::MAX_LOGO_KB],
        ], [
            'logo.required' => 'A partner needs a logo — that is the only thing the partners page actually shows.',
            'logo.max' => 'That logo is too large. The limit is '.rtrim(rtrim(number_format(self::MAX_LOGO_KB / 1024, 1), '0'), '.').' MB — try resizing it or exporting at a lower quality.',
            'logo.image' => 'That logo is not an image file. Use a JPG, PNG, GIF, SVG or WebP.',
        ]);
    }

    /**
     * Logos go straight to the destination's media library. The upload is held
     * only for the length of this request — PHP discards the temp file after.
     *
     * @return array{logo_id: int, logo_url: string}|null
     */
    private function uploadLogo(Request $request): ?array
    {
        if (! $request->hasFile('logo')) {
            return null;
        }

        $uploaded = $this->partners->uploadLogo($request->file('logo'));

        return ['logo_id' => $uploaded['id'], 'logo_url' => $uploaded['url']];
    }

    /**
     * Region and season terms as the site defines them.
     *
     * Reference data that changes about once a year and costs two seconds to
     * read, so it is cached — keyed by driver, or mock and live would share an
     * entry.
     *
     * @return array{region: array<int, array{id: int, name: string, slug: string}>, season: array<int, array{id: int, name: string, slug: string}>}
     */
    private function cachedTaxonomies(): array
    {
        return Cache::remember(
            'sponsor_taxonomies:'.$this->partners->driver(),
            now()->addMinutes(self::TAXONOMY_CACHE_MINUTES),
            fn () => $this->partners->taxonomies(),
        );
    }

    /** The term id for a slug or name on the film site, or null when it has no such term. */
    private function termId(string $taxonomy, ?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        try {
            $terms = $this->cachedTaxonomies()[$taxonomy] ?? [];
        } catch (Throwable) {
            return null; // Reported by the props below; not worth failing the page here.
        }

        foreach ($terms as $term) {
            if (strcasecmp($term['slug'] ?? '', $value) === 0 || strcasecmp($term['name'] ?? '', $value) === 0) {
                return (int) $term['id'];
            }
        }

        return null;
    }

    private function where(): string
    {
        return $this->partners->isLive() ? 'WordPress' : 'the mock store (nothing was sent to WordPress)';
    }
}
