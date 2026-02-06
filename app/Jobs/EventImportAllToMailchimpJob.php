<?php

namespace App\Jobs;

use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use App\Services\MailchimpLogService;
use App\Services\MailchimpService;
use App\Jobs\EventImportLocationToMailchimpJob;
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

    /** Mailchimp batch API: max operations per batch (Mailchimp recommends up to 500). */
    private const BATCH_CHUNK_SIZE = 500;

    /** Poll interval when waiting for a batch to finish (seconds). */
    private const BATCH_POLL_INTERVAL_SECONDS = 15;

    /** Max time to wait for a single batch to complete (seconds). */
    private const BATCH_MAX_WAIT_SECONDS = 600;

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
        // This job only dispatches per-location jobs; keep timeout short (e.g. 5 min for many locations).
        $this->timeout = config('queue.mailchimp_import_dispatch_job_timeout', 300);
        if ($this->timeout < 60) {
            $this->timeout = 300;
        }
    }

    public function handle(MailchimpLogService $logService): void
    {
        try {
            $this->dispatchLocationJobs();
        } catch (\Throwable $e) {
            $progress = $this->getProgressForLogging();
            $hint = $this->buildTimeoutHint($progress);
            Log::error('Event import all (job): dispatch failed – job removed from queue (not user cancel)', [
                'event_id' => $this->eventId,
                'list_id' => $this->listId,
                'import_batch_id' => $this->importBatchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'reason' => 'An error occurred while dispatching per-location jobs.',
                'progress' => $progress,
                'job_timeout_seconds' => $this->timeout,
                'what_to_check' => $hint,
            ]);
            throw $e;
        }
    }

    /**
     * Dispatch one EventImportLocationToMailchimpJob per location. Each location runs as a separate job
     * so imports finish without long-running timeouts.
     */
    private function dispatchLocationJobs(): void
    {
        $locationsToProcess = $this->locationsPayload;
        if ($this->skipAlreadyImported) {
            $alreadyImportedLocationIds = MailchimpImportLog::where('list_id', $this->listId)
                ->where('mailchimp_account', $this->mailchimpAccount)
                ->distinct()
                ->pluck('location_id')
                ->flip();
            $locationsToProcess = array_values(array_filter($this->locationsPayload, function ($loc) use ($alreadyImportedLocationIds) {
                return ! $alreadyImportedLocationIds->has((int) ($loc['location_id'] ?? 0));
            }));
        }

        $locationsQueued = count($locationsToProcess);
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

        Log::info('Event import all (job): dispatching per-location jobs', [
            'event_id' => $this->eventId,
            'list_id' => $this->listId,
            'import_batch_id' => $this->importBatchId,
            'locations_to_process' => $locationsQueued,
            'skip_already_imported' => $this->skipAlreadyImported,
        ]);

        foreach ($locationsToProcess as $loc) {
            if ($this->importBatchId && Cache::get('cancel_import_batch_' . $this->importBatchId)) {
                Log::info('Event import all (job): stopped by user – remaining locations not dispatched', [
                    'import_batch_id' => $this->importBatchId,
                    'event_id' => $this->eventId,
                ]);
                break;
            }
            EventImportLocationToMailchimpJob::dispatch(
                $this->eventId,
                $this->listId,
                $this->mailchimpAccount,
                $this->listName,
                $loc,
                $this->userId,
                $this->importBatchId,
                $this->fieldMapping
            );
        }

        Log::info('Event import all (job): dispatch complete – location jobs queued', [
            'event_id' => $this->eventId,
            'import_batch_id' => $this->importBatchId,
            'locations_dispatched' => $locationsQueued,
        ]);
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

                // Only import what the user selected per location (import_ticket / import_form). Skip locations with no data – no MC log.
                $importTicket = $loc['import_ticket'] ?? true;
                $importForm = $loc['import_form'] ?? true;
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
                $runTicket = $importTicket && $hasTicketData;
                $runForm = $importForm && $hasFormData;
                if (! $runTicket && ! $runForm) {
                    $locationsSkipped++;
                    continue;
                }

                // --- Ticket data import (only when selected and has data) ---
                if ($runTicket) {
                    $totalSubscribersImported += $this->importTicketData($mailchimpService, $logService, $location, $attendees, $tags);
                }

                // --- Win form (sign-up) data import (only when selected and has data) ---
                if ($runForm) {
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

    /**
     * Import subscribers to Mailchimp via the Batch API (submit batch, then poll until finished).
     * When operations error, fetches the batch response URL to get per-operation error messages.
     *
     * @return array{success: int, failed: int, errors: array<int, string>, failed_operations: array<int, array{email_address: string, first_name: string, last_name: string, mobile_number: string, error_message: string}>}
     */
    private function runBatchImport(
        MailchimpService $mailchimpService,
        string $listId,
        array $subscribers,
        array $tags,
        ?array $fieldMapping
    ): array {
        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        $failedOperations = [];
        if (empty($subscribers)) {
            return ['success' => 0, 'failed' => 0, 'errors' => [], 'failed_operations' => []];
        }

        $availableMergeFields = $mailchimpService->getListMergeFields($listId);
        $chunks = array_chunk($subscribers, self::BATCH_CHUNK_SIZE);
        $batchIndex = 0;

        foreach ($chunks as $chunk) {
            $batchIndex++;
            $operations = [];
            $subscribersInOrder = []; // same order as operations, for mapping response index -> subscriber
            foreach ($chunk as $i => $subscriber) {
                $email = strtolower(trim($subscriber['email_address'] ?? ''));
                if ($email === '') {
                    continue;
                }
                $emailHash = md5($email);
                $path = "/lists/{$listId}/members/{$emailHash}";
                $body = $mailchimpService->buildMemberPayloadForBatch($listId, $subscriber, $tags, $fieldMapping, $availableMergeFields);
                $operations[] = [
                    'method' => 'PUT',
                    'path' => $path,
                    'body' => json_encode($body),
                    'operation_id' => (string) ($batchIndex * self::BATCH_CHUNK_SIZE + $i),
                ];
                $subscribersInOrder[] = $subscriber;
            }
            if (empty($operations)) {
                continue;
            }

            try {
                $batchResponse = $mailchimpService->submitBatch($operations);
                $batchId = $batchResponse['id'] ?? null;
                if (! $batchId) {
                    $totalFailed += count($operations);
                    $errors[] = "Batch {$batchIndex}: no batch id in response.";
                    continue;
                }

                $deadline = time() + self::BATCH_MAX_WAIT_SECONDS;
                while (true) {
                    $statusResponse = $mailchimpService->getBatchStatus($batchId);
                    $status = $statusResponse['status'] ?? 'pending';
                    if ($status === 'finished') {
                        $finished = (int) ($statusResponse['finished_operations'] ?? 0);
                        $errored = (int) ($statusResponse['errored_operations'] ?? 0);
                        // finished_operations = all completed (success + error); success = finished - errored
                        $totalSuccess += max(0, $finished - $errored);
                        $totalFailed += $errored;
                        if ($errored > 0) {
                            $responseBodyUrl = $statusResponse['response_body_url'] ?? null;
                            if ($responseBodyUrl && is_string($responseBodyUrl)) {
                                try {
                                    $responses = $mailchimpService->getBatchResponseBody($responseBodyUrl);
                                    foreach ($responses as $i => $item) {
                                        $statusCode = (int) ($item['status_code'] ?? 0);
                                        if ($statusCode >= 400 && isset($subscribersInOrder[$i])) {
                                            $sub = $subscribersInOrder[$i];
                                            $bodyStr = $item['response'] ?? '';
                                            $body = is_string($bodyStr) ? json_decode($bodyStr, true) : null;
                                            $detail = $body['detail'] ?? '';
                                            if (is_array($body['errors'] ?? null)) {
                                                $parts = [];
                                                foreach (array_slice($body['errors'], 0, 3) as $err) {
                                                    $field = $err['field'] ?? '';
                                                    $msg = $err['message'] ?? '';
                                                    $parts[] = $field ? "{$field}: {$msg}" : $msg;
                                                }
                                                if ($parts) {
                                                    $detail = ($detail ? $detail . ' ' : '') . implode('; ', $parts);
                                                }
                                            }
                                            $failedOperations[] = [
                                                'email_address' => $sub['email_address'] ?? '',
                                                'first_name' => $sub['first_name'] ?? '',
                                                'last_name' => $sub['last_name'] ?? '',
                                                'mobile_number' => $sub['mobile_number'] ?? $sub['phone'] ?? '',
                                                'error_message' => $detail ?: ("HTTP {$statusCode}"),
                                            ];
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning('Event import all (job): could not fetch batch response body for errors', [
                                        'batch_id' => $batchId,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                        break;
                    }
                    if (in_array($status, ['pending', 'preprocessing', 'started', 'finalizing'], true)) {
                        if (time() >= $deadline) {
                            $totalFailed += count($operations);
                            $errors[] = "Batch {$batchIndex}: timed out waiting for batch (status: {$status}).";
                            break;
                        }
                        sleep(self::BATCH_POLL_INTERVAL_SECONDS);
                        continue;
                    }
                    $totalFailed += count($operations);
                    $errors[] = "Batch {$batchIndex}: unexpected status '{$status}'.";
                    break;
                }
            } catch (\Throwable $e) {
                $totalFailed += count($operations);
                $errors[] = "Batch {$batchIndex}: " . substr($e->getMessage(), 0, 150);
                Log::warning('Event import all (job): batch submit or poll failed', [
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['success' => $totalSuccess, 'failed' => $totalFailed, 'errors' => $errors, 'failed_operations' => $failedOperations];
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

        $listId = $this->listId;
        $maxFailedRowsStored = 100;

        // Filter to subscribers with email for batch (skip empty emails)
        $subscribersToImport = array_values(array_filter($subscribers, function ($s) {
            return ! empty(trim($s['email_address'] ?? ''));
        }));
        $skippedNoEmail = count($subscribers) - count($subscribersToImport);
        if ($skippedNoEmail > 0) {
            Log::info('Event import all (job): skipped subscribers with no email', [
                'location_id' => $locationId,
                'skipped' => $skippedNoEmail,
            ]);
        }

        $batchResult = $this->runBatchImport($mailchimpService, $listId, $subscribersToImport, $tags, $this->fieldMapping);
        $locSuccess = $batchResult['success'];
        $batchFailed = $batchResult['failed'];
        $locFailed = $batchFailed + $skippedNoEmail;
        $failedOperations = $batchResult['failed_operations'] ?? [];
        $failedRowsData = array_slice($failedOperations, 0, $maxFailedRowsStored);
        $attempted = count($subscribersToImport);
        $locNew = 0;
        // Only count as "data with error" rows where we have full error details (showable in UI).
        // Mailchimp-reported errors without details are counted as updated (e.g. may already be in list).
        $locDataWithError = count($failedRowsData);
        $noDetailCount = max(0, $batchFailed - count($failedRowsData));
        $locUpdated = $locSuccess + $noDetailCount;
        $locErrors = $batchResult['errors'];

        $hadPreviousImport = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $this->listId)
            ->where('mailchimp_account', $this->mailchimpAccount)
            ->exists();

        $ticketLog = MailchimpImportLog::create([
            'location_id' => $locationId,
            'imported_by' => $this->userId,
            'total_data' => $attempted,
            'new_contacts' => $locNew,
            'updated_data' => $locUpdated,
            'data_with_error' => $locDataWithError,
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

        $listId = $this->listId;
        $maxFailedRowsStored = 100;

        $subscribersToImport = [];
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
            $subscribersToImport[] = array_merge(
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
        }

        $batchResult = $this->runBatchImport($mailchimpService, $listId, $subscribersToImport, $formTags, $this->fieldMapping);
        $locFormSuccess = $batchResult['success'];
        $locFormFailed = $batchResult['failed'];
        $failedOperations = $batchResult['failed_operations'] ?? [];
        $failedRowsData = array_slice($failedOperations, 0, $maxFailedRowsStored);
        $locFormNew = 0;
        // Only count as "data with error" rows where we have full error details (showable in UI).
        $locFormDataWithError = count($failedRowsData);
        $noDetailCountForm = max(0, $locFormFailed - count($failedRowsData));
        $locFormUpdated = $locFormSuccess + $noDetailCountForm;
        $locFormErrors = $batchResult['errors'];
        $attemptedForm = count($subscribersToImport);

        $hadPreviousImport = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $this->listId)
            ->where('mailchimp_account', $this->mailchimpAccount)
            ->exists();

        $formLog = MailchimpImportLog::create([
            'location_id' => $locationId,
            'imported_by' => $this->userId,
            'total_data' => $attemptedForm,
            'new_contacts' => $locFormNew,
            'updated_data' => $locFormUpdated,
            'data_with_error' => $locFormDataWithError,
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
