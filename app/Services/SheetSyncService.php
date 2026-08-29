<?php

namespace App\Services;

use App\Models\Location;
use App\Models\SheetSource;
use App\Models\SheetSourceChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a configured tab of the master schedule into locations.
 *
 * The read is strictly one-way. Nothing in this class, or anything it calls,
 * writes to the spreadsheet — the OAuth scope the connection holds is
 * spreadsheets.readonly, so the API would refuse a write even if one were asked
 * for. The sheet belongs to someone else and stays exactly as they left it.
 *
 * Every run produces a plan first. A preview stops there and stores the plan for
 * the review screen; a sync stores it and then applies it. That split is what
 * lets a tab be watched for a run or two before it is trusted with the locations
 * table.
 */
class SheetSyncService
{
    /** Sheet checkbox columns, compared as booleans rather than as strings. */
    public const BOOL_FIELDS = ['dcp_trailer_sent', 'media_kit_sent', 'dcp_sent'];

    public function __construct(
        protected GoogleSheetsApi $sheets,
        protected SheetRowMapper $mapper,
        protected LocationValueParser $values,
    ) {}

    /**
     * Re-read the tab and park what it would do.
     *
     * Never writes to locations. A run's job is only to notice that the sheet has
     * moved and leave a reviewable batch behind; applying it is a separate,
     * deliberate act by an admin. That is what stops a stray edit in a shared
     * spreadsheet from silently rewriting live screenings.
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int, missing: int, actionable: int}
     *
     * @throws \App\Exceptions\GoogleSheetsException
     */
    public function refresh(SheetSource $source): array
    {
        $plan = $this->plan($source);

        $this->storePlan($source, $plan);

        $summary = $this->summarise($plan);

        return $summary + ['actionable' => $summary['created'] + $summary['updated']];
    }

    /**
     * Apply the batch the admin just reviewed.
     *
     * Deliberately replays the *stored* plan rather than re-reading the sheet: the
     * admin approved a specific set of changes, and silently applying a newer set
     * because someone typed into the spreadsheet in the meantime would make the
     * review meaningless. If the sheet has moved on, the next run picks that up as
     * a fresh batch to review.
     *
     * @return array{created: int, updated: int}
     */
    public function applyStored(SheetSource $source): array
    {
        $stored = SheetSourceChange::where('sheet_source_id', $source->id)
            ->whereIn('action', SheetSourceChange::ACTIONABLE)
            ->orderBy('sheet_row')
            ->get();

        $plan = $stored->map(fn (SheetSourceChange $c) => [
            'sheet_row' => $c->sheet_row,
            'action' => $c->action,
            'location_id' => $c->location_id,
            'label' => $c->label,
            'payload' => $c->payload,
            'diff' => $c->diff,
            'skip_reason' => null,
        ])->all();

        $this->apply($source, $plan);

        // Everything this tab matched is now spoken for by it, including the rows
        // that needed no change. Without claiming those, a screening that has
        // always matched the sheet exactly would never carry provenance, and its
        // removal from the sheet could never be reported.
        $this->claim($source, SheetSourceChange::where('sheet_source_id', $source->id)
            ->whereIn('action', [SheetSourceChange::ACTION_UPDATE, SheetSourceChange::ACTION_UNCHANGED])
            ->pluck('location_id')
            ->filter()
            ->all());

        return [
            'created' => count(array_filter($plan, fn ($c) => $c['action'] === SheetSourceChange::ACTION_CREATE)),
            'updated' => count(array_filter($plan, fn ($c) => $c['action'] === SheetSourceChange::ACTION_UPDATE)),
        ];
    }

    /**
     * Claim every location this tab currently matches, without applying anything.
     *
     * Locations that predate the provenance column carry none, so the sync cannot
     * tell them from hand-made ones and will never report them as dropped from
     * the sheet. This is the one-off that adopts them — it reads the sheet, takes
     * the locations its rows match, and stamps only those. Screening data is not
     * touched.
     *
     * @return int locations adopted
     *
     * @throws \App\Exceptions\GoogleSheetsException
     */
    public function adopt(SheetSource $source): int
    {
        $plan = $this->plan($source);

        return $this->claim($source, array_values(array_filter(array_column($plan, 'location_id'))));
    }

    /**
     * Stamp provenance on locations that do not already have it.
     *
     * Never steals a location from another tab: a row already claimed keeps its
     * owner, so two tabs pointed at the same event cannot fight over one.
     *
     * @param  list<int>  $locationIds
     */
    protected function claim(SheetSource $source, array $locationIds): int
    {
        if ($locationIds === []) {
            return 0;
        }

        return Location::whereIn('id', $locationIds)
            ->whereNull('sheet_source_id')
            ->update(['sheet_source_id' => $source->id]);
    }

    /**
     * Read the tab and classify every row beneath its header.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \App\Exceptions\GoogleSheetsException
     */
    public function plan(SheetSource $source): array
    {
        $rows = $this->sheets->values($source->spreadsheet_id, $source->range());

        $header = $this->mapper->locateHeader($rows);

        if ($header === null) {
            throw new \App\Exceptions\GoogleSheetsException(
                'No screening header found in the first '.SheetRowMapper::HEADER_SEARCH_DEPTH
                ." rows of '{$source->tab_name}'. Check the tab is a screening schedule and not a summary or notes tab."
            );
        }

        // Locations of this event as they stand, indexed by the composed name the
        // mapper produces. Loaded once — a tab is a few hundred rows and querying
        // per row would be a few hundred round trips.
        $locations = Location::where('event_id', $source->event_id)->get();

        $existing = $locations->groupBy('name');

        // A location may only be claimed by one row of the run. Without this, two
        // rows naming the same venue on the same date would both bind to it and
        // the second would silently overwrite the first.
        $claimed = [];

        $plan = [];

        foreach ($rows as $index => $row) {
            if ($index <= $header['index']) {
                continue;
            }

            // A1 rows are 1-based and the range starts at row 1.
            $sheetRow = $index + 1;

            $fields = $this->mapper->rawFields($row, $header['columns']);

            if ($reason = $this->mapper->rejectionReason($fields)) {
                $plan[] = [
                    'sheet_row' => $sheetRow,
                    'action' => SheetSourceChange::ACTION_SKIPPED,
                    'location_id' => null,
                    'label' => $this->labelForSkipped($fields),
                    'payload' => null,
                    'diff' => null,
                    'skip_reason' => $reason,
                ];

                continue;
            }

            $attributes = $this->mapper->toLocationAttributes($fields);
            $label = trim($attributes['name'].' — '.($attributes['date'] ?? ''), ' —');

            $match = $this->matchLocation($existing, $attributes, $claimed);

            if (! $match) {
                $plan[] = [
                    'sheet_row' => $sheetRow,
                    'action' => SheetSourceChange::ACTION_CREATE,
                    'location_id' => null,
                    'label' => $label,
                    'payload' => $attributes,
                    'diff' => null,
                    'skip_reason' => null,
                ];

                continue;
            }

            $claimed[$match->id] = true;

            $diff = $this->diff($match, $attributes);

            $plan[] = [
                'sheet_row' => $sheetRow,
                'action' => $diff === [] ? SheetSourceChange::ACTION_UNCHANGED : SheetSourceChange::ACTION_UPDATE,
                'location_id' => $match->id,
                'label' => $label,
                'payload' => $attributes,
                'diff' => $diff === [] ? null : $diff,
                'skip_reason' => null,
            ];
        }

        return array_merge($plan, $this->missing($source, $locations, $claimed));
    }

    /**
     * Locations this tab put here that the sheet has stopped mentioning.
     *
     * Only locations stamped with this source are considered. A location made by
     * hand on the locations screen, or one belonging to another tab pointed at
     * the same event, has no row in this sheet by definition, and reporting it as
     * removed would be wrong every single run.
     *
     * These are reported, never acted on. Deleting a location cascades to its
     * ticket attendees and prizes, and a row vanishing from a hand-edited shared
     * spreadsheet is as often a cut-and-paste mid-edit as a cancellation — so the
     * decision stays with an admin, one row at a time.
     *
     * @param  \Illuminate\Support\Collection<int, Location>  $locations
     * @param  array<int, bool>  $claimed
     * @return list<array<string, mixed>>
     */
    protected function missing(SheetSource $source, $locations, array $claimed): array
    {
        return $locations
            ->filter(fn (Location $l) => $l->sheet_source_id === $source->id && ! isset($claimed[$l->id]))
            ->map(fn (Location $l) => [
                // No row number: the row is precisely what no longer exists.
                'sheet_row' => null,
                'action' => SheetSourceChange::ACTION_MISSING,
                'location_id' => $l->id,
                'label' => trim($l->name.' — '.($l->date ?? ''), ' —'),
                'payload' => null,
                'diff' => null,
                'skip_reason' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * The location this row already corresponds to, or null if it is new.
     *
     * The composed name is the identity — it is what the paste-a-grid save writes
     * and what the sign-up form shows. Where a venue appears once for the event,
     * the name alone decides, so a screening whose date moved in the sheet is
     * updated rather than duplicated. Where it appears more than once, only the
     * date can tell the screenings apart.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Location>>  $existing
     * @param  array<string, mixed>  $attributes
     * @param  array<int, bool>  $claimed
     */
    protected function matchLocation($existing, array $attributes, array $claimed): ?Location
    {
        $candidates = ($existing[$attributes['name']] ?? collect())
            ->reject(fn (Location $l) => isset($claimed[$l->id]));

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return $candidates->firstWhere('date', $attributes['date']);
    }

    /**
     * field => [before, after] for every attribute the sheet would change.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function diff(Location $location, array $attributes): array
    {
        $diff = [];

        foreach ($attributes as $field => $new) {
            $current = $location->getAttribute($field);

            if ($this->differs($field, $current, $new)) {
                $diff[$field] = [$this->displayable($field, $current), $this->displayable($field, $new)];
            }
        }

        return $diff;
    }

    /**
     * Whether the sheet's value is genuinely different from what is stored.
     *
     * Types have to be levelled first: MySQL hands back tinyints for the checkbox
     * columns and strings for the integer one, so a straight comparison would
     * report a change on every run and the review screen would be useless.
     */
    protected function differs(string $field, $current, $new): bool
    {
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return (bool) $current !== (bool) $new;
        }

        if ($field === 'number_of_screenings') {
            return ($current === null ? null : (int) $current) !== ($new === null ? null : (int) $new);
        }

        // Null and empty string both mean "nothing here" across these columns, and
        // which one is stored depends on how the row was first written.
        return trim((string) $current) !== trim((string) $new);
    }

    /** Booleans read as true/false rather than 1/0 in the review screen's diff. */
    protected function displayable(string $field, $value)
    {
        return in_array($field, self::BOOL_FIELDS, true) ? (bool) $value : $value;
    }

    /**
     * A skipped row still gets whatever identifying text it had, so the review
     * screen can show "Premiere Week — Section heading" rather than a bare row
     * number.
     *
     * @param  array<string, string>  $fields
     */
    protected function labelForSkipped(array $fields): ?string
    {
        foreach (['location', 'cinema', 'film', 'date'] as $field) {
            if (($fields[$field] ?? '') !== '') {
                return $fields[$field];
            }
        }

        return null;
    }

    /**
     * Replace this source's stored plan with the one just computed.
     *
     * Wholesale, in a transaction: a half-written plan showing yesterday's
     * creates next to today's updates would be worse than no plan at all.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    protected function storePlan(SheetSource $source, array $plan): void
    {
        DB::transaction(function () use ($source, $plan) {
            SheetSourceChange::where('sheet_source_id', $source->id)->delete();

            foreach (array_chunk($plan, 200) as $chunk) {
                SheetSourceChange::insert(array_map(fn (array $change) => [
                    'sheet_source_id' => $source->id,
                    'sheet_row' => $change['sheet_row'],
                    'action' => $change['action'],
                    'location_id' => $change['location_id'],
                    'label' => $change['label'],
                    'payload' => $change['payload'] === null ? null : json_encode($change['payload']),
                    'diff' => $change['diff'] === null ? null : json_encode($change['diff']),
                    'skip_reason' => $change['skip_reason'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk));
            }
        });
    }

    /**
     * Write the plan to the locations table.
     *
     * Nothing is ever deleted here. A screening that disappears from the tab is
     * left alone: deleting it would take its ticket attendees with it, and a row
     * vanishing from a working spreadsheet is far more often an edit in progress
     * than a cancelled screening. Removing a location stays a deliberate act on
     * the locations screen.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    protected function apply(SheetSource $source, array $plan): void
    {
        DB::transaction(function () use ($source, $plan) {
            // Resolved once per run rather than per row, so a tab creating its
            // first three locations gives all three the same password instead of
            // three different ones.
            $password = null;

            foreach ($plan as $change) {
                if ($change['action'] === SheetSourceChange::ACTION_CREATE) {
                    $password ??= $this->values->passwordForNewLocation($source->event_id);

                    // `+` keeps the payload's own values; these only fill gaps.
                    // locations.time is NOT NULL, so a tab with no Time column at
                    // all still has to put something in it.
                    $location = Location::create($change['payload'] + [
                        'event_id' => $source->event_id,
                        // Provenance. Without it a later run cannot tell this
                        // location from one somebody typed in by hand, and could
                        // not report it as dropped from the sheet.
                        'sheet_source_id' => $source->id,
                        'password' => $password,
                        'time' => 'TBA',
                    ]);

                    SheetSourceChange::where('sheet_source_id', $source->id)
                        ->where('sheet_row', $change['sheet_row'])
                        ->update(['location_id' => $location->id]);

                    continue;
                }

                if ($change['action'] === SheetSourceChange::ACTION_UPDATE) {
                    // Claiming it on update too: the sheet names this screening, so
                    // from here on the sheet is the thing that speaks for it — even
                    // if the location was originally typed in by hand.
                    Location::where('id', $change['location_id'])
                        ->update($change['payload'] + ['sheet_source_id' => $source->id]);
                }
            }
        });

        Log::info('Master sheet sync applied', [
            'sheet_source_id' => $source->id,
            'tab' => $source->tab_name,
            'event_id' => $source->event_id,
        ] + $this->summarise($plan));
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return array{created: int, updated: int, unchanged: int, skipped: int, missing: int}
     */
    protected function summarise(array $plan): array
    {
        $count = fn (string $action) => count(array_filter($plan, fn ($c) => $c['action'] === $action));

        return [
            'created' => $count(SheetSourceChange::ACTION_CREATE),
            'updated' => $count(SheetSourceChange::ACTION_UPDATE),
            'unchanged' => $count(SheetSourceChange::ACTION_UNCHANGED),
            'skipped' => $count(SheetSourceChange::ACTION_SKIPPED),
            'missing' => $count(SheetSourceChange::ACTION_MISSING),
        ];
    }
}
