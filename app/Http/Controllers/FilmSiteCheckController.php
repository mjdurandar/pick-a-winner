<?php

namespace App\Http\Controllers;

use App\Exceptions\FilmSiteException;
use App\Jobs\RunFilmSiteCheckJob;
use App\Models\Events;
use App\Models\FilmSiteCheck;
use App\Models\FilmSiteCheckRun;
use App\Services\FilmSiteComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard of film site checks: which sites are compared with which events,
 * what the latest run found, and the log of every run.
 *
 * Nothing here writes to a film site or to locations. The only rows it creates
 * are checks and their run logs.
 */
class FilmSiteCheckController extends Controller
{
    public function __construct(protected FilmSiteComparisonService $service) {}

    public function index(): Response
    {
        $checks = FilmSiteCheck::with(['event', 'latestRun'])->orderBy('id')->get();

        return Inertia::render('FilmSiteChecks', [
            'checks' => $checks->map(fn (FilmSiteCheck $c) => [
                'id' => $c->id,
                'label' => $c->label(),
                'site_url' => $c->site_url,
                'region' => $c->region,
                'season' => $c->season,
                'event_id' => $c->event_id,
                'event_name' => $c->event?->event_name,
                'enabled' => $c->enabled,
                'last_checked_at' => $c->last_checked_at?->toIso8601String(),
                'last_status' => $c->last_status,
                'last_error' => $c->last_error,
                'latest_run' => $c->latestRun ? $this->runSummary($c->latestRun) : null,
            ]),
            'runs' => FilmSiteCheckRun::with(['check.event', 'triggeredBy'])
                ->latest('id')
                ->limit(100)
                ->get($this->runColumns())
                ->map(fn (FilmSiteCheckRun $r) => $this->runSummary($r) + [
                    'check_label' => $r->check?->label(),
                    'event_name' => $r->check?->event?->event_name,
                ]),
            'events' => Events::orderByDesc('event_year')
                ->orderBy('event_name')
                ->get(['id', 'event_name', 'event_year'])
                ->map(fn (Events $e) => [
                    'id' => $e->id,
                    'label' => trim($e->event_name.' ('.$e->event_year.')'),
                ]),
        ]);
    }

    /** The regions and seasons a site offers, so they are picked rather than typed. */
    public function taxonomies(Request $request): JsonResponse
    {
        $validated = $request->validate(['site_url' => 'required|string|max:255']);

        try {
            return response()->json($this->service->taxonomies($validated['site_url']));
        } catch (FilmSiteException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_url' => 'required|string|max:255',
            'event_id' => 'required|exists:events,id',
            // Several regions allowed: one event can span what the site files
            // separately (WAFT: 'au' and 'new-zealand').
            'regions' => 'nullable|array',
            'regions.*' => 'string|max:40',
            'season' => 'nullable|string|max:50',
        ]);

        $regions = collect($validated['regions'] ?? [])->filter()->unique()->sort()->implode(',');
        $validated['region'] = $regions !== '' ? $regions : null;

        try {
            $site = $this->service->normaliseSiteUrl($validated['site_url']);
        } catch (FilmSiteException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Checked here as well as by the unique key: MySQL treats NULL region or
        // season as distinct, so the key alone would allow a duplicate.
        $exists = FilmSiteCheck::where('event_id', $validated['event_id'])
            ->where('site_url', $site)
            ->where('region', $validated['region'])
            ->where('season', $validated['season'] ?: null)
            ->exists();

        if ($exists) {
            return back()->with('error', 'That site, region and season is already checked against that event.');
        }

        $check = FilmSiteCheck::create([
            'event_id' => $validated['event_id'],
            'site_url' => $site,
            'region' => $validated['region'],
            'season' => $validated['season'] ?: null,
            'enabled' => true,
        ]);

        RunFilmSiteCheckJob::dispatchSync($check->id, FilmSiteCheckRun::TRIGGER_MANUAL, $request->user()?->id);

        return back()->with('success', "Added {$check->label()} and ran the first check.");
    }

    public function update(Request $request, FilmSiteCheck $filmSiteCheck): RedirectResponse
    {
        $validated = $request->validate([
            'event_id' => 'sometimes|required|exists:events,id',
            'enabled' => 'sometimes|boolean',
        ]);

        $filmSiteCheck->update($validated);

        return back()->with('success', 'Check updated.');
    }

    public function destroy(FilmSiteCheck $filmSiteCheck): RedirectResponse
    {
        $label = $filmSiteCheck->label();

        // Its run log goes with it (cascade). Locations are untouched.
        $filmSiteCheck->delete();

        return back()->with('success', "Removed the check for {$label} and its run log.");
    }

    /** Run now, inline — the admin is watching and it is a few REST reads. */
    public function run(Request $request, FilmSiteCheck $filmSiteCheck): RedirectResponse
    {
        RunFilmSiteCheckJob::dispatchSync($filmSiteCheck->id, FilmSiteCheckRun::TRIGGER_MANUAL, $request->user()?->id);

        $filmSiteCheck->refresh();

        if ($filmSiteCheck->last_status === FilmSiteCheck::STATUS_FAILED) {
            return back()->with('error', "{$filmSiteCheck->label()}: {$filmSiteCheck->last_error}");
        }

        return back()->with('success', sprintf(
            '%s checked — %d error%s, %d warning%s.',
            $filmSiteCheck->label(),
            $filmSiteCheck->last_errors,
            $filmSiteCheck->last_errors === 1 ? '' : 's',
            $filmSiteCheck->last_warnings,
            $filmSiteCheck->last_warnings === 1 ? '' : 's'
        ));
    }

    /** Check all now: every enabled check, inline, same as pressing Run now on each. */
    public function runAll(Request $request): RedirectResponse
    {
        $checks = FilmSiteCheck::enabled()->orderBy('id')->get();

        if ($checks->isEmpty()) {
            return back()->with('error', 'No active checks to run.');
        }

        foreach ($checks as $check) {
            RunFilmSiteCheckJob::dispatchSync($check->id, FilmSiteCheckRun::TRIGGER_MANUAL, $request->user()?->id);
            $check->refresh();
        }

        $failed = $checks->where('last_status', FilmSiteCheck::STATUS_FAILED);
        $errors = $checks->sum('last_errors');
        $warnings = $checks->sum('last_warnings');

        $summary = sprintf(
            'Checked %d site%s — %d error%s, %d warning%s.',
            $checks->count(),
            $checks->count() === 1 ? '' : 's',
            $errors,
            $errors === 1 ? '' : 's',
            $warnings,
            $warnings === 1 ? '' : 's'
        );

        if ($failed->isNotEmpty()) {
            return back()->with('error', $summary.' Failed: '.$failed->map(fn (FilmSiteCheck $c) => $c->label())->implode(', ').'.');
        }

        return back()->with('success', $summary);
    }

    /** One run's compared screenings, for the detail panel. */
    public function showRun(FilmSiteCheckRun $filmSiteCheckRun): JsonResponse
    {
        $filmSiteCheckRun->load(['check.event', 'triggeredBy']);

        return response()->json($this->runSummary($filmSiteCheckRun) + [
            'check_label' => $filmSiteCheckRun->check?->label(),
            'event_name' => $filmSiteCheckRun->check?->event?->event_name,
            'items' => $filmSiteCheckRun->items ?? [],
            'meta' => $filmSiteCheckRun->meta ?? [],
        ]);
    }

    /**
     * Everything but the items blob — the log list shows a hundred runs and
     * does not need a hundred copies of every screening.
     *
     * @return array<int, string>
     */
    protected function runColumns(): array
    {
        return [
            'id', 'film_site_check_id', 'status', 'error', 'trigger', 'triggered_by_user_id',
            'matched', 'errors', 'warnings', 'notices', 'new_issues', 'resolved_issues',
            'duration_ms', 'created_at',
        ];
    }

    /** @return array<string, mixed> */
    protected function runSummary(FilmSiteCheckRun $run): array
    {
        return [
            'id' => $run->id,
            'check_id' => $run->film_site_check_id,
            'status' => $run->status,
            'error' => $run->error,
            'trigger' => $run->trigger,
            'triggered_by' => $run->triggeredBy?->name,
            'matched' => $run->matched,
            'errors' => $run->errors,
            'warnings' => $run->warnings,
            'notices' => $run->notices,
            'new_issues' => $run->new_issues,
            'resolved_issues' => $run->resolved_issues,
            'duration_ms' => $run->duration_ms,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
