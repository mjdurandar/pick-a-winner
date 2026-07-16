<?php

namespace App\Services;

use App\Models\Events;
use App\Models\MailchimpImportLog;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Picks the locations that are eligible for a Mailchimp auto-import.
 *
 * Shared by the daily scheduled command and the manual "run finished screenings now"
 * button so the two can never drift on what "finished and not yet imported" means.
 *
 * @see \App\Console\Commands\AutoImportFinishedLocations
 * @see \App\Http\Controllers\MailchimpImportLogsController::runAutoImportNow
 */
class FinishedLocationSelector
{
    /** @var array<int, SignUpForm|null> Sign-up form per event id; one lookup per event, not per location. */
    private array $signUpFormCache = [];

    /**
     * Events opted in to the daily scheduled import: toggle on AND a destination audience set.
     */
    public function optedInEvents()
    {
        return Events::where('auto_import_enabled', true)
            ->whereNotNull('auto_import_list_id')
            ->where('auto_import_list_id', '!=', '')
            ->whereNotNull('auto_import_account')
            ->where('auto_import_account', '!=', '')
            ->with('locations')
            ->get();
    }

    /**
     * Build the import work-list for a set of events.
     *
     * @param  iterable<Events>  $events  each must have `locations` loaded
     * @param  int  $days  a location is eligible once its screening date is this many days past
     * @param  array{list_id: string, list_name: ?string, account: string}|null  $audience
     *                                                                                      Destination override. Null = use each event's own auto_import_* config (the scheduled
     *                                                                                      run). Set = import every given event into this one operator-picked audience, which
     *                                                                                      is what the manual button does for events that were never opted in.
     * @return array<int, array{event_id: int, event_name: ?string, location_id: int, location_name: string, list_id: string, list_name: ?string, account: string}>
     */
    public function select(iterable $events, int $days, ?array $audience = null): array
    {
        $cutoff = Carbon::now()->startOfDay()->subDays(max(0, $days));
        $items = [];

        foreach ($events as $event) {
            $listId = (string) ($audience['list_id'] ?? $event->auto_import_list_id);
            $listName = $audience['list_name'] ?? $event->auto_import_list_name;
            $account = (string) ($audience['account'] ?? $event->auto_import_account);

            if ($listId === '' || $account === '') {
                continue;
            }

            foreach ($event->locations as $location) {
                if (! $this->isFinished($location->date, $cutoff)) {
                    continue;
                }

                if ($this->alreadyImported($location->id, $listId, $account)) {
                    continue;
                }

                if (! $this->hasImportableData($event, $location->id)) {
                    continue;
                }

                $items[] = [
                    'event_id' => (int) $event->id,
                    // Carried on the item so every run detail can name its event — including the
                    // skipped/error branches, which have no Location row to read it back from.
                    'event_name' => $event->event_name,
                    'location_id' => (int) $location->id,
                    'location_name' => (string) $location->name,
                    'list_id' => $listId,
                    'list_name' => $listName,
                    'account' => $account,
                ];
            }
        }

        return $items;
    }

    /**
     * Already landed in this audience? Keyed on the (location, list, account) triple, so the
     * same location can still be imported into a different audience.
     *
     * A prior run that fully errored (0 new + 0 updated) does not count as imported, so it retries.
     */
    public function alreadyImported(int $locationId, string $listId, string $account): bool
    {
        return (int) MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $listId)
            ->where('mailchimp_account', $account)
            ->sum(DB::raw('new_contacts + updated_data')) > 0;
    }

    /**
     * A location is "finished" when its screening date parses and is on/before the cutoff.
     * `locations.date` is free text, so it may be blank, 'TBA', or unparseable.
     */
    public function isFinished(?string $dateStr, Carbon $cutoff): bool
    {
        $dateStr = trim((string) $dateStr);
        if ($dateStr === '' || strtoupper($dateStr) === 'TBA') {
            return false;
        }
        try {
            $d = Carbon::parse($dateStr)->startOfDay();
        } catch (\Throwable $e) {
            return false;
        }

        return $d->lte($cutoff);
    }

    /**
     * True if the location has ticket attendees or sign-up-form rows to import.
     */
    public function hasImportableData(Events $event, int $locationId): bool
    {
        if (TicketAttendee::where('location_id', $locationId)->exists()) {
            return true;
        }

        $eventId = (int) $event->id;
        if (! array_key_exists($eventId, $this->signUpFormCache)) {
            $this->signUpFormCache[$eventId] = SignUpForm::where('event_id', $eventId)->first();
        }
        $signUpForm = $this->signUpFormCache[$eventId];

        if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
            return DB::table($signUpForm->table_name)
                ->where('location_id', $locationId)
                ->where('event_id', $eventId)
                ->whereNotNull('email_address')
                ->where('email_address', '!=', '')
                ->exists();
        }

        return false;
    }
}
