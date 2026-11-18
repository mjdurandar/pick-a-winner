<?php

namespace App\Services;

use App\Models\Location;
use App\Models\SheetSource;
use App\Models\SheetSourceChange;
use App\Support\LocationData;
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
                ." rows of '{$source->tab_name}'. A header row needs a location column"
                .' (Location or Show Location) and a date column (Date or Screening Start Date).'
                .' Check the tab is a screening schedule and not a summary or notes tab.'
            );
        }

        // Which rows the sheet has drawn a line through. Read separately because
        // it is formatting rather than a value, and narrowed to the three columns
        // that decide it — see GoogleSheetsApi::strikethrough().
        $struck = $this->sheets->strikethrough(
            $source->spreadsheet_id,
            $source,
            $this->mapper->identityColumns($header['columns'])
        );

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

        // The country the rows being read belong to. A tab with its own country
        // column never consults this; the USA workbook, which has none and runs
        // a CANADA block below its USA one, is why it is tracked at all. It
        // starts at whatever the tab's region implies, for the rows above the
        // first banner.
        $country = $source->defaultCountry();

        // Read the sheet first, plan second. Pairing up the sessions of a venue
        // that runs more than one a night needs to see all of its rows at once,
        // which a single pass cannot do.
        $parsed = [];

        foreach ($rows as $index => $row) {
            if ($index <= $header['index']) {
                continue;
            }

            // A1 rows are 1-based and the range starts at row 1.
            $sheetRow = $index + 1;

            $fields = $this->mapper->rawFields($row, $header['columns']);

            $isStruck = $this->mapper->isStruckThrough($fields, $header['columns'], $struck[$index] ?? []);

            if ($reason = $this->mapper->rejectionReason($fields, $isStruck)) {
                // Asked only of the rows that are not screenings, which is what
                // makes reading a banner safe: a promoter genuinely called
                // "Canada Fly Co - Banff" is a screening and never reaches here.
                if ($banner = $this->mapper->sectionCountry($row)) {
                    $country = $banner;
                }

                $parsed[] = [
                    'sheet_row' => $sheetRow,
                    'skip_reason' => $reason,
                    'fields' => $fields,
                    'attributes' => null,
                ];

                continue;
            }

            $parsed[] = [
                'sheet_row' => $sheetRow,
                'skip_reason' => null,
                'fields' => $fields,
                'attributes' => $this->mapper->toLocationAttributes($fields, $country),
            ];
        }

        $pairings = $this->pairSessions($parsed, $existing);

        // A session spoken for by one row has to be invisible to the others.
        // Without this the pairing is computed correctly and then ignored:
        // whichever row the sheet lists first still reaches the record through
        // the ordinary match and takes it.
        $reservedBy = [];
        foreach ($pairings as $forRow => $locationId) {
            $reservedBy[$locationId] = $forRow;
        }

        foreach ($parsed as $entry) {
            $sheetRow = $entry['sheet_row'];
            $fields = $entry['fields'];

            if ($entry['skip_reason'] !== null) {
                $plan[] = [
                    'sheet_row' => $sheetRow,
                    'action' => SheetSourceChange::ACTION_SKIPPED,
                    'location_id' => null,
                    'label' => $this->labelForSkipped($fields),
                    'payload' => null,
                    'diff' => null,
                    'skip_reason' => $entry['skip_reason'],
                ];

                continue;
            }

            $attributes = $entry['attributes'];
            $label = trim($attributes['name'].' — '.($attributes['date'] ?? ''), ' —');

            // A session paired by running order above, where the times alone
            // could not say which is which.
            $paired = isset($pairings[$sheetRow]) && ! isset($claimed[$pairings[$sheetRow]])
                ? $locations->firstWhere('id', $pairings[$sheetRow])
                : null;

            $offLimits = $claimed;
            foreach ($reservedBy as $locationId => $forRow) {
                if ($forRow !== $sheetRow) {
                    $offLimits[$locationId] = true;
                }
            }

            $match = $paired
                ?: $this->matchLocation($existing, $attributes['name'], $attributes, $offLimits)
                ?: $this->matchLocation($existing, $this->mapper->priorName($fields), $attributes, $offLimits);

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
        $dropped = $locations
            ->filter(fn (Location $l) => $l->sheet_source_id === $source->id && ! isset($claimed[$l->id]))
            ->values();

        if ($dropped->isEmpty()) {
            return [];
        }

        // A screening that holds sign-ups, attendees or prizes is never offered
        // for removal, however long the sheet has stopped naming it. Deleting it
        // destroys the attendees and prizes outright and detaches the sign-ups
        // from any screening, and a row disappearing from a hand-edited shared
        // spreadsheet is as often a cut-and-paste mid-edit as a cancellation.
        // It simply stays, and the tab leaves it alone.
        $held = LocationData::forEvent($source->event, $dropped->pluck('id')->all());

        return $dropped
            ->filter(fn (Location $l) => LocationData::isEmpty($held[$l->id] ?? []))
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
     * The name to try is passed in rather than read from the payload, because a
     * row is looked up under more than one: the name it composes now, and the
     * one it composed before placeholders were stripped. See priorName().
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
    /**
     * Pair up the sessions of a venue that runs more than one on a night.
     *
     * Name and date alone cannot tell two sessions apart, and the time only
     * helps while it is unchanged. When a venue moves both of its sessions
     * — 18:00 and 21:00 becoming 19:00 and 22:00 — nothing in the row says
     * which became which, and matching them one at a time either guesses
     * (swapping two live screenings, attendees and all) or gives up and
     * reports both as new.
     *
     * Running order settles it: a venue that shifts its night keeps the early
     * session early. Both sides are sorted by time and lined up, so the first
     * row takes the first session.
     *
     * Only genuinely ambiguous groups are paired here. A row whose time already
     * names a session is matched on that and left out of the ordering, and a
     * venue with one session a night never reaches this at all.
     *
     * @param  array<int, array{sheet_row: int, skip_reason: ?string, attributes: ?array}>  $parsed
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Location>>  $existing
     * @return array<int, int> location id, keyed by sheet row
     */
    protected function pairSessions(array $parsed, $existing): array
    {
        $groups = [];

        foreach ($parsed as $entry) {
            if ($entry['skip_reason'] !== null) {
                continue;
            }

            $name = $entry['attributes']['name'] ?? null;
            $date = $entry['attributes']['date'] ?? null;

            // TBA is not a night, so two TBA rows are not two sessions of one.
            if ($name === null || $date === null || $date === 'TBA') {
                continue;
            }

            $groups[$name."\0".$date][] = $entry;
        }

        $pairings = [];

        foreach ($groups as $key => $rowsInGroup) {
            if (count($rowsInGroup) < 2) {
                continue;
            }

            [$name, $date] = explode("\0", $key, 2);

            $candidates = ($existing[$name] ?? collect())->where('date', $date)->values();

            // One existing session is enough. Two rows for a venue that has one
            // record means a session is being added, and the row whose time
            // already matches has to claim it — otherwise whichever row the
            // sheet happens to list first takes the record, and the session
            // that was already there is rebuilt empty beside it.
            if ($candidates->isEmpty()) {
                continue;
            }

            // Rows whose time still names a session are not in doubt.
            $taken = [];
            $undecided = [];

            foreach ($rowsInGroup as $entry) {
                $time = $entry['attributes']['time'] ?? null;
                $hit = $candidates->first(fn (Location $l) => $l->time === $time && ! isset($taken[$l->id]));

                if ($hit) {
                    $taken[$hit->id] = true;
                    $pairings[$entry['sheet_row']] = $hit->id;
                } else {
                    $undecided[] = $entry;
                }
            }

            $free = $candidates->reject(fn (Location $l) => isset($taken[$l->id]))->values();

            if ($undecided === [] || $free->isEmpty()) {
                continue;
            }

            usort($undecided, fn ($a, $b) => strcmp(
                (string) ($a['attributes']['time'] ?? ''),
                (string) ($b['attributes']['time'] ?? ''),
            ));

            $free = $free->sortBy(fn (Location $l) => (string) $l->time)->values();

            // Any extra rows have no session to pair with and become new
            // screenings; any extra sessions are reported as dropped.
            foreach ($undecided as $i => $entry) {
                if (! isset($free[$i])) {
                    break;
                }

                $pairings[$entry['sheet_row']] = $free[$i]->id;
            }
        }

        return $pairings;
    }

    protected function matchLocation($existing, ?string $name, array $attributes, array $claimed): ?Location
    {
        if ($name === null) {
            return null;
        }

        $candidates = ($existing[$name] ?? collect())
            ->reject(fn (Location $l) => isset($claimed[$l->id]));

        if ($candidates->isEmpty()) {
            return null;
        }

        $date = $attributes['date'] ?? null;
        $time = $attributes['time'] ?? null;

        // Date and time together. A venue often runs two sessions in one night,
        // and they are the same name on the same date — the time is the only
        // thing that tells them apart.
        if ($exact = $candidates->first(fn (Location $l) => $l->date === $date && $l->time === $time)) {
            return $exact;
        }

        $onDate = $candidates->where('date', $date);

        // One screening that night, at a different time: the session moved.
        if ($onDate->count() === 1) {
            return $onDate->first();
        }

        // Several that night and none at this time. Which one moved is a guess,
        // and guessing swaps the times of two live screenings — taking their
        // attendees and prizes with them. Reported as a new screening instead,
        // where the duplicate hint puts it in front of an admin.
        if ($onDate->count() > 1) {
            return null;
        }

        // Nothing on this date. A single candidate elsewhere is that screening
        // having moved date.
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // A screening imported while its date was still TBA is the one that has
        // been waiting for exactly this — matching it fills the date in rather
        // than standing up a second location beside it and reporting the first
        // as dropped. A candidate with a real date that simply moved is left
        // alone: which of several it moved from is a guess.
        return $candidates->firstWhere('date', 'TBA');
    }

    /**
     * field => [before, after] for every attribute the sheet would change.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    /**
     * What a sheet row would change about a location.
     *
     * Public so the review screen can rebind a row to a location an admin has
     * recognised as the same screening, and show what that would change.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function diffFor(Location $location, array $attributes): array
    {
        return $this->diff($location, $attributes);
    }

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
