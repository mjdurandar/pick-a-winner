<?php

namespace App\Jobs;

use App\Models\MailchimpImportLog;
use App\Services\MailchimpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ManualImportToMailchimpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Job timeout in seconds. */
    public $timeout;

    private const CSV_HEADERS = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];

    private const TAG_TO_KEY = [
        'EMAIL' => 'email_address',
        'FNAME' => 'first_name',
        'LNAME' => 'last_name',
        'PHONE' => 'mobile_number',
        'SMSPHONE' => 'mobile_number',
        'MERGE4' => 'mobile_number',
        'MERGE30' => 'mobile_number',
        'ADDRESSWIN' => 'street_address',
        'MMERGE10' => 'street_address',
        'MERGE10' => 'street_address',
        'MERGE11' => 'street_address',
        'SHOWCITY' => 'city',
        'CITY' => 'city',
        'MERGE3' => 'city',
        'MERGE5' => 'city',
        'STATEWIN' => 'state',
        'STATE' => 'state',
        'MERGE6' => 'state',
        'ZIPCODEWIN' => 'zip_code',
        'ZIPCODE' => 'zip_code',
        'MERGE7' => 'zip_code',
        'COUNTRYWIN' => 'country',
        'COUNTRY' => 'country',
        'MERGE8' => 'country',
        'GENDER' => 'gender',
        'MERGE17' => 'gender',
        'AGEWIN' => 'age',
        'MERGE14' => 'age',
        'MMERGE14' => 'age',
    ];

    public function __construct(
        public array $subscribers,
        public string $listId,
        public string $mailchimpAccount,
        public array $tags,
        public ?array $fieldMapping,
        public ?string $listName,
        public ?string $customEventName,
        public ?string $customSource,
        public ?int $userId
    ) {
        $this->timeout = config('queue.mailchimp_import_job_timeout', 7200);
        if ($this->timeout < 300) {
            $this->timeout = 3600; // at least 1 hour for manual import
        }
    }

    public function handle(): void
    {
        $tags = array_values(array_filter(array_map(function ($t) {
            return is_string($t) ? trim($t) : (string) $t;
        }, $this->tags ?: []), fn ($t) => $t !== ''));

        $fieldMapping = is_array($this->fieldMapping) ? array_filter($this->fieldMapping, fn ($v) => $v !== null && $v !== '') : null;
        if (empty($fieldMapping)) {
            $fieldMapping = null;
        }

        $results = [
            'success' => 0,
            'failed' => 0,
            'new' => 0,
            'updated' => 0,
            'errors' => [],
            'errorDetails' => [],
        ];

        try {
            $mailchimpService = new MailchimpService($this->mailchimpAccount);
        } catch (\Throwable $e) {
            Log::error('Manual import job: MailchimpService init failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        Log::info('Manual import job started', [
            'subscribers_count' => count($this->subscribers),
            'list_id' => $this->listId,
            'mailchimp_account' => $this->mailchimpAccount,
        ]);

        foreach ($this->subscribers as $subscriber) {
            try {
                if ($fieldMapping && isset($fieldMapping['EMAIL']) && $fieldMapping['EMAIL'] !== '') {
                    $emailKey = $fieldMapping['EMAIL'];
                    $subscriber['email_address'] = trim($subscriber[$emailKey] ?? $subscriber['email_address'] ?? '');
                }
                if (empty($subscriber['email_address'] ?? '')) {
                    $results['failed']++;
                    $results['errors'][] = 'Skipped subscriber: Missing email address';
                    continue;
                }

                if ($fieldMapping && isset($fieldMapping['ADDRESSWIN']) && $fieldMapping['ADDRESSWIN'] !== '') {
                    $addrKey = $fieldMapping['ADDRESSWIN'];
                    $singleAddr = trim($subscriber[$addrKey] ?? '');
                    $subscriber['address_full'] = $singleAddr !== '' ? $singleAddr : null;
                }
                if (empty($subscriber['address_full'] ?? '')) {
                    $addrParts = array_filter([
                        trim($subscriber['street_address'] ?? ''),
                        trim($subscriber['street_address_2'] ?? ''),
                        trim($subscriber['city'] ?? ''),
                        trim($subscriber['state'] ?? ''),
                        trim($subscriber['zip_code'] ?? $subscriber['postal_code'] ?? ''),
                        trim($subscriber['country'] ?? ''),
                    ]);
                    $subscriber['address_full'] = implode(', ', $addrParts);
                }

                $result = $mailchimpService->manualImportSubscriber(
                    $this->listId,
                    $subscriber,
                    $tags,
                    $fieldMapping
                );

                $results['success']++;
                if (isset($result['import_type'])) {
                    if ($result['import_type'] === 'new') {
                        $results['new']++;
                    } elseif ($result['import_type'] === 'updated') {
                        $results['updated']++;
                    }
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $email = $subscriber['email_address'] ?? 'unknown';
                $results['errors'][] = "Failed to import {$email}: " . substr($e->getMessage(), 0, 200);
                $results['errorDetails'][] = [
                    'email' => $email,
                    'error' => $e->getMessage(),
                    'subscriber_data' => $subscriber,
                ];
                Log::warning('Manual import job: subscriber failed', ['email' => $email, 'error' => $e->getMessage()]);
            }
        }

        $total = count($this->subscribers);
        Log::info('Manual import job finished', [
            'total' => $total,
            'success' => $results['success'],
            'failed' => $results['failed'],
            'new' => $results['new'],
            'updated' => $results['updated'],
        ]);

        $effectiveFieldMapping = $this->effectiveFieldMappingForLog($fieldMapping);

        $log = MailchimpImportLog::create([
            'location_id' => null,
            'imported_by' => $this->userId,
            'total_data' => $total,
            'new_contacts' => $results['new'],
            'updated_data' => $results['updated'],
            'data_with_error' => $results['failed'],
            'errors' => array_slice($results['errors'], 0, 100),
            'failed_rows' => array_slice($results['errorDetails'], 0, 100),
            'tags' => $tags,
            'source' => 'manual_csv',
            'mailchimp_account' => $this->mailchimpAccount,
            'list_id' => $this->listId,
            'list_name' => $this->listName,
            'field_mapping' => $effectiveFieldMapping,
            'custom_event_name' => $this->customEventName,
            'custom_source' => $this->customSource,
            'status' => 'import',
        ]);

        $normalized = $this->buildNormalizedSubscribers($fieldMapping);
        if (! empty($normalized)) {
            $escape = function ($v) {
                $s = $v === null || $v === '' ? '' : (string) $v;
                return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                    ? '"' . str_replace('"', '""', $s) . '"' : $s;
            };
            $lines = [implode(',', self::CSV_HEADERS)];
            foreach ($normalized as $row) {
                $lines[] = implode(',', array_map(function ($key) use ($row, $escape) {
                    return $escape($row[$key] ?? '');
                }, self::CSV_HEADERS));
            }
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines);
            Storage::disk('local')->put('mailchimp_imports/' . $log->id . '.csv', $csv);
            $log->update(['has_import_file' => true]);
        }
    }

    /**
     * Build tag => subscriber_key mapping for the log so reimport can use the same mapping.
     */
    private function effectiveFieldMappingForLog(?array $fieldMapping): ?array
    {
        if (empty($fieldMapping) || ! is_array($fieldMapping)) {
            return null;
        }
        $out = [];
        foreach ($fieldMapping as $tag => $col) {
            if (($col !== '' && $col !== null) && isset(self::TAG_TO_KEY[$tag])) {
                $out[$tag] = self::TAG_TO_KEY[$tag];
            }
        }
        return $out ?: null;
    }

    private function buildNormalizedSubscribers(?array $fieldMapping): array
    {
        $out = [];
        foreach ($this->subscribers as $row) {
            $norm = array_fill_keys(self::CSV_HEADERS, '');
            if ($fieldMapping) {
                foreach ($fieldMapping as $tag => $col) {
                    if ($col === '') {
                        continue;
                    }
                    $key = self::TAG_TO_KEY[$tag] ?? null;
                    if ($key) {
                        $norm[$key] = trim((string) ($row[$col] ?? ''));
                    }
                }
                if (isset($fieldMapping['EMAIL']) && $fieldMapping['EMAIL'] !== '') {
                    $norm['email_address'] = trim((string) ($row[$fieldMapping['EMAIL']] ?? ''));
                }
            }
            $out[] = $norm;
        }
        return $out;
    }
}
