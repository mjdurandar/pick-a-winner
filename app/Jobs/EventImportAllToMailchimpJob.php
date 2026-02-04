<?php

namespace App\Jobs;

use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use App\Services\MailchimpLogService;
use App\Services\MailchimpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class EventImportAllToMailchimpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Job timeout in seconds; large imports need time to finish all locations. */
    public $timeout;

    /** Fail after one attempt so one failing job does not block the queue with retries. */
    public $tries = 1;

    public function __construct(
        public int $eventId,
        public string $listId,
        public string $mailchimpAccount,
        public ?string $listName,
        public array $locationsPayload,
        public ?int $userId,
        public bool $skipAlreadyImported = false,
        public ?string $importBatchId = null,
        public ?array $fieldMapping = null
    ) {
        // Allow production to run until all locations are imported (default 2 hours).
        $this->timeout = config('queue.mailchimp_import_job_timeout', 7200);
        if ($this->timeout < 60) {
            $this->timeout = 7200;
        }
    }

    public function handle(MailchimpLogService $logService): void
    {
        try {
            $mailchimpService = new MailchimpService($this->mailchimpAccount);
        } catch (\Exception $e) {
            Log::error('Event import all (job): MailchimpService init failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        $locationsToProcess = $this->locationsPayload;
        if ($this->skipAlreadyImported) {
            $alreadyImportedLocationIds = MailchimpImportLog::where('list_id', $this->listId)
                ->where('mailchimp_account', $this->mailchimpAccount)
                ->distinct()
                ->pluck('location_id')
                ->flip();
            $locationsToProcess = array_values(array_filter($this->locationsPayload, function ($loc) use ($alreadyImportedLocationIds) {
                return !$alreadyImportedLocationIds->has((int) ($loc['location_id'] ?? 0));
            }));
        }

        $locationsQueued = count($locationsToProcess);
        $locationsImported = 0;
        $locationsFailed = 0;
        $locationsSkipped = 0;
        $failureReasons = [];
        $locationIndex = 0;

        Log::info('Event import all (job) started', [
            'event_id' => $this->eventId,
            'locations_queued' => $locationsQueued,
            'locations_total_before_skip' => count($this->locationsPayload),
            'skip_already_imported' => $this->skipAlreadyImported,
        ]);

        foreach ($locationsToProcess as $loc) {
            $locationIndex++;

            if ($this->importBatchId && Cache::get('cancel_import_batch_' . $this->importBatchId)) {
                Log::info('Event import all (job): cancelled by user', ['import_batch_id' => $this->importBatchId]);
                break;
            }

            $locationId = (int) ($loc['location_id'] ?? 0);
            $attendees = $loc['attendees'] ?? [];
            $tags = $loc['tags'] ?? [];
            $formTags = $loc['form_tags'] ?? [];

            try {
                $location = Location::with('event')->find($locationId);
                if (!$location || (int) $location->event_id !== $this->eventId) {
                    $locationsSkipped++;
                    continue;
                }

                // Skip locations with no data: no Mailchimp API calls, no MC logs (optimization)
                $hasTicketData = ! empty($attendees) && ! empty($tags);
                $hasFormData = false;
                if (! empty($formTags)) {
                    $signUpForm = SignUpForm::where('event_id', $this->eventId)->first();
                    if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                        $hasFormData = DB::table($signUpForm->table_name)
                            ->where('location_id', $locationId)
                            ->where('event_id', $this->eventId)
                            ->whereNotNull('email_address')
                            ->where('email_address', '!=', '')
                            ->exists();
                    }
                }
                if (! $hasTicketData && ! $hasFormData) {
                    $locationsSkipped++;
                    continue;
                }

                // --- Ticket data import ---
                if ($hasTicketData) {
                    $this->importTicketData($mailchimpService, $logService, $location, $attendees, $tags);
                }

                // --- Win form (sign-up) data import ---
                if ($hasFormData) {
                    $this->importFormData($mailchimpService, $logService, $location, $formTags);
                }

                $locationsImported++;
            } catch (\Throwable $e) {
                $locationsFailed++;
                $reason = substr($e->getMessage(), 0, 200);
                if (! isset($failureReasons[$reason])) {
                    $failureReasons[$reason] = ['count' => 0, 'location_ids' => []];
                }
                $failureReasons[$reason]['count']++;
                $failureReasons[$reason]['location_ids'][] = $locationId;
                if (count($failureReasons[$reason]['location_ids']) > 5) {
                    $failureReasons[$reason]['location_ids'] = array_slice($failureReasons[$reason]['location_ids'], 0, 5);
                }
                // One error must not stop the rest: continue to next location (no rethrow).
            }

            // Progress log every 5 locations so you can see the job is still running
            if ($locationIndex % 5 === 0 || $locationIndex === $locationsQueued) {
                Log::info('Event import all (job) progress', [
                    'event_id' => $this->eventId,
                    'processed' => $locationIndex,
                    'total' => $locationsQueued,
                    'imported_so_far' => $locationsImported,
                    'failed_so_far' => $locationsFailed,
                ]);
            }
        }

        $reasonsSummary = [];
        foreach ($failureReasons as $msg => $info) {
            $reasonsSummary[] = $msg . ' (locations: ' . implode(', ', $info['location_ids']) . ', count: ' . $info['count'] . ')';
        }

        Log::info('Event import all (job) completed', [
            'event_id' => $this->eventId,
            'locations_queued' => $locationsQueued,
            'locations_imported' => $locationsImported,
            'locations_failed' => $locationsFailed,
            'locations_skipped' => $locationsSkipped,
            'failure_reasons' => array_slice($reasonsSummary, 0, 20),
        ]);
    }

    private function importTicketData(
        MailchimpService $mailchimpService,
        MailchimpLogService $logService,
        Location $location,
        array $attendees,
        array $tags
    ): void {
        $locationId = $location->id;

        TicketAttendee::where('location_id', $locationId)->delete();
        $seenEmails = [];
        foreach ($attendees as $a) {
            $email = strtolower(trim($a['email'] ?? ''));
            if ($email === '' || isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;
            TicketAttendee::create([
                'location_id' => $locationId,
                'event_id' => $location->event_id,
                'email' => $email,
                'first_name' => $a['first_name'] ?? '',
                'last_name' => $a['last_name'] ?? '',
                'phone' => $a['phone'] ?? $a['mobile_number'] ?? '',
                'city' => $a['city'] ?? '',
                'state' => $a['state'] ?? '',
                'country' => $a['country'] ?? '',
            ]);
        }

        $subscribers = [];
        foreach ($attendees as $a) {
            $email = strtolower(trim($a['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $city = trim($a['city'] ?? '');
            $state = trim($a['state'] ?? '');
            $country = trim($a['country'] ?? '');
            $addrParts = array_filter([$city, $state, $country]);
            $subscribers[] = [
                'email_address' => $email,
                'first_name' => $a['first_name'] ?? '',
                'last_name' => $a['last_name'] ?? '',
                'mobile_number' => $a['phone'] ?? $a['mobile_number'] ?? '',
                'phone' => $a['phone'] ?? $a['mobile_number'] ?? '',
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'address_full' => implode(', ', $addrParts),
            ];
        }

        $locSuccess = 0;
        $locFailed = 0;
        $locNew = 0;
        $locUpdated = 0;
        $locErrors = [];
        $failedRowsData = [];
        $listId = $this->listId;
        $maxFailedRowsStored = 100;

        foreach ($subscribers as $subscriber) {
            try {
                if (empty($subscriber['email_address'])) {
                    $locFailed++;
                    $locErrors[] = 'Skipped: Missing email';
                    if (count($failedRowsData) < $maxFailedRowsStored) {
                        $failedRowsData[] = [
                            'email_address' => '',
                            'first_name' => $subscriber['first_name'] ?? '',
                            'last_name' => $subscriber['last_name'] ?? '',
                            'mobile_number' => $subscriber['mobile_number'] ?? '',
                            'error_message' => 'Skipped: Missing email',
                        ];
                    }
                    continue;
                }
                $result = $mailchimpService->manualImportSubscriber(
                    $listId,
                    $subscriber,
                    $tags,
                    $this->fieldMapping
                );
                $locSuccess++;
                if (isset($result['import_type'])) {
                    if ($result['import_type'] === 'new') {
                        $locNew++;
                    } elseif ($result['import_type'] === 'updated') {
                        $locUpdated++;
                    }
                }
                try {
                    $logService->logImport($location->id, $location->name, [
                        'success' => true,
                        'email' => $subscriber['email_address'],
                        'tags' => $tags,
                        'source' => 'eventbrite',
                    ]);
                } catch (\Throwable $e) {
                    // Logging failure must not abort the import; continue to next subscriber.
                }
            } catch (\Throwable $e) {
                $locFailed++;
                $errMsg = substr("{$subscriber['email_address']}: " . $e->getMessage(), 0, 200);
                $locErrors[] = $errMsg;
                if (count($failedRowsData) < $maxFailedRowsStored) {
                    $failedRowsData[] = [
                        'email_address' => $subscriber['email_address'] ?? '',
                        'first_name' => $subscriber['first_name'] ?? '',
                        'last_name' => $subscriber['last_name'] ?? '',
                        'mobile_number' => $subscriber['mobile_number'] ?? '',
                        'error_message' => $e->getMessage(),
                    ];
                }
                try {
                    $logService->logImport($location->id, $location->name, [
                        'success' => false,
                        'email' => $subscriber['email_address'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'tags' => $tags,
                        'source' => 'eventbrite',
                    ]);
                } catch (\Throwable $e2) {
                    // Do not let logging failure abort the import.
                }
            }
        }

        $hadPreviousImport = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $this->listId)
            ->where('mailchimp_account', $this->mailchimpAccount)
            ->exists();

        $ticketLog = MailchimpImportLog::create([
            'location_id' => $locationId,
            'imported_by' => $this->userId,
            'total_data' => count($subscribers),
            'new_contacts' => $locNew,
            'updated_data' => $locUpdated,
            'data_with_error' => $locFailed,
            'errors' => array_slice($locErrors, 0, 50),
            'failed_rows' => array_slice($failedRowsData, 0, $maxFailedRowsStored),
            'tags' => $tags,
            'source' => 'ticket_data',
            'mailchimp_account' => $this->mailchimpAccount,
            'list_id' => $this->listId,
            'list_name' => $this->listName,
            'status' => $hadPreviousImport ? 'reimport' : 'import',
        ]);

        $csvHeaders = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];
        $escapeCsv = function ($v) {
            $s = $v === null || $v === '' ? '' : (string) $v;
            return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $ticketRows = [];
        foreach ($attendees as $a) {
            $ticketRows[] = [
                'email_address' => $a['email'] ?? '',
                'first_name' => $a['first_name'] ?? '',
                'last_name' => $a['last_name'] ?? '',
                'mobile_number' => $a['phone'] ?? $a['mobile_number'] ?? '',
                'street_address' => $a['street_address'] ?? '',
                'street_address_2' => $a['street_address_2'] ?? '',
                'city' => $a['city'] ?? '',
                'state' => $a['state'] ?? '',
                'zip_code' => $a['zip_code'] ?? $a['postal_code'] ?? '',
                'country' => $a['country'] ?? '',
                'gender' => $a['gender'] ?? '',
                'age' => $a['age'] ?? '',
            ];
        }
        if (!empty($ticketRows)) {
            $lines = [implode(',', $csvHeaders)];
            foreach ($ticketRows as $row) {
                $lines[] = implode(',', array_map(function ($key) use ($row, $escapeCsv) {
                    return $escapeCsv($row[$key] ?? '');
                }, $csvHeaders));
            }
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines);
            Storage::disk('local')->put('mailchimp_imports/' . $ticketLog->id . '.csv', $csv);
            $ticketLog->update(['has_import_file' => true]);
        }
    }

    private function importFormData(
        MailchimpService $mailchimpService,
        MailchimpLogService $logService,
        Location $location,
        array $formTags
    ): void {
        $eventId = $this->eventId;
        $locationId = $location->id;
        $signUpForm = SignUpForm::where('event_id', $eventId)->first();

        if (!$signUpForm || !$signUpForm->table_name || !Schema::hasTable($signUpForm->table_name)) {
            return;
        }

        $signUpRows = DB::table($signUpForm->table_name)
            ->where('location_id', $locationId)
            ->where('event_id', $eventId)
            ->get();

        $locFormSuccess = 0;
        $locFormFailed = 0;
        $locFormNew = 0;
        $locFormUpdated = 0;
        $locFormErrors = [];
        $failedRowsData = [];
        $listId = $this->listId;
        $maxFailedRowsStored = 100;

        foreach ($signUpRows as $row) {
            $email = trim((string) ($row->email_address ?? ''));
            if ($email === '') {
                continue;
            }
            $street = trim($row->street_address ?? '');
            $street2 = trim($row->street_address_2 ?? '');
            $city = trim($row->city ?? '');
            $state = trim($row->state ?? '');
            $zip = trim($row->zip_code ?? $row->postal_code ?? '');
            $country = trim($row->country ?? '');
            $addrParts = array_filter([$street, $street2, $city, $state, $zip, $country]);
            $subscriber = array_merge(
                (array) $row,
                [
                    'email_address' => $email,
                    'first_name' => $row->first_name ?? '',
                    'last_name' => $row->last_name ?? '',
                    'mobile_number' => $row->mobile_number ?? $row->phone ?? '',
                    'street_address' => $street,
                    'street_address_2' => $street2,
                    'city' => $city,
                    'state' => $state,
                    'zip_code' => $zip,
                    'country' => $country,
                    'gender' => $row->gender ?? '',
                    'age' => $row->age ?? '',
                    'address_full' => implode(', ', $addrParts),
                ]
            );
            try {
                $result = $mailchimpService->manualImportSubscriber($listId, $subscriber, $formTags, $this->fieldMapping);
                $locFormSuccess++;
                if (isset($result['import_type'])) {
                    if ($result['import_type'] === 'new') {
                        $locFormNew++;
                    } elseif ($result['import_type'] === 'updated') {
                        $locFormUpdated++;
                    }
                }
                try {
                    $logService->logImport($location->id, $location->name, [
                        'success' => true,
                        'email' => $subscriber['email_address'],
                        'tags' => $formTags,
                        'source' => 'signup_form',
                    ]);
                } catch (\Throwable $e2) {
                    // Do not let logging failure abort the import.
                }
            } catch (\Throwable $e) {
                $locFormFailed++;
                $locFormErrors[] = substr("{$subscriber['email_address']}: " . $e->getMessage(), 0, 200);
                if (count($failedRowsData) < $maxFailedRowsStored) {
                    $failedRowsData[] = array_merge(
                        array_intersect_key($subscriber, array_flip(['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'])),
                        ['error_message' => $e->getMessage()]
                    );
                }
                try {
                    $logService->logImport($location->id, $location->name, [
                        'success' => false,
                        'email' => $subscriber['email_address'],
                        'error' => $e->getMessage(),
                        'tags' => $formTags,
                        'source' => 'signup_form',
                    ]);
                } catch (\Throwable $e2) {
                    // Do not let logging failure abort the import.
                }
            }
        }

        $hadPreviousImport = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $this->listId)
            ->where('mailchimp_account', $this->mailchimpAccount)
            ->exists();

        $formLog = MailchimpImportLog::create([
            'location_id' => $locationId,
            'imported_by' => $this->userId,
            'total_data' => count($signUpRows),
            'new_contacts' => $locFormNew,
            'updated_data' => $locFormUpdated,
            'data_with_error' => $locFormFailed,
            'errors' => array_slice($locFormErrors, 0, 50),
            'failed_rows' => array_slice($failedRowsData, 0, $maxFailedRowsStored),
            'tags' => $formTags,
            'source' => 'signup_form',
            'mailchimp_account' => $this->mailchimpAccount,
            'list_id' => $this->listId,
            'list_name' => $this->listName,
            'status' => $hadPreviousImport ? 'reimport' : 'import',
        ]);

        $csvHeadersForm = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];
        $escapeCsvForm = function ($v) {
            $s = $v === null || $v === '' ? '' : (string) $v;
            return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        if (!empty($signUpRows)) {
            $linesForm = [implode(',', $csvHeadersForm)];
            foreach ($signUpRows as $row) {
                $r = [
                    'email_address' => $row->email_address ?? '',
                    'first_name' => $row->first_name ?? '',
                    'last_name' => $row->last_name ?? '',
                    'mobile_number' => $row->mobile_number ?? $row->phone ?? '',
                    'street_address' => $row->street_address ?? '',
                    'street_address_2' => $row->street_address_2 ?? '',
                    'city' => $row->city ?? '',
                    'state' => $row->state ?? '',
                    'zip_code' => $row->zip_code ?? $row->postal_code ?? '',
                    'country' => $row->country ?? '',
                    'gender' => $row->gender ?? '',
                    'age' => $row->age ?? '',
                ];
                $linesForm[] = implode(',', array_map(function ($key) use ($r, $escapeCsvForm) {
                    return $escapeCsvForm($r[$key] ?? '');
                }, $csvHeadersForm));
            }
            $csvForm = "\xEF\xBB\xBF" . implode("\r\n", $linesForm);
            Storage::disk('local')->put('mailchimp_imports/' . $formLog->id . '.csv', $csvForm);
            $formLog->update(['has_import_file' => true]);
        }
    }
}
