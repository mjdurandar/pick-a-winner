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

    /** Progress for failure logging (set during run; not serialized in original payload so failed() uses cache). */
    public array $progressForLogging = [];

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
        // Allow production to run until all locations are imported (default 16 hours for large imports).
        $this->timeout = config('queue.mailchimp_import_job_timeout', 57600);
        if ($this->timeout < 60) {
            $this->timeout = 57600;
        }
    }

    public function handle(MailchimpLogService $logService): void
    {
        try {
            $this->runImport($logService);
        } catch (\Throwable $e) {
            $progress = $this->getProgressForLogging();
            $hint = $this->buildTimeoutHint($progress);
            Log::error('Event import all (job): import failed with error – job removed from queue (not user cancel)', [
                'event_id' => $this->eventId,
                'list_id' => $this->listId,
                'import_batch_id' => $this->importBatchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'reason' => 'An error occurred during import. The queued job is gone because the job failed.',
                'progress' => $progress,
                'job_timeout_seconds' => $this->timeout,
                'what_to_check' => $hint,
            ]);
            // Do not rethrow: allow the job to complete so Laravel does not retry and the queue keeps processing other jobs.
        }
    }

    /**
     * Called when the job is moved to failed_jobs (e.g. timeout, max attempts, worker killed).
     * Log so we can see why the import disappeared from the queue and how much was left.
     */
    public function failed(?\Throwable $e = null): void
    {
        $progress = $this->getProgressForLogging();
        $hint = $this->buildTimeoutHint($progress);
        Log::error('Event import all (job): job moved to failed_jobs – import did not complete (not user cancel)', [
            'event_id' => $this->eventId,
            'list_id' => $this->listId,
            'import_batch_id' => $this->importBatchId,
            'exception' => $e ? $e->getMessage() : 'unknown (e.g. timeout or max attempts exceeded)',
            'reason' => 'The queued import is gone because the job failed or was killed. Remaining locations were not imported.',
            'progress' => $progress,
            'job_timeout_seconds' => $this->timeout,
            'what_likely_stopped_it' => $hint,
        ]);
    }

    /**
     * Build a short hint for logs when import stops (timeout, kill, or error).
     */
    private function buildTimeoutHint(array $progress): string
    {
        $elapsed = isset($progress['started_at']) ? (time() - (int) $progress['started_at']) : null;
        $timeout = $this->timeout;
        $hint = 'Increase MAILCHIMP_IMPORT_JOB_TIMEOUT and DB_QUEUE_RETRY_AFTER in .env if the import stops at the same time every run. ';
        if ($elapsed !== null && $timeout > 0 && $elapsed >= $timeout * 0.95) {
            $hint .= sprintf('Elapsed %ds is near job timeout %ds – job was likely stopped by Laravel timeout. ', $elapsed, $timeout);
        } elseif ($elapsed !== null) {
            $hint .= sprintf('Elapsed %ds (job timeout %ds). ', $elapsed, $timeout);
        }
        $hint .= 'If the process dies earlier, check PHP memory_limit, max_execution_time, and server/worker kill signals.';
        return $hint;
    }

    /**
     * Progress for logging on failure. Uses in-memory progress if set this run, else cache (for failed() after timeout/kill).
     */
    private function getProgressForLogging(): array
    {
        if (! empty($this->progressForLogging)) {
            $p = $this->progressForLogging;
            $remaining = max(0, ($p['locations_queued'] ?? 0) - ($p['last_index'] ?? 0));
            $elapsed = isset($p['started_at']) ? (time() - (int) $p['started_at']) : null;
            $out = array_merge($p, ['locations_remaining_not_imported' => $remaining]);
            if ($elapsed !== null) {
                $out['elapsed_seconds'] = $elapsed;
            }
            return $out;
        }
        $key = $this->importBatchId
            ? 'event_import_progress_' . $this->importBatchId
            : 'event_import_progress_' . $this->eventId . '_' . $this->listId;
        $p = Cache::get($key, []);
        $locationsQueued = $p['locations_queued'] ?? count($this->locationsPayload);
        $lastIndex = $p['last_index'] ?? 0;
        $remaining = max(0, $locationsQueued - $lastIndex);
        $elapsed = isset($p['started_at']) ? (time() - (int) $p['started_at']) : null;
        $out = array_merge($p, [
            'locations_queued' => $locationsQueued,
            'locations_remaining_not_imported' => $remaining,
        ]);
        if ($elapsed !== null) {
            $out['elapsed_seconds'] = $elapsed;
        }
        return $out;
    }

    private function writeProgressToCache(int $locationsQueued, int $lastIndex, int $imported, int $failed, ?int $startedAt = null, int $subscribersImported = 0): void
    {
        $key = $this->importBatchId
            ? 'event_import_progress_' . $this->importBatchId
            : 'event_import_progress_' . $this->eventId . '_' . $this->listId;
        $payload = [
            'locations_queued' => $locationsQueued,
            'last_index' => $lastIndex,
            'locations_imported' => $imported,
            'locations_failed' => $failed,
            'subscribers_imported_so_far' => $subscribersImported,
        ];
        if ($startedAt !== null) {
            $payload['started_at'] = $startedAt;
        }
        Cache::put($key, $payload, 7200);
    }

    private function runImport(MailchimpLogService $logService): void
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
        $totalSubscribersImported = 0;
        $startedAt = time();

        $this->progressForLogging = [
            'locations_queued' => $locationsQueued,
            'last_index' => 0,
            'locations_imported' => 0,
            'locations_failed' => 0,
            'subscribers_imported_so_far' => 0,
            'started_at' => $startedAt,
        ];
        $this->writeProgressToCache($locationsQueued, 0, 0, 0, $startedAt, 0);

        Log::info('Event import all (job) started', [
            'event_id' => $this->eventId,
            'locations_queued' => $locationsQueued,
            'locations_total_before_skip' => count($this->locationsPayload),
            'skip_already_imported' => $this->skipAlreadyImported,
            'job_timeout_seconds' => $this->timeout,
            'hint' => 'Laravel will stop this job after ' . $this->timeout . 's if not finished. Set MAILCHIMP_IMPORT_JOB_TIMEOUT and DB_QUEUE_RETRY_AFTER in .env to allow longer runs.',
        ]);

        foreach ($locationsToProcess as $loc) {
            $locationIndex++;

            if ($this->importBatchId && Cache::get('cancel_import_batch_' . $this->importBatchId)) {
                $locationsRemaining = $locationsQueued - $locationIndex + 1;
                $remainingLocationsSlice = array_slice($locationsToProcess, $locationIndex - 1);
                $subscribersRemainingEstimate = array_reduce($remainingLocationsSlice, function ($sum, $loc) {
                    $attendees = $loc['attendees'] ?? [];
                    return $sum + count($attendees);
                }, 0);
                Log::info('Event import all (job): stopped by user – remaining work not imported', [
                    'import_batch_id' => $this->importBatchId,
                    'event_id' => $this->eventId,
                    'reason' => 'User requested stop (stop all imports). Job exited so the queue entry is removed.',
                    'locations_queued_total' => $locationsQueued,
                    'locations_already_imported' => $locationsImported,
                    'locations_failed_so_far' => $locationsFailed,
                    'locations_skipped_so_far' => $locationsSkipped,
                    'locations_remaining_not_imported' => $locationsRemaining,
                    'subscribers_remaining_estimate' => $subscribersRemainingEstimate,
                ]);
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
                    $totalSubscribersImported += $this->importTicketData($mailchimpService, $logService, $location, $attendees, $tags);
                }

                // --- Win form (sign-up) data import ---
                if ($hasFormData) {
                    $totalSubscribersImported += $this->importFormData($mailchimpService, $logService, $location, $formTags);
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

            // Progress log and cache every 5 locations so we can log progress if job fails or is killed
            if ($locationIndex % 5 === 0 || $locationIndex === $locationsQueued) {
                $elapsed = time() - $startedAt;
                $this->progressForLogging = [
                    'locations_queued' => $locationsQueued,
                    'last_index' => $locationIndex,
                    'locations_imported' => $locationsImported,
                    'locations_failed' => $locationsFailed,
                    'subscribers_imported_so_far' => $totalSubscribersImported,
                    'started_at' => $startedAt,
                ];
                $this->writeProgressToCache($locationsQueued, $locationIndex, $locationsImported, $locationsFailed, $startedAt, $totalSubscribersImported);
                Log::info('Event import all (job) progress – success count so far', [
                    'event_id' => $this->eventId,
                    'locations_processed' => $locationIndex,
                    'locations_total' => $locationsQueued,
                    'locations_imported_so_far' => $locationsImported,
                    'locations_failed_so_far' => $locationsFailed,
                    'subscribers_imported_to_mailchimp_so_far' => $totalSubscribersImported,
                    'elapsed_seconds' => $elapsed,
                    'job_timeout_seconds' => $this->timeout,
                ]);
            }
        }

        $reasonsSummary = [];
        foreach ($failureReasons as $msg => $info) {
            $reasonsSummary[] = $msg . ' (locations: ' . implode(', ', $info['location_ids']) . ', count: ' . $info['count'] . ')';
        }

        $elapsed = time() - $startedAt;
        Log::info('Event import all (job) completed', [
            'event_id' => $this->eventId,
            'locations_queued' => $locationsQueued,
            'locations_imported' => $locationsImported,
            'locations_failed' => $locationsFailed,
            'locations_skipped' => $locationsSkipped,
            'total_subscribers_imported_to_mailchimp' => $totalSubscribersImported,
            'elapsed_seconds' => $elapsed,
            'failure_reasons' => array_slice($reasonsSummary, 0, 20),
        ]);
    }

    private function importTicketData(
        MailchimpService $mailchimpService,
        MailchimpLogService $logService,
        Location $location,
        array $attendees,
        array $tags
    ): int {
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

        return $locSuccess;
    }

    private function importFormData(
        MailchimpService $mailchimpService,
        MailchimpLogService $logService,
        Location $location,
        array $formTags
    ): int {
        $eventId = $this->eventId;
        $locationId = $location->id;
        $signUpForm = SignUpForm::where('event_id', $eventId)->first();

        if (!$signUpForm || !$signUpForm->table_name || !Schema::hasTable($signUpForm->table_name)) {
            return 0;
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

        return $locFormSuccess;
    }
}
