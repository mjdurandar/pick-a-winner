<?php

namespace App\Services;

use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Runs Mailchimp import for a single location (ticket and/or win form).
 * Used by EventImportLocationToMailchimpJob so each location is a separate job and we avoid timeouts.
 */
class MailchimpLocationImportService
{
    private const BATCH_CHUNK_SIZE = 500;

    private const BATCH_POLL_INTERVAL_SECONDS = 15;

    private const BATCH_MAX_WAIT_SECONDS = 600;

    /**
     * Import one location: ticket and/or win form based on payload. Skips if no data; no MC log for skipped.
     *
     * @param  array{location_id: int, import_ticket: bool, import_form: bool, attendees: array, tags: array, form_tags: array}  $locationPayload
     * @return array{imported: int, skipped: bool}
     */
    public function runForOneLocation(
        int $eventId,
        string $listId,
        string $mailchimpAccount,
        ?string $listName,
        array $locationPayload,
        int $userId,
        ?array $fieldMapping
    ): array {
        $locationId = (int) ($locationPayload['location_id'] ?? 0);
        $attendees = $locationPayload['attendees'] ?? [];
        $tags = $locationPayload['tags'] ?? [];
        $formTags = $locationPayload['form_tags'] ?? [];
        $importTicket = $locationPayload['import_ticket'] ?? true;
        $importForm = $locationPayload['import_form'] ?? true;

        $location = Location::with('event')->find($locationId);
        if (! $location || (int) $location->event_id !== $eventId) {
            return ['imported' => 0, 'skipped' => true];
        }

        $hasTicketData = ! empty($attendees) && ! empty($tags);
        $hasFormData = false;
        if (! empty($formTags)) {
            $signUpForm = SignUpForm::where('event_id', $eventId)->first();
            if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                $hasFormData = DB::table($signUpForm->table_name)
                    ->where('location_id', $locationId)
                    ->where('event_id', $eventId)
                    ->whereNotNull('email_address')
                    ->where('email_address', '!=', '')
                    ->exists();
            }
        }
        $runTicket = $importTicket && $hasTicketData;
        $runForm = $importForm && $hasFormData;
        if (! $runTicket && ! $runForm) {
            return ['imported' => 0, 'skipped' => true];
        }

        $mailchimpService = new MailchimpService($mailchimpAccount);
        $logService = new MailchimpLogService;
        $autoSyncSettings = app(AutoMailchimpService::class)->getSettings($eventId);
        $interestTagMap = is_array($autoSyncSettings['interest_tag_map'] ?? null) ? $autoSyncSettings['interest_tag_map'] : [];
        $total = 0;
        if ($runTicket) {
            $total += $this->importTicketData($mailchimpService, $listId, $listName, $mailchimpAccount, $userId, $fieldMapping, $location, $attendees, $tags, $interestTagMap);
        }
        if ($runForm) {
            $total += $this->importFormData($mailchimpService, $eventId, $listId, $listName, $mailchimpAccount, $userId, $fieldMapping, $location, $formTags, $interestTagMap);
        }

        return ['imported' => $total, 'skipped' => false];
    }

    private function runBatchImport(
        MailchimpService $mailchimpService,
        string $listId,
        array $subscribers,
        array $tags,
        ?array $fieldMapping,
        ?array $interestTagMap = null
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
            $subscribersInOrder = [];
            foreach ($chunk as $i => $subscriber) {
                $email = strtolower(trim($subscriber['email_address'] ?? ''));
                if ($email === '') {
                    continue;
                }
                $emailHash = md5($email);
                $path = "/lists/{$listId}/members/{$emailHash}";
                $body = $mailchimpService->buildMemberPayloadForBatch($listId, $subscriber, $tags, $fieldMapping, $availableMergeFields, $interestTagMap);
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
                                    Log::warning('Mailchimp location import: could not fetch batch response body', [
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
                Log::warning('Mailchimp location import: batch failed', ['batch_index' => $batchIndex, 'error' => $e->getMessage()]);
            }
        }

        return ['success' => $totalSuccess, 'failed' => $totalFailed, 'errors' => $errors, 'failed_operations' => $failedOperations];
    }

    private function importTicketData(
        MailchimpService $mailchimpService,
        string $listId,
        ?string $listName,
        string $mailchimpAccount,
        int $userId,
        ?array $fieldMapping,
        Location $location,
        array $attendees,
        array $tags,
        ?array $interestTagMap = null
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

        $maxFailedRowsStored = 100;
        $subscribersToImport = array_values(array_filter($subscribers, fn ($s) => ! empty(trim($s['email_address'] ?? ''))));
        if (count($subscribersToImport) === 0) {
            return 0;
        }

        // Asked before the batch runs: PUT /members/{hash} is add-or-update and answers
        // the same either way, so this is the only moment the audience can still say
        // which of these people it had never heard of.
        $existingBefore = $mailchimpService->existingMemberEmails($listId, array_column($subscribersToImport, 'email_address'));

        $batchResult = $this->runBatchImport($mailchimpService, $listId, $subscribersToImport, $tags, $fieldMapping, $interestTagMap);
        $locSuccess = $batchResult['success'];
        $batchFailed = $batchResult['failed'];
        $failedOperations = $batchResult['failed_operations'] ?? [];
        $failedRowsData = array_slice($failedOperations, 0, $maxFailedRowsStored);
        $attempted = count($subscribersToImport);
        $locDataWithError = count($failedRowsData);
        $noDetailCount = max(0, $batchFailed - count($failedRowsData));
        $written = $locSuccess + $noDetailCount;
        $split = MailchimpService::splitNewAndUpdated($subscribersToImport, $existingBefore, $failedRowsData, $written);
        $locNew = $split['new'];
        $locUpdated = $split['updated'];
        $locErrors = $batchResult['errors'];

        $hadPreviousImport = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $listId)
            ->where('mailchimp_account', $mailchimpAccount)
            ->where('source', 'ticket_data')
            ->exists();

        // Reuse the same log line for this location/list/account/source on re-import instead of adding a new row.
        $ticketLog = MailchimpImportLog::updateOrCreate([
            'location_id' => $locationId,
            'source' => 'ticket_data',
            'list_id' => $listId,
            'mailchimp_account' => $mailchimpAccount,
        ], [
            'imported_by' => $userId ?: null,
            'total_data' => $attempted,
            'new_contacts' => $locNew,
            'updated_data' => $locUpdated,
            'data_with_error' => $locDataWithError,
            'errors' => array_slice($locErrors, 0, 50),
            'failed_rows' => array_slice($failedRowsData, 0, $maxFailedRowsStored),
            'tags' => $tags,
            'list_name' => $listName,
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
        if (! empty($ticketRows)) {
            $lines = [implode(',', $csvHeaders)];
            foreach ($ticketRows as $row) {
                $lines[] = implode(',', array_map(fn ($key) => $escapeCsv($row[$key] ?? ''), $csvHeaders));
            }
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines);
            Storage::disk('local')->put('mailchimp_imports/' . $ticketLog->id . '.csv', $csv);
            $ticketLog->update(['has_import_file' => true]);
        }

        return $locSuccess;
    }

    private function importFormData(
        MailchimpService $mailchimpService,
        int $eventId,
        string $listId,
        ?string $listName,
        string $mailchimpAccount,
        int $userId,
        ?array $fieldMapping,
        Location $location,
        array $formTags,
        ?array $interestTagMap = null
    ): int {
        $locationId = $location->id;
        $signUpForm = SignUpForm::where('event_id', $eventId)->first();

        if (! $signUpForm || ! $signUpForm->table_name || ! Schema::hasTable($signUpForm->table_name)) {
            return 0;
        }

        $signUpRows = DB::table($signUpForm->table_name)
            ->where('location_id', $locationId)
            ->where('event_id', $eventId)
            ->get();

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

        if (empty($subscribersToImport)) {
            return 0;
        }

        // Taken before the batch, for the reason given on the ticket leg above.
        $existingBefore = $mailchimpService->existingMemberEmails($listId, array_column($subscribersToImport, 'email_address'));

        $batchResult = $this->runBatchImport($mailchimpService, $listId, $subscribersToImport, $formTags, $fieldMapping, $interestTagMap);
        $locFormSuccess = $batchResult['success'];
        $locFormFailed = $batchResult['failed'];
        $failedOperations = $batchResult['failed_operations'] ?? [];
        $failedRowsData = array_slice($failedOperations, 0, $maxFailedRowsStored);
        $locFormDataWithError = count($failedRowsData);
        $noDetailCountForm = max(0, $locFormFailed - count($failedRowsData));
        $writtenForm = $locFormSuccess + $noDetailCountForm;
        $formSplit = MailchimpService::splitNewAndUpdated($subscribersToImport, $existingBefore, $failedRowsData, $writtenForm);
        $locFormNew = $formSplit['new'];
        $locFormUpdated = $formSplit['updated'];
        $locFormErrors = $batchResult['errors'];
        $attemptedForm = count($subscribersToImport);

        $hadPreviousImport = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $listId)
            ->where('mailchimp_account', $mailchimpAccount)
            ->where('source', 'signup_form')
            ->exists();

        // Reuse the same log line for this location/list/account/source on re-import instead of adding a new row.
        $formLog = MailchimpImportLog::updateOrCreate([
            'location_id' => $locationId,
            'source' => 'signup_form',
            'list_id' => $listId,
            'mailchimp_account' => $mailchimpAccount,
        ], [
            'imported_by' => $userId ?: null,
            'total_data' => $attemptedForm,
            'new_contacts' => $locFormNew,
            'updated_data' => $locFormUpdated,
            'data_with_error' => $locFormDataWithError,
            'errors' => array_slice($locFormErrors, 0, 50),
            'failed_rows' => array_slice($failedRowsData, 0, $maxFailedRowsStored),
            'tags' => $formTags,
            'list_name' => $listName,
            'status' => $hadPreviousImport ? 'reimport' : 'import',
        ]);

        $csvHeadersForm = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];
        $escapeCsvForm = function ($v) {
            $s = $v === null || $v === '' ? '' : (string) $v;
            return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        if (! empty($signUpRows)) {
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
                $linesForm[] = implode(',', array_map(fn ($key) => $escapeCsvForm($r[$key] ?? ''), $csvHeadersForm));
            }
            $csvForm = "\xEF\xBB\xBF" . implode("\r\n", $linesForm);
            Storage::disk('local')->put('mailchimp_imports/' . $formLog->id . '.csv', $csvForm);
            $formLog->update(['has_import_file' => true]);
        }

        return $locFormSuccess;
    }
}
