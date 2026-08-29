<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleOAuthException;
use App\Exceptions\GoogleSheetsException;
use App\Jobs\SyncSheetSourceJob;
use App\Models\Events;
use App\Models\GoogleConnection;
use App\Models\Location;
use App\Models\Prize;
use App\Models\SheetSource;
use App\Models\SheetSourceChange;
use App\Models\TicketAttendee;
use App\Services\GoogleOAuth;
use App\Services\GoogleSheetsApi;
use App\Services\SheetSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin screen behind the master sheet: which Google account is connected,
 * which tabs feed which events, and what the next run would do.
 *
 * Everything here reads from Google. There is no code path in this controller,
 * or in the services it calls, that writes to a spreadsheet — the connection's
 * scope is spreadsheets.readonly and the Sheets API would reject a write.
 */
class SheetSyncController extends Controller
{
    public function __construct(
        protected GoogleOAuth $oauth,
        protected GoogleSheetsApi $sheets,
        protected SheetSyncService $sync,
    ) {}

    public function index(): Response
    {
        $connection = GoogleConnection::with('connectedBy')
            ->where('purpose', GoogleConnection::PURPOSE_SHEETS)
            ->first();

        return Inertia::render('MasterSheetSync', [
            'configured' => $this->oauth->isConfigured(),
            'connection' => $connection ? [
                'email' => $connection->google_account_email,
                'status' => $connection->status,
                'connected_at' => $connection->connected_at?->toIso8601String(),
                'connected_by' => $connection->connectedBy?->name,
            ] : null,
            'sources' => $this->sourcePayload(),
            'events' => Events::with('film')
                ->orderByDesc('event_year')
                ->orderBy('event_name')
                ->get()
                ->map(fn (Events $e) => [
                    'id' => $e->id,
                    'label' => trim($e->event_name.' ('.$e->event_year.')'),
                    'film' => $e->film?->name ?? '',
                ]),
            'regions' => SheetSource::REGIONS,
        ]);
    }

    /**
     * The tab names in a spreadsheet, so the admin picks from a list instead of
     * typing a tab name that has to match character for character.
     */
    public function tabs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spreadsheet' => 'required|string',
        ]);

        $spreadsheetId = $this->spreadsheetId($validated['spreadsheet']);

        if ($spreadsheetId === null) {
            return response()->json([
                'message' => 'That does not look like a Google Sheets link or id. Paste the whole URL from the browser.',
            ], 422);
        }

        try {
            $metadata = $this->sheets->metadata($spreadsheetId);
        } catch (GoogleSheetsException|GoogleOAuthException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Loading the picker is also the moment a renamed spreadsheet gets its
        // stored label corrected, since the title is right here for free.
        SheetSource::where('spreadsheet_id', $spreadsheetId)
            ->update(['spreadsheet_title' => $metadata['title']]);

        // Tabs already configured cannot be added twice — the table has a unique
        // key on them, so showing them as available would only produce an error.
        $taken = SheetSource::where('spreadsheet_id', $spreadsheetId)->pluck('tab_name')->all();

        return response()->json([
            'spreadsheet_id' => $spreadsheetId,
            'title' => $metadata['title'],
            'tabs' => array_values(array_map(
                fn (string $tab) => ['name' => $tab, 'taken' => in_array($tab, $taken, true)],
                $metadata['tabs']
            )),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'spreadsheet' => 'required|string',
            'tab_name' => 'required|string|max:255',
            'event_id' => 'required|exists:events,id',
            'region' => 'nullable|in:'.implode(',', array_keys(SheetSource::REGIONS)),
            'spreadsheet_title' => 'nullable|string|max:255',
        ]);

        $spreadsheetId = $this->spreadsheetId($validated['spreadsheet']);

        if ($spreadsheetId === null) {
            return back()->with('error', 'That does not look like a Google Sheets link or id.');
        }

        $exists = SheetSource::where('spreadsheet_id', $spreadsheetId)
            ->where('tab_name', $validated['tab_name'])
            ->exists();

        if ($exists) {
            return back()->with('error', 'That tab is already connected.');
        }

        $source = SheetSource::create([
            'spreadsheet_id' => $spreadsheetId,
            'tab_name' => $validated['tab_name'],
            'spreadsheet_title' => $validated['spreadsheet_title'] ?? null,
            'region' => $validated['region'] ?? null,
            'event_id' => $validated['event_id'],
            'enabled' => true,
        ]);

        // Preview straight away. A tab is only worth keeping if its header was
        // found, and the admin is right here to see it if it was not.
        SyncSheetSourceJob::dispatchSync($source->id);

        return back()->with('success', "Connected '{$source->tab_name}'. Review what it would do, then approve to apply it.");
    }

    public function update(Request $request, SheetSource $sheetSource): RedirectResponse
    {
        $validated = $request->validate([
            'event_id' => 'sometimes|required|exists:events,id',
            'region' => 'sometimes|nullable|in:'.implode(',', array_keys(SheetSource::REGIONS)),
            'enabled' => 'sometimes|boolean',
        ]);

        // Repointing a tab at a different event invalidates the parked batch: the
        // same rows now match against a different set of locations, so every diff
        // in it is about the wrong event.
        if (array_key_exists('event_id', $validated) && (int) $validated['event_id'] !== $sheetSource->event_id) {
            SheetSourceChange::where('sheet_source_id', $sheetSource->id)->delete();
        }

        $sheetSource->update($validated);

        return back()->with('success', 'Source updated.');
    }

    public function destroy(SheetSource $sheetSource): RedirectResponse
    {
        $tab = $sheetSource->tab_name;

        // Only the mapping goes. Locations this source created stay — they have
        // attendees and sign-ups hanging off them, and disconnecting a tab is not
        // a statement that its screenings never happened.
        $sheetSource->delete();

        return back()->with('success', "Disconnected '{$tab}'. The locations it created were kept.");
    }

    /**
     * Re-read the tab now. Inline rather than queued — the admin is watching, and
     * a tab is a single API read.
     */
    public function run(SheetSource $sheetSource): RedirectResponse
    {
        // The job skips a paused source, which would leave this reporting a
        // success that never happened.
        if (! $sheetSource->enabled) {
            return back()->with('error', "'{$sheetSource->tab_name}' is paused. Resume it before running a sync.");
        }

        SyncSheetSourceJob::dispatchSync($sheetSource->id);

        $sheetSource->refresh();

        if ($sheetSource->last_status === SheetSource::STATUS_FAILED) {
            return back()->with('error', $sheetSource->last_error);
        }

        $pending = $sheetSource->pendingChangeCount();

        return back()->with('success', $pending > 0
            ? "'{$sheetSource->tab_name}' has {$pending} change".($pending === 1 ? '' : 's').' to review. Nothing has been written yet.'
            : "'{$sheetSource->tab_name}' is up to date — no changes found.");
    }

    /**
     * What the stored plan says this tab would do, for the review panel.
     */
    public function changes(Request $request, SheetSource $sheetSource): JsonResponse
    {
        $action = $request->query('action');

        $changes = SheetSourceChange::where('sheet_source_id', $sheetSource->id)
            ->when(in_array($action, [
                SheetSourceChange::ACTION_CREATE,
                SheetSourceChange::ACTION_UPDATE,
                SheetSourceChange::ACTION_UNCHANGED,
                SheetSourceChange::ACTION_SKIPPED,
                SheetSourceChange::ACTION_MISSING,
            ], true), fn ($q) => $q->where('action', $action))
            ->orderBy('sheet_row')
            ->get();

        // What deleting each vanished location would take with it. Counted here
        // rather than stored on the change, because attendees keep signing up
        // after the plan was parked and a stale number is the one number that
        // must not be wrong on a delete confirmation.
        $cost = $this->deletionCost($changes);

        // Creates that look like a location the event already has, so a name the
        // sheet spells slightly differently is caught before it becomes a second
        // copy of a screening that already has sign-ups pointing at it.
        $hints = $this->duplicateHints($sheetSource, $changes->where('action', SheetSourceChange::ACTION_CREATE));

        $changes = $changes
            ->map(fn (SheetSourceChange $c) => [
                'id' => $c->id,
                'sheet_row' => $c->sheet_row,
                'action' => $c->action,
                'label' => $c->label,
                'location_id' => $c->location_id,
                'diff' => $c->diff,
                'payload' => $c->payload,
                'skip_reason' => $c->skip_reason,
                'attendees' => $cost[$c->location_id]['attendees'] ?? 0,
                'prizes' => $cost[$c->location_id]['prizes'] ?? 0,
                'duplicate_of' => $hints[$c->id] ?? null,
            ]);

        return response()->json([
            'changes' => $changes,
            'counts' => $this->counts($sheetSource),
        ]);
    }

    /**
     * Apply the batch the admin just reviewed, then re-read so the screen shows
     * the tab settled rather than still advertising changes it has now made.
     */
    public function approve(Request $request, SheetSource $sheetSource): RedirectResponse
    {
        if ($sheetSource->pendingChangeCount() === 0) {
            return back()->with('error', $sheetSource->missingCount() > 0
                ? "'{$sheetSource->tab_name}' has nothing to apply — the only thing waiting is screenings removed from the sheet, which you handle one at a time."
                : "'{$sheetSource->tab_name}' has no changes waiting.");
        }

        try {
            $applied = $this->sync->applyStored($sheetSource);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Master sheet approval failed', [
                'sheet_source_id' => $sheetSource->id,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', 'Applying those changes failed, so nothing was written. The error has been logged.');
        }

        $sheetSource->recordApproval($request->user()?->id);

        // Re-read so the parked batch reflects reality again. A failure here is not
        // worth alarming anyone about — the changes did land, and the next
        // scheduled run will correct the display.
        try {
            $result = $this->sync->refresh($sheetSource);
            $sheetSource->recordSuccess($result['created'], $result['updated'], $result['skipped'], true);
        } catch (GoogleSheetsException|GoogleOAuthException $e) {
            // Left as-is; the counts on screen are simply one run stale.
        }

        return back()->with('success', sprintf(
            "Applied to '%s' — %d created, %d updated.",
            $sheetSource->tab_name,
            $applied['created'],
            $applied['updated']
        ));
    }

    /**
     * Throw the parked batch away without applying it. The next run will find the
     * same differences again, so this is "not now", not "never".
     */
    public function discard(SheetSource $sheetSource): RedirectResponse
    {
        SheetSourceChange::where('sheet_source_id', $sheetSource->id)->delete();

        return back()->with('success', "Dismissed the pending changes for '{$sheetSource->tab_name}'. The next run will pick them up again.");
    }

    /**
     * Delete a location whose row has gone from the sheet.
     *
     * The one destructive path in this feature, and deliberately the only one an
     * automated run can never reach: locations cascade to their ticket attendees
     * and prizes, so the count is re-read here and a location carrying either has
     * to be confirmed by typing DELETE. The sync itself never calls this.
     */
    public function removeLocation(Request $request, SheetSource $sheetSource, SheetSourceChange $sheetSourceChange): RedirectResponse
    {
        if ($sheetSourceChange->sheet_source_id !== $sheetSource->id
            || $sheetSourceChange->action !== SheetSourceChange::ACTION_MISSING) {
            return back()->with('error', 'That row is not a screening removed from the sheet.');
        }

        $location = Location::find($sheetSourceChange->location_id);

        if (! $location) {
            // Already gone — clear the row so it stops being offered.
            $sheetSourceChange->delete();

            return back()->with('success', 'That location had already been removed.');
        }

        $attendees = TicketAttendee::where('location_id', $location->id)->count();
        $prizes = Prize::where('location_id', $location->id)->count();

        // Counted again on the server: the number the admin saw was read when the
        // panel opened, and a sign-up in the meantime must not be destroyed by a
        // confirmation given for a smaller number.
        if (($attendees > 0 || $prizes > 0) && $request->input('confirmation') !== 'DELETE') {
            return back()->with('error', sprintf(
                "'%s' has %d attendee%s and %d prize%s. Type DELETE to confirm removing it.",
                $location->name,
                $attendees,
                $attendees === 1 ? '' : 's',
                $prizes,
                $prizes === 1 ? '' : 's'
            ));
        }

        $name = $location->name;

        \Illuminate\Support\Facades\Log::warning('Master sheet location removed', [
            'sheet_source_id' => $sheetSource->id,
            'location_id' => $location->id,
            'name' => $name,
            'attendees_destroyed' => $attendees,
            'prizes_destroyed' => $prizes,
            'user_id' => $request->user()?->id,
        ]);

        $location->delete();
        $sheetSourceChange->delete();

        return back()->with('success', $attendees > 0
            ? "Removed '{$name}' and its {$attendees} attendee record".($attendees === 1 ? '' : 's').'.'
            : "Removed '{$name}'.");
    }

    /**
     * Keep a location whose row has gone from the sheet.
     *
     * Detaches it from the tab rather than storing a dismissal flag. The batch is
     * rewritten by every run, so a flag would have to be re-matched each time;
     * clearing the provenance says the true thing instead — the sheet no longer
     * speaks for this screening, so no future run should ask about it again.
     */
    public function keepLocation(SheetSource $sheetSource, SheetSourceChange $sheetSourceChange): RedirectResponse
    {
        if ($sheetSourceChange->sheet_source_id !== $sheetSource->id
            || $sheetSourceChange->action !== SheetSourceChange::ACTION_MISSING) {
            return back()->with('error', 'That row is not a screening removed from the sheet.');
        }

        $location = Location::find($sheetSourceChange->location_id);

        if ($location) {
            $location->forceFill(['sheet_source_id' => null])->save();
        }

        $sheetSourceChange->delete();

        return back()->with('success', $location
            ? "Kept '{$location->name}'. It is no longer managed by '{$sheetSource->tab_name}'."
            : 'That location no longer exists.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sourcePayload(): array
    {
        return SheetSource::with(['event', 'approvedBy'])
            ->orderBy('id')
            ->get()
            ->map(fn (SheetSource $s) => [
                'id' => $s->id,
                'spreadsheet_id' => $s->spreadsheet_id,
                'spreadsheet_title' => $s->spreadsheet_title,
                'url' => $s->url(),
                'tab_name' => $s->tab_name,
                'region' => $s->region,
                'event_id' => $s->event_id,
                'event_name' => $s->event?->event_name,
                'enabled' => $s->enabled,
                'needs_review' => $s->needsReview(),
                'pending' => $s->pendingChangeCount(),
                'missing' => $s->missingCount(),
                'duplicates' => count($this->duplicateHints(
                    $s,
                    SheetSourceChange::where('sheet_source_id', $s->id)
                        ->where('action', SheetSourceChange::ACTION_CREATE)
                        ->get()
                )),
                'approved_at' => $s->approved_at?->toIso8601String(),
                'approved_by' => $s->approvedBy?->name,
                'last_synced_at' => $s->last_synced_at?->toIso8601String(),
                'last_status' => $s->last_status,
                'last_error' => $s->last_error,
                'counts' => $this->counts($s),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    protected function counts(SheetSource $source): array
    {
        $counts = SheetSourceChange::where('sheet_source_id', $source->id)
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        return [
            'create' => (int) ($counts[SheetSourceChange::ACTION_CREATE] ?? 0),
            'update' => (int) ($counts[SheetSourceChange::ACTION_UPDATE] ?? 0),
            'unchanged' => (int) ($counts[SheetSourceChange::ACTION_UNCHANGED] ?? 0),
            'skipped' => (int) ($counts[SheetSourceChange::ACTION_SKIPPED] ?? 0),
            'missing' => (int) ($counts[SheetSourceChange::ACTION_MISSING] ?? 0),
        ];
    }

    /**
     * Creates that are probably a location the event already holds.
     *
     * The sync matches on the composed name exactly, which is correct — it is the
     * same identity the paste-a-grid save writes, and loosening it would let two
     * genuinely different screenings collapse into one. But an existing location
     * typed in by hand months ago may spell the same cinema with different
     * spacing, punctuation or case, and would then be imported a second time
     * rather than updated.
     *
     * So the strictness stays and the near-misses are surfaced instead: an admin
     * can see "this create looks like that location" and fix the spelling in one
     * place or the other before approving.
     *
     * @param  \Illuminate\Support\Collection<int, SheetSourceChange>  $creates
     * @return array<int, array{id: int, name: string, date: string|null}> keyed by change id
     */
    protected function duplicateHints(SheetSource $source, $creates): array
    {
        if ($creates->isEmpty()) {
            return [];
        }

        $existing = Location::where('event_id', $source->event_id)->get(['id', 'name', 'date']);

        if ($existing->isEmpty()) {
            return [];
        }

        $normalised = $existing->map(fn (Location $l) => [
            'location' => $l,
            'key' => $this->comparableName($l->name),
        ]);

        $hints = [];

        foreach ($creates as $change) {
            $name = $change->payload['name'] ?? $change->label;
            $key = $this->comparableName($name);

            if ($key === '') {
                continue;
            }

            $match = $normalised->first(function (array $candidate) use ($key, $change) {
                // Same name once case, spacing and punctuation are set aside.
                if ($candidate['key'] === $key) {
                    return true;
                }

                // Or the same night at a venue spelled almost the same way. The
                // date has to agree for this one — without it, two different
                // screenings at the same cinema would look like duplicates.
                if (($change->payload['date'] ?? null) !== ($candidate['location']->date ?? null)) {
                    return false;
                }

                similar_text($candidate['key'], $key, $percent);

                return $percent >= 85;
            });

            if ($match) {
                $hints[$change->id] = [
                    'id' => $match['location']->id,
                    'name' => $match['location']->name,
                    'date' => $match['location']->date,
                ];
            }
        }

        return $hints;
    }

    /** A name reduced to what actually identifies it, for near-match comparison. */
    protected function comparableName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $name));
    }

    /**
     * attendee and prize counts per location, for the rows a delete would touch.
     *
     * @param  \Illuminate\Support\Collection<int, SheetSourceChange>  $changes
     * @return array<int, array{attendees: int, prizes: int}>
     */
    protected function deletionCost($changes): array
    {
        $ids = $changes
            ->where('action', SheetSourceChange::ACTION_MISSING)
            ->pluck('location_id')
            ->filter()
            ->all();

        if ($ids === []) {
            return [];
        }

        $attendees = TicketAttendee::whereIn('location_id', $ids)
            ->selectRaw('location_id, COUNT(*) as total')
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        $prizes = Prize::whereIn('location_id', $ids)
            ->selectRaw('location_id, COUNT(*) as total')
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        $cost = [];

        foreach ($ids as $id) {
            $cost[$id] = [
                'attendees' => (int) ($attendees[$id] ?? 0),
                'prizes' => (int) ($prizes[$id] ?? 0),
            ];
        }

        return $cost;
    }

    /**
     * Pull the id out of whatever the admin pasted.
     *
     * They will paste the browser URL, which carries the id between /d/ and /edit
     * along with a #gid fragment. A bare id is accepted too.
     */
    protected function spreadsheetId(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $input, $matches)) {
            return $matches[1];
        }

        // Google's ids are long random strings; anything short is a mistake.
        return preg_match('/^[a-zA-Z0-9-_]{20,}$/', $input) ? $input : null;
    }
}
