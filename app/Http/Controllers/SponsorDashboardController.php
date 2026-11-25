<?php

namespace App\Http\Controllers;

use App\Services\WordPress\FilmSites;
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

    private ?SponsorRepository $repository = null;

    /** Which film site this request is for. Set by useSite() before anything else. */
    private ?string $siteKey = null;

    public function __construct(private readonly FilmSites $sites) {}

    /**
     * Settle which film site this request is for.
     *
     * There is one WordPress per tour, and nothing about one may be assumed of
     * another — "Australia" is a different term id on each. Writing a partner
     * onto the wrong tour's public site is much worse than a request that
     * refuses to run, so an unknown or missing site is an error for every write
     * and only the dashboard itself may fall back to a default.
     *
     * @return bool whether a site was settled on
     */
    private function useSite(Request $request, bool $orDefault = false): bool
    {
        // Cleared before anything is read. A controller instance can outlive the
        // request that made it — Laravel memoises the one it resolved onto the
        // route, and a long-lived worker reuses both — so a site left behind by
        // the last request is precisely how a write would reach the wrong tour's
        // public site. Refusing has to mean no site at all, not the previous one.
        $this->siteKey = null;
        $this->repository = null;

        $asked = trim((string) $request->input('site', ''));

        if ($asked !== '' && $this->sites->has($asked)) {
            $this->siteKey = $asked;

            return true;
        }

        if ($orDefault && $asked === '') {
            $this->siteKey = $this->sites->default();

            return $this->siteKey !== null;
        }

        return false;
    }

    /**
     * Resolved on use, not injected.
     *
     * The repository throws when a site has no credentials, and a constructor
     * dependency would turn that into a 500 before any action runs — from a
     * link in the main nav. Resolving here lets the screen say what is wrong
     * instead.
     */
    private function partners(): SponsorRepository
    {
        return $this->repository ??= $this->sites->repository((string) $this->siteKey);
    }

    /**
     * Run one write against the settled site, turning a failure into a message.
     *
     * The callable returns what to tell the admin. Wrapping it here is what
     * keeps the site check in front of every write: an action cannot forget it
     * without also losing its error handling.
     */
    private function onSite(Request $request, callable $work): RedirectResponse
    {
        if (! $this->useSite($request)) {
            return back()->with('error', $this->siteProblem($request));
        }

        try {
            $message = $work();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * Why a request could not be pointed at a site, in the terms the admin sees.
     *
     * Never resolved by quietly using another site: landing on a different
     * tour's partners without being told is the confusion this screen exists to
     * prevent.
     */
    private function siteProblem(Request $request, string $consequence = 'so nothing was written'): string
    {
        $asked = trim((string) $request->input('site', ''));

        if ($asked === '') {
            return $this->sites->configured() === []
                ? 'No film site is connected yet, so there is nothing to show or save.'
                : 'That request did not say which film site it was for, '.$consequence
                    .'. Reload the page and try again.';
        }

        if (isset($this->sites->all()[$asked])) {
            return $this->sites->label($asked).' is not connected yet — its address and application password '
                .'are not set on the server — '.$consequence.'.';
        }

        return "'{$asked}' is not a film site this app knows about, ".$consequence.'.';
    }

    public function index(Request $request): Response
    {
        // Which film site, region and season an event publishes to is already
        // recorded for the film-site check. Reused rather than duplicated.
        $films = DB::table('film_site_checks')
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
        $film = $films->firstWhere('event_id', (int) $request->query('film'));

        // The film also says which site to be on, so arriving from one lands on
        // its tour rather than on whichever site happens to be listed first.
        if ($film && ! $request->filled('site')) {
            $request->merge(['site' => $this->sites->forUrl($film->site_url)]);
        }

        // The dashboard is the one place allowed to fall back to a default: it
        // only reads, and it has to render something to pick a site from.
        $hasSite = $this->useSite($request, orDefault: true);

        if ($film && ! $filters['region'] && ! $filters['season'] && $hasSite) {
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
        //
        // They are also deferred. The page used to wait for all of them before
        // it showed anything, which read as a dead click; now it opens at once
        // and fetches them in a second request while a spinner says so. One
        // group, so they still resolve in order in a single request and
        // `loadError` still sees whether the ones before it failed.
        $failed = null;
        $guard = function (callable $fetch, $fallback) use (&$failed, $hasSite) {
            // No site settled means none is connected. That is a state to
            // describe, not an error to raise on every prop.
            if (! $hasSite) {
                return $fallback;
            }

            try {
                return $fetch();
            } catch (Throwable $e) {
                // Reported, never papered over with substitute data.
                $failed ??= $e->getMessage();

                return $fallback;
            }
        };

        return Inertia::render('SponsorDashboard', [
            'driver' => $guard(fn () => $this->partners()->driver(), 'unconfigured'),
            'isLive' => $guard(fn () => $this->partners()->isLive(), false),
            // The tours this app can manage, and which one is being looked at.
            // Every write names it back, so nothing can land on the wrong site.
            'connections' => array_values($this->sites->all()),
            'site' => $this->siteKey,
            // Asked for a site that cannot be used. Said plainly rather than
            // resolved by showing a different tour's partners instead.
            'siteError' => $hasSite ? null : $this->siteProblem($request, 'so there is nothing to show'),
            'films' => $films,
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
            // year at most, and reading them costs two seconds. Cached per
            // site — the terms and their ids differ on every one of them.
            'taxonomies' => Inertia::defer(fn () => $guard(fn () => $this->cachedTaxonomies(), ['region' => [], 'season' => []]), 'wordpress'),
            'groups' => Inertia::defer(fn () => $guard(fn () => $this->partners()->groups($filters), []), 'wordpress'),
            // Which pages exist and which groups each shows. A group on no page
            // is invisible however it is tagged, so the screen has to say so.
            'pages' => Inertia::defer(fn () => $guard(fn () => $this->partners()->pages(), []), 'wordpress'),
            // Declared after the closures above so it sees whether they failed.
            'loadError' => Inertia::defer(fn () => $failed, 'wordpress'),
            'maxLogoKb' => self::MAX_LOGO_KB,
            // For deep links into wp-admin. The site address only, never the
            // credentials that go with it.
            'siteUrl' => $guard(
                fn () => $this->partners()->isLive()
                    ? $this->sites->url((string) $this->siteKey)
                    : null,
                null,
            ),
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

        return $this->onSite($request, function () use ($data) {
            $this->partners()->createGroup($data);

            return 'Partner group created in '.$this->where().'.';
        });
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

        return $this->onSite($request, function () use ($group, $data) {
            $this->partners()->updateGroup($group, $data);

            return 'Partner group updated in '.$this->where().'.';
        });
    }

    public function destroyGroup(Request $request, int $group): RedirectResponse
    {
        return $this->onSite($request, function () use ($group) {
            $this->partners()->deleteGroup($group);

            return 'Group deleted. On a live site it goes to the WordPress trash and can be restored there.';
        });
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

        return $this->onSite($request, function () use ($group, $data) {
            $data['show']
                ? $this->partners()->placeGroup($data['page_id'], $group)
                : $this->partners()->unplaceGroup($data['page_id'], $group);

            return $data['show']
                ? 'Group added to that page — it shows there now.'
                : 'Group taken off that page. Nothing in it was deleted.';
        });
    }

    // -- partners within a group ---------------------------------------------

    public function storePartner(Request $request, int $group): RedirectResponse
    {
        $data = $this->validatedPartner($request, logoRequired: true);

        return $this->onSite($request, function () use ($request, $group, $data) {
            // Before the logo: an upload that lands while the group cannot be
            // written would orphan an image in the media library.
            $this->partners()->assertWritable();

            if ($logo = $this->uploadLogo($request)) {
                $data = array_merge($data, $logo);
            }

            $this->partners()->addPartner($group, $data);

            return 'Partner added in '.$this->where().'.';
        });
    }

    public function updatePartner(Request $request, int $group, int $index): RedirectResponse
    {
        $data = $this->validatedPartner($request);

        return $this->onSite($request, function () use ($request, $group, $index, $data) {
            $this->partners()->assertWritable();

            if ($logo = $this->uploadLogo($request)) {
                $data = array_merge($data, $logo);
            }

            $this->partners()->updatePartner($group, $index, $data);

            return 'Partner updated in '.$this->where().'.';
        });
    }

    /** Set the order of a group's partners, as dragged on the dashboard. */
    public function reorderPartners(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'min:0'],
        ]);

        return $this->onSite($request, function () use ($group, $data) {
            $this->partners()->reorderPartners($group, array_map('intval', $data['order']));

            return 'Partner order saved.';
        });
    }

    /** Set the order of the groups on one page. */
    public function reorderPage(Request $request, int $page): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'min:1'],
        ]);

        return $this->onSite($request, function () use ($page, $data) {
            $this->partners()->reorderPage($page, array_map('intval', $data['order']));

            return 'Page order saved.';
        });
    }

    public function destroyPartner(Request $request, int $group, int $index): RedirectResponse
    {
        return $this->onSite($request, function () use ($group, $index) {
            $this->partners()->removePartner($group, $index);

            return 'Partner removed from the group. The logo stays in the WordPress media library.';
        });
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

        $uploaded = $this->partners()->uploadLogo($request->file('logo'));

        return ['logo_id' => $uploaded['id'], 'logo_url' => $uploaded['url']];
    }

    /**
     * Region and season terms as the site defines them.
     *
     * Reference data that changes about once a year and costs two seconds to
     * read, so it is cached — keyed by site as well as driver. The sites do not
     * share term ids ("Australia" is 2 on one and 6 on another), so one shared
     * entry would filter by whatever the last site read put there.
     *
     * @return array{region: array<int, array{id: int, name: string, slug: string}>, season: array<int, array{id: int, name: string, slug: string}>}
     */
    private function cachedTaxonomies(): array
    {
        return Cache::remember(
            'sponsor_taxonomies:'.$this->partners()->driver().':'.$this->siteKey,
            now()->addMinutes(self::TAXONOMY_CACHE_MINUTES),
            fn () => $this->partners()->taxonomies(),
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
        return $this->partners()->isLive() ? 'WordPress' : 'the mock store (nothing was sent to WordPress)';
    }
}
