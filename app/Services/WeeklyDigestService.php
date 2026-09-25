<?php

namespace App\Services;

use App\Models\Events;
use App\Models\FilmSiteCheck;
use App\Models\Location;
use App\Models\SheetSource;
use App\Models\SheetSourceChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Gathers the weekly digest: what screened, what it collected, and what the two
 * syncs have been trying to tell us.
 *
 * Read-only throughout. Nothing here approves a sheet batch or triggers a sync —
 * the mail reports, and the decisions stay on their own screens behind auth.
 */
class WeeklyDigestService
{
    /** How many sheet rows and site differences the mail spells out per source. */
    public const SAMPLE_SIZE = 8;

    /**
     * Everything the digest needs for the week ending at $end.
     *
     * @return array{
     *     window: array{start: CarbonImmutable, end: CarbonImmutable},
     *     screenings: list<array<string, mixed>>,
     *     totals: array{screenings: int, signups: int, events: int},
     *     sheetSources: list<array<string, mixed>>,
     *     sheetTotals: array{creates: int, updates: int, missing: int, dates: int},
     *     siteChecks: list<array<string, mixed>>,
     *     siteTotals: array{errors: int, warnings: int, stale: int},
     * }
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $screenings = $this->screenings($start, $end);
        $sheetSources = $this->sheetSources();
        $siteChecks = $this->siteChecks();

        return [
            'window' => ['start' => $start, 'end' => $end],
            'screenings' => $screenings,
            'totals' => [
                'events' => count($screenings),
                'screenings' => array_sum(array_map(fn ($e) => count($e['locations']), $screenings)),
                'signups' => array_sum(array_column($screenings, 'signups')),
            ],
            'sheetSources' => $sheetSources,
            'sheetTotals' => [
                'creates' => array_sum(array_map(fn ($s) => $s['counts']['creates'], $sheetSources)),
                'updates' => array_sum(array_map(fn ($s) => $s['counts']['updates'], $sheetSources)),
                'missing' => array_sum(array_map(fn ($s) => $s['counts']['missing'], $sheetSources)),
                'dates' => array_sum(array_column($sheetSources, 'date_changes')),
            ],
            'siteChecks' => $siteChecks,
            'siteTotals' => [
                'errors' => array_sum(array_column($siteChecks, 'errors')),
                'warnings' => array_sum(array_column($siteChecks, 'warnings')),
                'stale' => count(array_filter($siteChecks, fn ($c) => $c['stale'])),
            ],
        ];
    }

    /**
     * The screenings that fell in the window, grouped by event, with what each
     * one collected.
     *
     * Location.date is a plain Y-m-d string, deliberately uncast and free to hold
     * 'TBA', so the window is matched as a string range rather than by parsing
     * every row — a TBA sorts outside any real date and drops out on its own.
     *
     * @return list<array<string, mixed>>
     */
    protected function screenings(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();

        $locations = Location::query()
            ->whereBetween(DB::raw('SUBSTRING(date, 1, 10)'), [$from, $to])
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        if ($locations->isEmpty()) {
            return [];
        }

        $events = Events::with('film')
            ->whereIn('id', $locations->pluck('event_id')->unique()->filter())
            ->get()
            ->keyBy('id');

        // One count query per event table rather than per location: the sign-up
        // rows live in a per-event table, and a tour week can be forty screenings.
        $signupsByLocation = [];
        foreach ($events as $event) {
            $signupsByLocation += $this->signupCounts($event, $locations->where('event_id', $event->id)->pluck('id')->all());
        }

        $grouped = [];

        foreach ($locations as $location) {
            $event = $events->get($location->event_id);
            $key = $location->event_id ?? 0;

            $grouped[$key] ??= [
                'event_id' => $location->event_id,
                'event_name' => $event->event_name ?? 'Unknown event',
                'film' => $event?->film?->name,
                'country' => $event->event_country ?? null,
                'locations' => [],
                'signups' => 0,
            ];

            $signups = (int) ($signupsByLocation[$location->id] ?? 0);

            $grouped[$key]['locations'][] = [
                'id' => $location->id,
                'name' => $location->name,
                'date' => $this->prettyDate($location->date),
                'state' => $location->state,
                'signups' => $signups,
            ];
            $grouped[$key]['signups'] += $signups;
        }

        // Busiest first — the tour with three hundred sign-ups is the one worth
        // reading before the one with a single screening.
        usort($grouped, fn ($a, $b) => $b['signups'] <=> $a['signups']);

        return array_values($grouped);
    }

    /**
     * Sign-up counts for the given locations of one event.
     *
     * The table name comes from the event's sign-up form and is a stored string,
     * so its existence is checked rather than assumed: an event whose form was
     * rebuilt can point at a table that is no longer there, and a digest must not
     * die on it.
     *
     * @param  list<int>  $locationIds
     * @return array<int, int>
     */
    protected function signupCounts(Events $event, array $locationIds): array
    {
        $table = $event->signUpForm?->table_name;

        if (! $table || $locationIds === []) {
            return [];
        }

        try {
            if (! Schema::hasTable($table)) {
                return [];
            }

            return DB::table($table)
                ->whereIn('location_id', $locationIds)
                ->selectRaw('location_id, count(*) as total')
                ->groupBy('location_id')
                ->pluck('total', 'location_id')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Weekly digest could not count sign-ups', [
                'event_id' => $event->id,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Master sheet tabs sitting on changes nobody has accepted.
     *
     * The parked batch is what is true right now, not a history of the week: the
     * sync rewrites it every five minutes, so anything approved during the week
     * has already left it. That is the right thing for a digest to carry — it is
     * a list of what still needs someone, not a diary.
     *
     * @return list<array<string, mixed>>
     */
    protected function sheetSources(): array
    {
        $sources = SheetSource::with('event')->needingReview()->get();

        return $sources->map(function (SheetSource $source) {
            $counts = $source->reviewCounts();

            return [
                'tab' => $source->tab_name,
                'event' => $source->event?->event_name,
                'counts' => $counts,
                'date_changes' => $this->dateChangeCount($source),
                'samples' => $this->sheetSamples($source),
                'overflow' => max(0, array_sum($counts) - self::SAMPLE_SIZE),
                'last_synced_at' => $source->last_synced_at ?? null,
            ];
        })->all();
    }

    /**
     * How many parked updates move a screening's date or time.
     *
     * Called out separately because it is the change that costs money — a moved
     * screening that nobody applied means the app, the website and the sheet are
     * telling ticket buyers three different things.
     */
    protected function dateChangeCount(SheetSource $source): int
    {
        return SheetSourceChange::where('sheet_source_id', $source->id)
            ->where('action', SheetSourceChange::ACTION_UPDATE)
            ->get(['diff'])
            ->filter(fn (SheetSourceChange $c) => $this->touchesDate($c))
            ->count();
    }

    /** Date and time moves, named as the sheet importer names them. */
    protected function touchesDate(SheetSourceChange $change): bool
    {
        $fields = array_keys($change->diff ?? []);

        return array_intersect($fields, ['date', 'time']) !== [];
    }

    /**
     * A handful of parked rows, date moves first.
     *
     * @return list<array{action: string, label: string, detail: string}>
     */
    protected function sheetSamples(SheetSource $source): array
    {
        return SheetSourceChange::where('sheet_source_id', $source->id)
            ->whereIn('action', SheetSourceChange::NEEDS_REVIEW)
            ->orderByRaw("CASE action WHEN 'update' THEN 0 WHEN 'create' THEN 1 ELSE 2 END")
            ->orderBy('sheet_row')
            ->get()
            // Date moves to the top of the updates: within the batch they are what
            // the digest exists to surface.
            ->sortByDesc(fn (SheetSourceChange $c) => $this->touchesDate($c) ? 1 : 0)
            ->take(self::SAMPLE_SIZE)
            ->map(fn (SheetSourceChange $c) => [
                'action' => match ($c->action) {
                    SheetSourceChange::ACTION_CREATE => 'New',
                    SheetSourceChange::ACTION_UPDATE => $this->touchesDate($c) ? 'Date moved' : 'Changed',
                    default => 'Gone from sheet',
                },
                'label' => $c->label ?: 'Row '.$c->sheet_row,
                'detail' => $this->describeDiff($c),
            ])
            ->values()
            ->all();
    }

    /**
     * What a parked update would change. Dates are quoted with their values —
     * "12 Aug → 19 Aug" is the whole point of the line — while everything else is
     * named only, because a digest is not the review screen.
     */
    protected function describeDiff(SheetSourceChange $change): string
    {
        if ($change->action !== SheetSourceChange::ACTION_UPDATE) {
            return '';
        }

        $diff = $change->diff ?? [];

        if ($diff === []) {
            return '';
        }

        $parts = [];

        foreach (['date', 'time'] as $field) {
            if (! array_key_exists($field, $diff)) {
                continue;
            }

            $parts[] = sprintf(
                '%s %s → %s',
                $field,
                $this->diffSide($diff[$field], 0) ?: '—',
                $this->diffSide($diff[$field], 1) ?: '—'
            );
        }

        $others = array_diff(array_keys($diff), ['date', 'time']);

        if ($others !== []) {
            $named = array_map(fn ($f) => str_replace('_', ' ', (string) $f), $others);
            $parts[] = count($named) > 3
                ? implode(', ', array_slice($named, 0, 3)).' and '.(count($named) - 3).' more'
                : implode(', ', $named);
        }

        return implode('; ', $parts);
    }

    /**
     * One side of a diff entry. SheetSyncService::diff() writes each field as a
     * positional [old, new] pair; a bare scalar is read as the new value so an
     * unexpected shape degrades to "— → value" rather than throwing.
     */
    protected function diffSide(mixed $entry, int $side): string
    {
        if (is_array($entry)) {
            return trim((string) ($entry[$side] ?? ''));
        }

        return $side === 1 ? trim((string) $entry) : '';
    }

    /**
     * Every enabled film site check with its latest run.
     *
     * @return list<array<string, mixed>>
     */
    protected function siteChecks(): array
    {
        $checks = FilmSiteCheck::with(['event', 'latestRun'])
            ->where('enabled', true)
            ->get();

        return $checks->map(function (FilmSiteCheck $check) {
            $run = $check->latestRun;
            $items = collect($run->items ?? []);

            $problems = $items
                ->whereIn('severity', [
                    FilmSiteComparisonService::SEVERITY_ERROR,
                    FilmSiteComparisonService::SEVERITY_WARNING,
                ])
                // Errors before warnings; a date mismatch outranks a missing listing.
                ->sortBy(fn ($i) => ($i['severity'] ?? '') === FilmSiteComparisonService::SEVERITY_ERROR ? 0 : 1)
                ->values();

            return [
                'event' => $check->event?->event_name,
                'site' => preg_replace('#^https?://(www\.)?#', '', rtrim($check->site_url, '/')),
                'season' => $check->season,
                'region' => $check->region,
                'status' => $check->last_status,
                'error' => $check->last_error,
                'checked_at' => $check->last_checked_at,
                // The check runs daily, so nothing in a day and a half means a
                // scheduled run was missed — the scheduler or the site itself has
                // stopped answering.
                'stale' => $check->last_checked_at === null || $check->last_checked_at->lt(now()->subHours(36)),
                'errors' => (int) $check->last_errors,
                'warnings' => (int) $check->last_warnings,
                'new_issues' => (int) ($run->new_issues ?? 0),
                'resolved_issues' => (int) ($run->resolved_issues ?? 0),
                'samples' => $problems->take(self::SAMPLE_SIZE)->map(fn ($i) => [
                    // The comparison lowercases place names to match on them; the
                    // dashboard can live with that, an email reads better without it.
                    'place' => ucwords((string) ($i['place'] ?? '')) ?: '—',
                    'message' => $i['message'] ?? '',
                    'severity' => $i['severity'] ?? '',
                ])->all(),
                'overflow' => max(0, $problems->count() - self::SAMPLE_SIZE),
            ];
        })->all();
    }

    /** "Tue 12 Aug", or the raw string when the sheet gave us something else. */
    protected function prettyDate(mixed $date): string
    {
        $raw = trim((string) $date);

        if ($raw === '' || strcasecmp($raw, 'TBA') === 0) {
            return 'TBA';
        }

        try {
            return CarbonImmutable::parse(substr($raw, 0, 10))->format('D j M');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
