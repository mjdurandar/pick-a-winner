<?php

namespace App\Jobs;

use App\Models\Location;
use App\Models\MailchimpAutoImportRun;
use App\Models\MailchimpImportLog;
use App\Models\TicketAttendee;
use App\Services\AutoMailchimpService;
use App\Services\MailchimpLocationImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Imports a batch of "finished" locations to Mailchimp for the scheduled auto-import.
 * Reuses MailchimpLocationImportService (same path as the manual import) so logging and
 * per-line dedup behave identically, then aggregates a run summary into MailchimpAutoImportRun.
 *
 * @see \App\Console\Commands\AutoImportFinishedLocations for how eligible items are chosen.
 */
class AutoImportFinishedLocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generous timeout: batch imports poll the Mailchimp batch API per location. */
    public $timeout = 3600;

    public $tries = 1;

    /**
     * Ticket rows carry the venue's city/state (not the contact's home address), so we only
     * map name — mirroring the manual "Import All" ticket mode in LocationPage.vue.
     */
    private const TICKET_FIELD_MAPPING = [
        'FNAME' => 'first_name',
        'LNAME' => 'last_name',
    ];

    /**
     * Full field mapping for win/sign-up-form rows (they carry the contact's real address).
     * Mirrors the tagToDefault map in LocationPage.vue. SMSPHONE is intentionally omitted.
     */
    private const FORM_FIELD_MAPPING = [
        'FNAME' => 'first_name', 'LNAME' => 'last_name',
        'PHONE' => 'mobile_number', 'MERGE4' => 'mobile_number', 'MERGE30' => 'mobile_number',
        'ADDRESSWIN' => 'address_full', 'MMERGE10' => 'address_full', 'MERGE10' => 'address_full', 'MERGE11' => 'address_full',
        'SHOWCITY' => 'city', 'CITY' => 'city', 'MERGE3' => 'city', 'MERGE5' => 'city',
        'STATEWIN' => 'state', 'STATE' => 'state', 'MERGE6' => 'state',
        'ZIPCODEWIN' => 'zip_code', 'ZIPCODE' => 'zip_code', 'MERGE7' => 'zip_code',
        'COUNTRYWIN' => 'country', 'COUNTRY' => 'country', 'MERGE8' => 'country',
        'GENDER' => 'gender', 'MERGE17' => 'gender',
        'AGEWIN' => 'age', 'MERGE14' => 'age', 'MMERGE14' => 'age',
    ];

    /**
     * @param  int  $runId  MailchimpAutoImportRun id to write the summary into.
     * @param  array<int, array{event_id:int, location_id:int, location_name:string, list_id:string, list_name:?string, account:string}>  $items
     */
    public function __construct(
        public int $runId,
        public array $items
    ) {}

    public function handle(): void
    {
        $run = MailchimpAutoImportRun::find($this->runId);
        if (! $run) {
            Log::warning('Auto-import job: run record not found', ['run_id' => $this->runId]);

            return;
        }

        $service = new MailchimpLocationImportService;
        $tagBuilder = app(AutoMailchimpService::class);

        $details = [];
        $imported = 0;
        $skipped = 0;
        $totalNew = 0;
        $totalUpdated = 0;
        $totalErrors = 0;

        foreach ($this->items as $item) {
            $locationId = (int) $item['location_id'];
            $eventId = (int) $item['event_id'];
            $listId = (string) $item['list_id'];
            $account = (string) $item['account'];
            $listName = $item['list_name'] ?? null;

            $location = Location::with('event')->find($locationId);
            if (! $location) {
                $skipped++;
                $details[] = [
                    'location_id' => $locationId,
                    'location_name' => $item['location_name'] ?? (string) $locationId,
                    'event_id' => $eventId,
                    'status' => 'skipped',
                    'reason' => 'location not found',
                ];

                continue;
            }

            try {
                $attendees = TicketAttendee::where('location_id', $locationId)->get()->map(function ($a) {
                    return [
                        'email' => $a->email,
                        'first_name' => $a->first_name ?? '',
                        'last_name' => $a->last_name ?? '',
                        'phone' => $a->phone ?? '',
                        'city' => $a->city ?? '',
                        'state' => $a->state ?? '',
                        'country' => $a->country ?? '',
                    ];
                })->all();

                // Ticket and form run as separate imports because they need different field mappings
                // (ticket = name only; form = full address). Each writes its own per-source log line.
                $ticketResult = ['imported' => 0, 'skipped' => true];
                if (! empty($attendees)) {
                    $ticketResult = $service->runForOneLocation($eventId, $listId, $account, $listName, [
                        'location_id' => $locationId,
                        'import_ticket' => true,
                        'import_form' => false,
                        'attendees' => $attendees,
                        'tags' => $tagBuilder->buildLocationTags($location, 'ticket'),
                        'form_tags' => [],
                    ], 0, self::TICKET_FIELD_MAPPING);
                }

                $formResult = $service->runForOneLocation($eventId, $listId, $account, $listName, [
                    'location_id' => $locationId,
                    'import_ticket' => false,
                    'import_form' => true,
                    'attendees' => [],
                    'tags' => [],
                    'form_tags' => $tagBuilder->buildLocationTags($location, 'form'),
                ], 0, self::FORM_FIELD_MAPPING);

                if ($ticketResult['skipped'] && $formResult['skipped']) {
                    $skipped++;
                    $details[] = [
                        'location_id' => $locationId,
                        'location_name' => $location->name,
                        'event_id' => $eventId,
                        'status' => 'skipped',
                        'reason' => 'no ticket or form data',
                    ];

                    continue;
                }

                // Read back the per-source log rows this import just wrote/updated for accurate counts.
                $logs = MailchimpImportLog::where('location_id', $locationId)
                    ->where('list_id', $listId)
                    ->where('mailchimp_account', $account)
                    ->whereIn('source', ['ticket_data', 'signup_form'])
                    ->get();

                $new = (int) $logs->sum('new_contacts');
                $updated = (int) $logs->sum('updated_data');
                $errors = (int) $logs->sum('data_with_error');

                $imported++;
                $totalNew += $new;
                $totalUpdated += $updated;
                $totalErrors += $errors;

                $details[] = [
                    'location_id' => $locationId,
                    'location_name' => $location->name,
                    'event_id' => $eventId,
                    'event_name' => $location->event->event_name ?? null,
                    'list_name' => $listName,
                    'account' => $account,
                    'status' => 'imported',
                    'new' => $new,
                    'updated' => $updated,
                    'errors' => $errors,
                    'sources' => $logs->pluck('source')->values()->all(),
                ];
            } catch (\Throwable $e) {
                $skipped++;
                $totalErrors++;
                $details[] = [
                    'location_id' => $locationId,
                    'location_name' => $location->name ?? (string) $locationId,
                    'event_id' => $eventId,
                    'status' => 'error',
                    'reason' => substr($e->getMessage(), 0, 300),
                ];
                Log::error('Auto-import job: location failed', [
                    'run_id' => $this->runId,
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ]);
            }

            $run->update([
                'locations_imported' => $imported,
                'locations_skipped' => $skipped,
                'total_new' => $totalNew,
                'total_updated' => $totalUpdated,
                'total_errors' => $totalErrors,
                'details' => $details,
            ]);
        }

        $run->update([
            'locations_imported' => $imported,
            'locations_skipped' => $skipped,
            'total_new' => $totalNew,
            'total_updated' => $totalUpdated,
            'total_errors' => $totalErrors,
            'details' => $details,
            'status' => 'completed',
        ]);

        Log::info('Auto-import job: completed', [
            'run_id' => $this->runId,
            'imported' => $imported,
            'skipped' => $skipped,
            'new' => $totalNew,
            'updated' => $totalUpdated,
            'errors' => $totalErrors,
        ]);
    }

    public function failed(?\Throwable $e = null): void
    {
        $run = MailchimpAutoImportRun::find($this->runId);
        if ($run) {
            $run->update([
                'status' => 'failed',
                'error' => $e ? substr($e->getMessage(), 0, 500) : 'unknown',
            ]);
        }
    }
}
