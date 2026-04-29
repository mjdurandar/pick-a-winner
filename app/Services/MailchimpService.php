<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class MailchimpService
{
    protected $apiKey;
    protected $serverPrefix;
    protected $baseUrl;
    protected $account;

    public function __construct($account = 'anz')
    {
        $this->account = $account;
        
        // Support both new multi-account structure and legacy single account
        if ($account === 'anz' || $account === 'usa') {
            $this->apiKey = Config::get("services.mailchimp.{$account}.key");
            $this->serverPrefix = Config::get("services.mailchimp.{$account}.server");
        } else {
            // Legacy support
            $this->apiKey = Config::get('services.mailchimp.key');
            $this->serverPrefix = Config::get('services.mailchimp.server');
        }
        
        $this->baseUrl = "https://{$this->serverPrefix}.api.mailchimp.com/3.0";
    }

    public function getLists()
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Mailchimp API key not configured for account: {$this->account}");
        }

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/lists");

        if ($response->successful()) {
            return $response->json()['lists'];
        }

        throw new \Exception('Failed to fetch Mailchimp lists: ' . $response->body());
    }

    /**
     * Map a sign-up form "Favorite adventure sport" answer to INT - * tags.
     * Multi-select answers are stored as a comma-separated string, so this
     * splits on commas that separate distinct options (not commas inside
     * parentheses) and returns one tag per selection. Unrecognized or empty
     * values yield an empty array.
     *
     * When $customMap is provided (admin-configured per-event map from the
     * Mailchimp settings modal), its entries override the hardcoded default.
     * Keys are matched case-insensitively: first by exact equality with the
     * selected option, then by prefix. When a non-empty custom map is given,
     * the hardcoded defaults are NOT used as a fallback — unmapped answers
     * produce no tag, so admins can fully control which answers tag and which
     * do not.
     *
     * @param  array<string, string>|null  $customMap  option label → tag string
     * @return string[]
     */
    public static function mapFaveSportToInterestTags(?string $value, ?array $customMap = null): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $defaultMap = [
            'snow sports' => 'INT - SNOWSPORTS',
            'climbing' => 'INT - CLIMBING',
            'running' => 'INT - RUNNING',
            'trail sports' => 'INT - TRAILSPORTS, INT - RUNNING',
            'skate sports' => 'INT - SKATEBOARDING',
            'cycling' => 'INT - MTB',
            'water sports' => 'INT - WATERSPORTS',
            'outdoor' => 'INT - OUTDOOR',
            'aerial' => 'INT - OUTDOOR, INT - ENVIRONMENT',
            'extreme' => 'INT - OUTDOOR, INT - ENVIRONMENT',
            'other' => 'INT - ALL',
        ];

        $normalizedCustom = [];
        if (is_array($customMap)) {
            foreach ($customMap as $k => $v) {
                if (!is_string($k) || !is_string($v)) {
                    continue;
                }
                $tagVal = trim($v);
                if ($tagVal === '') {
                    continue;
                }
                $normalizedCustom[strtolower(trim($k))] = $tagVal;
            }
        }
        $useCustom = !empty($normalizedCustom);

        // Split on commas that are outside parentheses so option labels like
        // "Water Sports (Kayaking, Canoeing)" stay intact.
        $parts = preg_split('/,(?![^()]*\))/', $raw) ?: [$raw];

        $tags = [];
        foreach ($parts as $part) {
            $v = strtolower(trim($part));
            if ($v === '') {
                continue;
            }

            if ($useCustom) {
                if (isset($normalizedCustom[$v])) {
                    $tags = array_merge($tags, self::splitTagString($normalizedCustom[$v]));
                    continue;
                }
                foreach ($normalizedCustom as $key => $tag) {
                    if ($key !== '' && strpos($v, $key) === 0) {
                        $tags = array_merge($tags, self::splitTagString($tag));
                        break;
                    }
                }
                continue;
            }

            foreach ($defaultMap as $prefix => $tag) {
                if (strpos($v, $prefix) === 0) {
                    $tags = array_merge($tags, self::splitTagString($tag));
                    break;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Split a comma-separated tag string into individual trimmed tags.
     * Lets a single mapping value (e.g. "INT - OUTDOOR, INT - ENVIRONMENT")
     * apply multiple Mailchimp tags from one fave_sport answer.
     */
    private static function splitTagString(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($t) => $t !== ''));
    }

    public static function getAvailableAccounts()
    {
        return [
            'anz' => [
                'name' => 'Mailchimp ANZ',
                'key' => Config::get('services.mailchimp.anz.key'),
                'server' => Config::get('services.mailchimp.anz.server'),
                'enabled' => !empty(Config::get('services.mailchimp.anz.key'))
            ],
            'usa' => [
                'name' => 'Mailchimp USA',
                'key' => Config::get('services.mailchimp.usa.key'),
                'server' => Config::get('services.mailchimp.usa.server'),
                'enabled' => !empty(Config::get('services.mailchimp.usa.key'))
            ]
        ];
    }

    public function getListSegments($listId)
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Mailchimp API key not configured for account: {$this->account}");
        }

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/lists/{$listId}/segments", [
                'type' => 'static',
                'count' => 1000,
            ]);

        if ($response->successful()) {
            return $response->json()['segments'] ?? [];
        }

        throw new \Exception('Failed to fetch Mailchimp segments: ' . $response->body());
    }

    public function getListMergeFields($listId)
    {
        // Request up to 1000 merge fields to ensure we get all audience fields (Mailchimp API paginates by default)
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/lists/{$listId}/merge-fields", ['count' => 1000]);

        if ($response->successful()) {
            return $response->json()['merge_fields'];
        }

        throw new \Exception('Failed to fetch Mailchimp merge fields: ' . $response->body());
    }

    /**
     * Submit a batch of operations to Mailchimp (e.g. bulk add/update members).
     * Each operation: method, path, optional operation_id, optional body (JSON string for PUT/POST/PATCH).
     *
     * @param  array  $operations  Array of [ 'method' => 'PUT'|'POST'|'PATCH'|'GET', 'path' => '/lists/...', 'body' => '...' (optional), 'operation_id' => '...' (optional) ]
     * @return array  Response with id (batch_id), status, total_operations, etc.
     */
    public function submitBatch(array $operations): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Mailchimp API key not configured for account: {$this->account}");
        }
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->timeout(120)
            ->post("{$this->baseUrl}/batches", ['operations' => $operations]);

        if (! $response->successful()) {
            throw new \Exception('Failed to submit Mailchimp batch: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get the status of a batch operation.
     *
     * @param  string  $batchId  The id returned from submitBatch().
     * @return array  status (pending|preprocessing|started|finalizing|finished), total_operations, finished_operations, errored_operations, etc.
     */
    public function getBatchStatus(string $batchId): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Mailchimp API key not configured for account: {$this->account}");
        }
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/batches/{$batchId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get Mailchimp batch status: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Fetch and parse the batch response body from Mailchimp (per-operation results including errors).
     * The URL is returned in getBatchStatus() as response_body_url when the batch is finished.
     * Mailchimp returns application/gzip: either raw gzip of JSON, or gzip of a tar archive containing the JSON file.
     * When the client auto-decompresses, we may receive tar bytes (ustar); we extract the first file and parse as JSON/NDJSON.
     *
     * @param  string  $url  response_body_url from batch status
     * @return array<int, array{status_code: int, response: string}>  Index matches operation order.
     */
    public function getBatchResponseBody(string $url): array
    {
        $timeoutSeconds = 120;
        $body = null;
        $lastException = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::timeout($timeoutSeconds)->get($url);
                if (! $response->successful()) {
                    throw new \Exception('Failed to fetch batch response body: ' . $response->status());
                }
                $body = $response->body();
                break;
            } catch (\Throwable $e) {
                $lastException = $e;
                $isTimeout = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'Operation timed out');
                if ($attempt < 1 && $isTimeout) {
                    continue;
                }
                throw $e;
            }
        }
        if ($body === null) {
            throw $lastException ?? new \Exception('Failed to fetch batch response body');
        }

        // If raw gzip (magic bytes), decompress first
        if (strlen($body) >= 2 && substr($body, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($body);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        // Mailchimp may return a gzip-compressed tar; when the HTTP client auto-decompresses we get tar bytes (ustar)
        if (strlen($body) >= 512 && substr($body, 257, 5) === 'ustar') {
            $body = $this->extractFirstFileFromTar($body);
        }

        return $this->parseBatchResponseAsList($body);
    }

    /**
     * Extract the first file payload from a tar archive (512-byte header + file content).
     * Mailchimp's tar may have multiple 512-byte blocks; skip to the first JSON ([ or {) to get the batch response.
     */
    private function extractFirstFileFromTar(string $tar): string
    {
        $maxSize = 100 * 1024 * 1024;
        $offset = 0;

        while ($offset + 512 <= strlen($tar)) {
            $header = substr($tar, $offset, 512);
            $offset += 512;
            if (substr($header, 257, 5) !== 'ustar') {
                break;
            }
            $sizeOct = trim(substr($header, 124, 12));
            $size = (int) octdec($sizeOct);
            if ($size > 0 && $size <= $maxSize) {
                $content = substr($tar, $offset, $size);
                if (strlen($content) === $size) {
                    return $content;
                }
            }
            $offset += $size;
            if ($size % 512 !== 0) {
                $offset += 512 - ($size % 512);
            }
        }

        // No valid header/size: find first JSON start in the remainder (skip extra header blocks)
        $rest = substr($tar, 512);
        $jsonStart = strpos($rest, '[');
        if ($jsonStart === false) {
            $jsonStart = strpos($rest, '{');
        }
        if ($jsonStart !== false && strlen($rest) - $jsonStart <= $maxSize) {
            return substr($rest, $jsonStart);
        }

        // Fallback: use PharData to parse tar
        $tmp = tempnam(sys_get_temp_dir(), 'mc_batch');
        $tarPath = $tmp . '.tar';
        try {
            if (file_put_contents($tarPath, $tar) === false) {
                throw new \Exception('Could not write temp tar file');
            }
            $phar = new \PharData($tarPath);
            $content = null;
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                if ($file->isFile() && $file->getFilename() !== '' && substr($file->getFilename(), -5) === '.json') {
                    $content = file_get_contents($file->getPathname());
                    break;
                }
            }
            if ($content === null) {
                foreach (new \RecursiveIteratorIterator($phar) as $file) {
                    if ($file->isFile() && $file->getFilename() !== '') {
                        $content = file_get_contents($file->getPathname());
                        break;
                    }
                }
            }
            if ($content !== null && strlen($content) <= $maxSize) {
                return $content;
            }
        } finally {
            if (file_exists($tarPath)) {
                @unlink($tarPath);
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        throw new \Exception('Invalid tar file size in batch response');
    }

    /**
     * Parse a string as batch response: JSON array, wrapped object, or NDJSON.
     *
     * @return array<int, array{status_code: int, response: string}>
     */
    private function parseBatchResponseAsList(string $body): array
    {
        $data = json_decode($body, true);

        if (is_array($data)) {
            if (isset($data['responses']) && is_array($data['responses'])) {
                $data = $data['responses'];
            } elseif (isset($data['operations']) && is_array($data['operations'])) {
                $data = $data['operations'];
            }
            if (is_array($data) && (array_is_list($data) || array_key_exists(0, $data))) {
                return $data;
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $item = json_decode($line, true);
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        if (! empty($out)) {
            return $out;
        }

        \Illuminate\Support\Facades\Log::warning('Mailchimp batch response body: could not parse as JSON array or NDJSON', [
            'body_preview' => substr($body, 0, 300),
        ]);
        throw new \Exception('Invalid batch response body: not a JSON array or NDJSON. Check logs for body preview.');
    }

    /**
     * Build the request body for a single member for use in a batch operation (PUT /lists/{list_id}/members/{subscriber_hash}).
     * Reuses the same merge field and tag logic as manualImportSubscriber so batch imports match per-request behavior.
     *
     * @param  string  $listId  Mailchimp list (audience) ID.
     * @param  array  $subscriber  Subscriber data (email_address, first_name, last_name, etc.).
     * @param  array  $tags  Tags to apply.
     * @param  array|null  $fieldMapping  Optional map of Mailchimp tag => subscriber key.
     * @param  array|null  $availableMergeFields  Optional merge fields from getListMergeFields(); fetched if null.
     * @return array  Body for batch op: email_address, status, merge_fields, tags.
     */
    public function buildMemberPayloadForBatch(string $listId, array $subscriber, array $tags = [], ?array $fieldMapping = null, ?array $availableMergeFields = null, ?array $interestTagMap = null): array
    {
        $ageValue = null;
        if (isset($subscriber['age'])) {
            if (is_numeric($subscriber['age'])) {
                $ageValue = (int) $subscriber['age'];
            } elseif (is_string($subscriber['age'])) {
                $ageStr = strtolower(trim($subscriber['age']));
                if ($ageStr === 'under 21') {
                    $ageValue = 'Under 21';
                } elseif ($ageStr === '22-44') {
                    $ageValue = '22-44';
                } elseif ($ageStr === '45+') {
                    $ageValue = '45+';
                } else {
                    $ageValue = $subscriber['age'];
                }
            }
        }

        $interestTags = self::mapFaveSportToInterestTags($subscriber['fave_sport'] ?? null, $interestTagMap);
        if (!empty($interestTags)) {
            $tags = array_merge($tags, $interestTags);
        }
        $tagsData = array_values(array_unique($tags));
        $rejectedFields = [];

        if ($availableMergeFields === null) {
            $availableMergeFields = $this->getListMergeFields($listId);
        }

        try {
            $mergeFieldMap = $this->createDynamicMergeFieldMap($availableMergeFields, $subscriber, $ageValue, $rejectedFields, $fieldMapping);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Batch build payload: merge field map failed, using fallback', ['error' => $e->getMessage()]);
            $mergeFieldMap = [
                'FNAME' => $this->getSubscriberField($subscriber, ['first_name', 'firstname', 'First Name', 'fname']),
                'LNAME' => $this->getSubscriberField($subscriber, ['last_name', 'lastname', 'Last Name', 'lname', 'surname']),
                'PHONE' => $this->getSubscriberField($subscriber, ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number']),
            ];
        }

        $payload = [
            'email_address' => $subscriber['email_address'],
            'status' => 'subscribed',
            'merge_fields' => $mergeFieldMap,
            'tags' => $tagsData,
        ];
        $smsStatus = $this->resolveSmsSubscriptionStatus($mergeFieldMap, $subscriber, $fieldMapping);
        if ($smsStatus !== null) {
            $payload['sms_subscription'] = ['status' => $smsStatus];
        }
        return $payload;
    }

    /**
     * Resolve SMS subscription status for API: 'subscribed' | 'not_subscribed' | null.
     * When we send SMSPHONE we set subscribed so Mailchimp persists the number.
     * If fieldMapping has SMS_MARKETING_STATUS and subscriber has a value, use that.
     */
    private function resolveSmsSubscriptionStatus(array $mergeFields, array $subscriber, ?array $fieldMapping): ?string
    {
        if ($fieldMapping && isset($fieldMapping['SMS_MARKETING_STATUS']) && $fieldMapping['SMS_MARKETING_STATUS'] !== '') {
            $col = $fieldMapping['SMS_MARKETING_STATUS'];
            $val = isset($subscriber[$col]) ? trim((string) $subscriber[$col]) : '';
            if (stripos($val, 'subscribed') !== false || $val === '1' || $val === 'yes') {
                return 'subscribed';
            }
            if (stripos($val, 'unsubscribed') !== false || stripos($val, 'not subscribed') !== false || $val === '0' || $val === 'no') {
                return 'not_subscribed';
            }
        }
        if (isset($mergeFields['SMSPHONE']) && (string) ($mergeFields['SMSPHONE'] ?? '') !== '') {
            return 'subscribed';
        }
        return null;
    }

    /**
     * Format phone number to E.164 (international standard) for Mailchimp PHONE/SMSPHONE.
     * Mailchimp requires "SMS number in the international standard format" (e.g. +61412345678, +15551234567).
     * Uses $this->account (anz vs usa) so ANZ imports default to +61/+64 and USA imports to +1 when ambiguous.
     *
     * @param  string|null  $number
     * @param  string|null  $country  Optional country name/code to choose +61 (AU) vs +64 (NZ) for leading-0 numbers
     */
    private function formatPhoneE164($number, $country = null)
    {
        if ($number === null || $number === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', (string) $number);
        if ($digits === '') {
            return '';
        }
        $account = $this->account ?? 'anz';

        // US/Canada: 10 or 11 digits starting with 1
        if (strlen($digits) >= 10 && substr($digits, 0, 1) === '1') {
            return '+1' . substr($digits, -10);
        }
        // Already has country code: ensure + prefix
        if (strlen($digits) >= 9 && substr($digits, 0, 2) === '61') {
            return '+' . $digits;
        }
        if (strlen($digits) >= 9 && substr($digits, 0, 2) === '64') {
            return '+' . $digits;
        }

        // Leading 0: ANZ → +61/+64 from country; USA → treat as US if 0 + 10 digits (e.g. 0555123456)
        if (substr($digits, 0, 1) === '0' && strlen($digits) >= 9) {
            if ($account === 'usa' && strlen($digits) === 11) {
                return '+1' . substr($digits, 1);
            }
            $countryUpper = strtoupper((string) $country);
            if (strpos($countryUpper, 'NEW ZEALAND') !== false || $countryUpper === 'NZ') {
                return '+64' . substr($digits, 1);
            }
            return '+61' . substr($digits, 1);
        }

        // US/Canada: 10 digits, no leading 0 → +1
        if (strlen($digits) === 10 && substr($digits, 0, 1) !== '0') {
            return '+1' . $digits;
        }

        // Fallback: 10–15 digits, prepend +
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+' . ltrim($digits, '0');
        }
        return '';
    }

    /**
     * Check a subscriber's status in a Mailchimp list/audience.
     *
     * @param  string  $listId  Mailchimp list (audience) ID
     * @param  string  $email   Email address to check
     * @return array  ['exists' => bool, 'status' => string|null]  status: subscribed, unsubscribed, cleaned, pending, transactional, archived
     */
    public function getSubscriberStatus($listId, $email)
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Mailchimp API key not configured for account: {$this->account}");
        }

        $emailHash = md5(strtolower(trim($email)));

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}");

        if ($response->successful()) {
            $data = $response->json();
            return [
                'exists' => true,
                'status' => $data['status'] ?? null,
                'compliance_state' => $data['consents_to_one_to_one_messaging'] ?? null,
            ];
        }

        if ($response->status() === 404) {
            return [
                'exists' => false,
                'status' => null,
            ];
        }

        throw new \Exception('Failed to check subscriber status: ' . $response->body());
    }

    /**
     * Get the hosted signup form URL for a Mailchimp list.
     * Mailchimp returns subscribe_url_long on the list details endpoint.
     */
    public function getListSignupUrl($listId)
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::withBasicAuth('anystring', $this->apiKey)
                ->get("{$this->baseUrl}/lists/{$listId}?fields=subscribe_url_long,subscribe_url_short");

            if ($response->successful()) {
                $data = $response->json();
                return $data['subscribe_url_long'] ?? $data['subscribe_url_short'] ?? null;
            }
        } catch (\Exception $e) {
            // Fail silently
        }

        return null;
    }

    /**
     * Resubscribe an existing member to a Mailchimp list.
     * Uses PATCH to update status. If member is in compliance state,
     * skips silently — they can only resubscribe themselves via Mailchimp form.
     */
    public function resubscribe($listId, $email)
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Mailchimp API key not configured for account: {$this->account}");
        }

        $emailHash = md5(strtolower(trim($email)));

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->patch("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}?skip_merge_validation=true", [
                'status' => 'subscribed',
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        // Compliance state — nothing we can do via API, skip silently
        if ($response->status() === 400 && str_contains($response->body(), 'Compliance State')) {
            \Illuminate\Support\Facades\Log::info('Member in compliance state, skipping resubscribe (must self-subscribe via Mailchimp form)', [
                'email' => $email,
            ]);
            return ['status' => 'compliance_skipped'];
        }

        throw new \Exception('Failed to resubscribe: ' . $response->body());
    }

    public function addSubscriberToList($listId, $subscriber, $tags = [])
    {
        // Preprocess age value
        $ageValue = null;
        if (isset($subscriber['age'])) {
            if (is_numeric($subscriber['age'])) {
                $ageValue = (int)$subscriber['age'];
            } elseif (is_string($subscriber['age'])) {
                $ageStr = strtolower(trim($subscriber['age']));
                if ($ageStr === 'Under 21') {
                    $ageValue = 'Under 21';
                } elseif ($ageStr === '22-44') {
                    $ageValue = '22-44';
                } elseif ($ageStr === '45+') {
                    $ageValue = '45+';
                } else {
                    $ageValue = $subscriber['age']; // Keep original value if not recognized
                }
            }
        }

        // Use tags directly without any modification
        $tagsData = array_values(array_unique($tags));

        // Mailchimp requires a complete address (non-empty addr1, city, state, zip, country). Use placeholder when missing.
        $addrPlaceholder = '—';
        $addr1 = trim((string) ($subscriber['street_address'] ?? ''));
        $city = trim((string) ($subscriber['city'] ?? ''));
        $state = trim((string) ($subscriber['state'] ?? ''));
        $zip = trim((string) ($subscriber['zip_code'] ?? ''));
        $country = trim((string) ($subscriber['country'] ?? ''));
        $mergeFields = [
            'FNAME' => $subscriber['first_name'] ?? '',
            'LNAME' => $subscriber['last_name'] ?? '',
            'ADDRESS' => [
                'addr1' => $addr1 !== '' ? $addr1 : $addrPlaceholder,
                'addr2' => $subscriber['street_address_2'] ?? '',
                'city' => $city !== '' ? $city : $addrPlaceholder,
                'state' => $state !== '' ? $state : $addrPlaceholder,
                'zip' => $zip !== '' ? $zip : $addrPlaceholder,
                'country' => $country !== '' ? $country : $addrPlaceholder,
            ],
            'PHONE' => $subscriber['mobile_number'] ?? '',
            'GENDER' => $subscriber['gender'] ?? '',
            'MMERGE6' => $subscriber['city'] ?? '', // City
            'MMERGE7' => $subscriber['state'] ?? '', // State
            'MMERGE9' => $subscriber['country'] ?? '', // Country
            'MMERGE10' => $ageValue, // Age
            'MMERGE11' => $subscriber['street_address'] ?? '', // Street Address (backup)
        ];

        // Add MMERGE12 for zip code
        $mergeFields['MMERGE12'] = $subscriber['zip_code'] ?? '';

        // Use PUT (upsert) instead of POST to handle both new and existing members
        $emailHash = md5(strtolower(trim($subscriber['email_address'])));

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->put("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}", [
                'email_address' => $subscriber['email_address'],
                'status_if_new' => 'subscribed',
                'merge_fields' => $mergeFields,
                'tags' => $tagsData
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        // If compliance state, log and return gracefully
        if ($response->status() === 400 && str_contains($response->body(), 'Compliance State')) {
            \Illuminate\Support\Facades\Log::info('Member in compliance state during add/update, skipping', [
                'email' => $subscriber['email_address'],
            ]);
            return ['status' => 'compliance_skipped'];
        }

        throw new \Exception('Failed to add subscriber to Mailchimp list: ' . $response->body());
    }

    // NEW METHOD: Manual import with enhanced tracking and field mapping
    public function manualImportSubscriber($listId, $subscriber, $tags = [], $fieldMapping = null)
    {
        // Preprocess age value
        $ageValue = null;
        if (isset($subscriber['age'])) {
            if (is_numeric($subscriber['age'])) {
                $ageValue = (int)$subscriber['age'];
            } elseif (is_string($subscriber['age'])) {
                $ageStr = strtolower(trim($subscriber['age']));
                if ($ageStr === 'Under 21') {
                    $ageValue = 'Under 21';
                } elseif ($ageStr === '22-44') {
                    $ageValue = '22-44';
                } elseif ($ageStr === '45+') {
                    $ageValue = '45+';
                } else {
                    $ageValue = $subscriber['age'];
                }
            }
        }

        // Use tags directly without any modification
        $tagsData = array_values(array_unique($tags));

        // Initialize rejected fields tracking
        $rejectedFields = [];

        // Get available merge fields from Mailchimp
        try {
            $availableMergeFields = $this->getListMergeFields($listId);
            $mergeFieldMap = $this->createDynamicMergeFieldMap($availableMergeFields, $subscriber, $ageValue, $rejectedFields, $fieldMapping);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to get merge fields, using fallback mapping', [
                'error' => $e->getMessage()
            ]);
            
            // Fallback to basic mapping if we can't get merge fields
            $mergeFieldMap = [
                'FNAME' => $this->getSubscriberField($subscriber, ['first_name', 'firstname', 'First Name', 'fname']),
                'LNAME' => $this->getSubscriberField($subscriber, ['last_name', 'lastname', 'Last Name', 'lname', 'surname']),
                'PHONE' => $this->getSubscriberField($subscriber, ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number']),
            ];
        }

        $mergeFields = $mergeFieldMap;
        $smsSubscriptionStatus = $this->resolveSmsSubscriptionStatus($mergeFields, $subscriber, $fieldMapping);

        // Try to get existing member first to determine if it's new or updated
        $emailHash = md5(strtolower($subscriber['email_address']));
        $isExisting = false;
        
        try {
            $checkResponse = Http::withBasicAuth('anystring', $this->apiKey)
                ->get("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}");
            $isExisting = $checkResponse->successful();
        } catch (\Exception $e) {
            // Member doesn't exist, will be new
            $isExisting = false;
        }

        $doRequest = function ($mergeFieldsToSend) use ($emailHash, $listId, $subscriber, $tagsData, $smsSubscriptionStatus) {
            $patchBody = [
                'merge_fields' => $mergeFieldsToSend,
                'tags' => $tagsData,
            ];
            if ($smsSubscriptionStatus !== null) {
                $patchBody['sms_subscription'] = ['status' => $smsSubscriptionStatus];
            }
            $response = Http::withBasicAuth('anystring', $this->apiKey)
                ->patch("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}", $patchBody);
            if (! $response->successful() && $response->status() === 404) {
                $postBody = [
                    'email_address' => $subscriber['email_address'],
                    'status' => 'subscribed',
                    'merge_fields' => $mergeFieldsToSend,
                    'tags' => $tagsData,
                ];
                if ($smsSubscriptionStatus !== null) {
                    $postBody['sms_subscription'] = ['status' => $smsSubscriptionStatus];
                }
                $response = Http::withBasicAuth('anystring', $this->apiKey)
                    ->post("{$this->baseUrl}/lists/{$listId}/members", $postBody);
            }
            return $response;
        };

        $response = $doRequest($mergeFields);
        if ($response->successful()) {
            $result = $response->json();
            $result['was_existing'] = $isExisting;
            $result['import_type'] = $isExisting ? 'updated' : 'new';
            $result['rejected_fields'] = $rejectedFields;

            return $result;
        }

        $responseBody = $response->body();
        $responseData = json_decode($responseBody, true) ?? [];

        if ($response->status() === 400 && isset($responseData['errors']) && is_array($responseData['errors'])) {
            $allErrorsAreMerge18 = true;
            foreach ($responseData['errors'] as $error) {
                if (! isset($error['field']) || $error['field'] !== 'MMERGE18') {
                    $allErrorsAreMerge18 = false;
                    break;
                }
            }
            if ($allErrorsAreMerge18) {
                return [
                    'id' => 'unknown',
                    'email_address' => $subscriber['email_address'],
                    'status' => 'subscribed',
                    'was_existing' => true,
                    'import_type' => 'updated',
                    'rejected_fields' => $rejectedFields,
                    'note' => 'MMERGE18 validation errors ignored - not from our import data'
                ];
            }
        }

        $detail = $responseData['detail'] ?? '';
        $errors = $responseData['errors'] ?? [];
        $isPhoneRelatedError = false;
        if ($response->status() === 400) {
            foreach ($errors as $err) {
                $field = $err['field'] ?? '';
                if (in_array($field, ['PHONE', 'SMSPHONE', 'MERGE4', 'MERGE30'], true)) {
                    $isPhoneRelatedError = true;
                    break;
                }
            }
            if (! $isPhoneRelatedError && (stripos($detail, 'SMSPHONE') !== false || stripos($detail, 'SMS number') !== false || stripos($detail, 'international standard') !== false)) {
                $isPhoneRelatedError = true;
            }
        }

        if ($isPhoneRelatedError) {
            $mergeFieldsWithoutPhone = array_diff_key($mergeFields, array_flip(['PHONE', 'SMSPHONE', 'MERGE4', 'MERGE30']));
            \Illuminate\Support\Facades\Log::info('Manual import - retrying without phone/SMS fields after invalid format', [
                'email' => $subscriber['email_address'] ?? '',
            ]);
            $response = $doRequest($mergeFieldsWithoutPhone);
            if ($response->successful()) {
                $result = $response->json();
                $result['was_existing'] = $isExisting;
                $result['import_type'] = $isExisting ? 'updated' : 'new';
                $result['rejected_fields'] = array_merge($rejectedFields, ['PHONE/SMS omitted (invalid format)']);

                return $result;
            }
            $responseBody = $response->body();
            $responseData = json_decode($responseBody, true) ?? [];
        }

        $detail = $responseData['detail'] ?? 'Your merge fields were invalid.';
        if (! empty($responseData['errors']) && is_array($responseData['errors'])) {
            $first = $responseData['errors'][0];
            $field = $first['field'] ?? '';
            $message = $first['message'] ?? '';
            $detail .= ' Field: ' . $field . ($message ? ' - ' . $message : '');
        }
        \Illuminate\Support\Facades\Log::warning('Manual import - Mailchimp API error', [
            'email' => $subscriber['email_address'] ?? '',
            'status' => $response->status(),
            'detail' => $detail,
        ]);
        throw new \Exception('Failed to add/update subscriber in Mailchimp list: ' . $detail);
    }

    // Helper method for smart field detection in manual imports
    private function getSubscriberField($subscriber, $possibleKeys)
    {
        foreach ($possibleKeys as $key) {
            if (isset($subscriber[$key]) && !empty($subscriber[$key])) {
                return $subscriber[$key];
            }
        }
        return '';
    }

    // Create dynamic merge field mapping based on available Mailchimp fields
    // Only sends merge fields that exist on the audience; respects required fields and types to avoid "Your merge fields were invalid"
    // When $fieldMapping is provided (e.g. { FNAME: 'first_name', ADDRESS: 'address_full' }), use subscriber[$fieldMapping[tag]] for that tag
    private function createDynamicMergeFieldMap($availableMergeFields, $subscriber, $ageValue, &$rejectedFields = [], $fieldMapping = null)
    {
        $mergeFieldMap = [];
        $availableFieldTags = array_column($availableMergeFields, 'tag');
        $fieldInfoByTag = [];
        foreach ($availableMergeFields as $f) {
            $fieldInfoByTag[$f['tag']] = $f;
        }
        $placeholderForRequired = '—';

        // Helper: normalize value for Mailchimp (type, length, required)
        $normalizeValue = function ($value, $tag) use ($fieldInfoByTag, $placeholderForRequired) {
            if ($value === null || $value === '') {
                $info = $fieldInfoByTag[$tag] ?? null;
                if ($info && !empty($info['required'])) {
                    return $placeholderForRequired;
                }
                return null;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $info = $fieldInfoByTag[$tag] ?? null;
                    if ($info && !empty($info['required'])) {
                        return $placeholderForRequired;
                    }
                    return null;
                }
                // Mailchimp text fields ~255 char limit
                if (mb_strlen($value) > 255) {
                    $value = mb_substr($value, 0, 255);
                }
            }
            return $value;
        };

        // Value comes only from the user's mapping. When user maps a column that is blank, we skip it (don't touch existing Mailchimp data).
        $getSubscriberValueForTag = function ($tag) use ($subscriber, $fieldMapping) {
            if (! $fieldMapping || ! isset($fieldMapping[$tag]) || $fieldMapping[$tag] === '') {
                return null;
            }
            $key = $fieldMapping[$tag];
            $val = $subscriber[$key] ?? null;
            if ($val === null || (is_string($val) && trim($val) === '')) {
                return null; // Empty value: skip this field, don't overwrite existing Mailchimp data
            }
            return (string) $val;
        };

        // Process every merge field that exists on the audience (from API). No hardcoded tag list — we only use what Mailchimp returns and what the user mapped.
        foreach ($availableMergeFields as $field) {
            $tag = $field['tag'] ?? null;
            if (! $tag) {
                continue;
            }
            $info = $fieldInfoByTag[$tag] ?? $field;
            $fieldType = $info['type'] ?? 'text';
            $value = $getSubscriberValueForTag($tag);

            // Age: only include when mapped and non-empty.
            if (in_array($tag, ['AGE', 'AGEWIN', 'MMERGE14', 'MERGE14'], true)) {
                if ($value !== null) {
                    if ($ageValue !== null) {
                        $mergeFieldMap[$tag] = $ageValue;
                    } else {
                        $normalized = $normalizeValue($value, $tag);
                        if ($normalized !== null) {
                            $mergeFieldMap[$tag] = $normalized;
                        }
                    }
                }
                continue;
            }

            // Phone/SMS: format as E.164 when mapped and non-empty. Empty = skip, don't touch existing Mailchimp data.
            if (in_array($fieldType, ['phone', 'smsphone', 'sms'], true) || (stripos($tag, 'PHONE') !== false || stripos($tag, 'MERGE4') !== false || stripos($tag, 'MERGE30') !== false)) {
                if ($value !== null && $value !== '') {
                    $country = $subscriber['country'] ?? $subscriber['Country'] ?? '';
                    $formatted = $this->formatPhoneE164($value, $country);
                    if ($formatted !== '') {
                        $mergeFieldMap[$tag] = $formatted;
                    }
                }
                continue;
            }

            // Address type is handled later. Only set when mapped and non-empty.
            if ($fieldType === 'address') {
                if ($value !== null) {
                    $mergeFieldMap[$tag] = trim((string) $value);
                }
                continue;
            }

            // Zip/number: normalize when mapped and non-empty.
            if (in_array($fieldType, ['number'], true)) {
                if ($value !== null && $value !== '') {
                    $mergeFieldMap[$tag] = is_numeric($value) ? (int) $value : (string) $value;
                }
                continue;
            }

            // Dropdown/radio: only include when mapped and non-empty.
            if (in_array($fieldType, ['dropdown', 'radio']) && isset($info['options']['choices']) && is_array($info['options']['choices'])) {
                if ($value !== null) {
                    $choices = $info['options']['choices'];
                    $valueStr = (string) trim($value);
                    $matched = false;
                    foreach ($choices as $choice) {
                        $choiceStr = is_string($choice) ? $choice : ($choice['value'] ?? (string) $choice);
                        if (strcasecmp(trim($choiceStr), $valueStr) === 0) {
                            $mergeFieldMap[$tag] = $choiceStr;
                            $matched = true;
                            break;
                        }
                    }
                    if (! $matched && ! empty($info['required']) && count($choices) > 0) {
                        $first = $choices[0];
                        $mergeFieldMap[$tag] = is_string($first) ? $first : ($first['value'] ?? $placeholderForRequired);
                    }
                }
                continue;
            }

            // Text and everything else: only include when mapped and non-empty.
            if ($value !== null) {
                $normalized = $normalizeValue($value, $tag);
                if ($normalized !== null) {
                    $mergeFieldMap[$tag] = $normalized;
                }
            }
        }

        // Build a complete address for Mailchimp (required: addr1, city, state, zip, country must be non-empty)
        $addressPlaceholder = function () use ($placeholderForRequired) {
            return [
                'addr1' => $placeholderForRequired,
                'addr2' => '',
                'city' => $placeholderForRequired,
                'state' => $placeholderForRequired,
                'zip' => $placeholderForRequired,
                'country' => $placeholderForRequired,
            ];
        };

        // Handle ADDRESS field only when audience has it and user mapped it. Unmapped = don't send (leave existing Mailchimp value).
        if (in_array('ADDRESS', $availableFieldTags)) {
            $addressValue = $getSubscriberValueForTag('ADDRESS');
            $addressFieldType = ($fieldInfoByTag['ADDRESS'] ?? [])['type'] ?? 'text';
            if ($addressFieldType === 'address' && $addressValue !== null) {
                $addr1Source = trim((string) $addressValue);
                if ($addr1Source === '') {
                    $addr1Source = $this->getSubscriberField($subscriber, ['address_full', 'street_address', 'address', 'Street Address', 'Address', 'street']);
                }
                $addressData = [
                    'addr1' => $addr1Source !== '' ? $addr1Source : $placeholderForRequired,
                    'addr2' => $this->getSubscriberField($subscriber, ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2']),
                    'city' => $this->getSubscriberField($subscriber, ['city', 'City', 'town', 'Town']),
                    'state' => $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province']),
                    'zip' => $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code']),
                    'country' => $this->getSubscriberField($subscriber, ['country', 'Country']),
                ];
                foreach (['addr1', 'city', 'state', 'zip', 'country'] as $k) {
                    if (trim((string) ($addressData[$k] ?? '')) === '') {
                        $addressData[$k] = $placeholderForRequired;
                    }
                }
                $addressData['addr2'] = $addressData['addr2'] ?? '';
                $mergeFieldMap['ADDRESS'] = $addressData;
            }
        }

        // Fill required merge fields only when user mapped that field but we didn't set a value (e.g. required dropdown with no match).
        foreach ($availableMergeFields as $field) {
            $tag = $field['tag'] ?? null;
            if (!$tag || !empty($field['required']) === false) {
                continue;
            }
            if (array_key_exists($tag, $mergeFieldMap)) {
                continue;
            }
            if (!$fieldMapping || !isset($fieldMapping[$tag]) || $fieldMapping[$tag] === '') {
                continue;
            }
            $type = $field['type'] ?? 'text';
            if ($type === 'address') {
                $mergeFieldMap[$tag] = $addressPlaceholder();
            } elseif ($type === 'number') {
                $mergeFieldMap[$tag] = 0;
            } elseif (in_array($type, ['dropdown', 'radio']) && isset($field['options']['choices']) && is_array($field['options']['choices']) && count($field['options']['choices']) > 0) {
                $first = $field['options']['choices'][0];
                $mergeFieldMap[$tag] = is_string($first) ? $first : ($first['value'] ?? $placeholderForRequired);
            } else {
                $mergeFieldMap[$tag] = $placeholderForRequired;
            }
        }

        // Do NOT force-map fields that don't exist on the audience — sending unknown tags causes "Your merge fields were invalid"

        // Other address-type merge fields (e.g. ADDRESSWIN, MMERGE10): only build when user mapped this tag.
        foreach ($availableMergeFields as $field) {
            $tag = $field['tag'] ?? null;
            if (! $tag) {
                continue;
            }
            $fieldType = ($fieldInfoByTag[$tag] ?? [])['type'] ?? 'text';
            if ($fieldType !== 'address') {
                continue;
            }
            if (array_key_exists($tag, $mergeFieldMap)) {
                continue;
            }
            $mappedValue = $getSubscriberValueForTag($tag);
            if ($mappedValue === null) {
                continue;
            }
            $addr1 = is_scalar($mappedValue) ? trim((string) $mappedValue) : '';
            if ($addr1 === '') {
                $addr1 = $placeholderForRequired;
            }
            $addressData = [
                'addr1' => $addr1,
                'addr2' => trim((string) $this->getSubscriberField($subscriber, ['street_address_2', 'address_2', 'address_line_2'])),
                'city' => trim((string) $this->getSubscriberField($subscriber, ['city', 'City', 'town', 'Town'])),
                'state' => trim((string) $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province'])),
                'zip' => trim((string) $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode'])),
                'country' => trim((string) $this->getSubscriberField($subscriber, ['country', 'Country'])),
            ];
            $addressData['addr2'] = $addressData['addr2'] ?? '';
            foreach (['city', 'state', 'zip', 'country'] as $k) {
                if (($addressData[$k] ?? '') === '') {
                    $addressData[$k] = $placeholderForRequired;
                }
            }
            $mergeFieldMap[$tag] = $addressData;
        }

        return $mergeFieldMap;
    }

    private function getFieldDisplayName($fieldTag)
    {
        $displayNames = [
            'FNAME' => 'First Name',
            'LNAME' => 'Last Name',
            'CITY' => 'City',
            'SHOWCITY' => 'Show City',
            'STATE' => 'State',
            'STATEWIN' => 'State',
            'ZIPCODE' => 'Zip Code',
            'ZIPCODEWIN' => 'Zip Code',
            'COUNTRY' => 'Country',
            'COUNTRYWIN' => 'Country',
            'PHONE' => 'Phone Number',
            'SMSPHONE' => 'SMS Phone Number',
            'AGEWIN' => 'Age',
            'GENDER' => 'Gender',
            'MMERGE10' => 'Street Address',
            'MMERGE11' => 'Address',
            'ADDRESSWIN' => 'Address',
            'STREETADD' => 'Street Address',
            'MERGE12' => 'Street Address',
            'ADDRESS' => 'Address',
            'MERGE9' => 'Address',
            // 'MMERGE18' => 'Household Income', // Excluded from imports
            'MMERGE14' => 'Age',
        ];
        
        return $displayNames[$fieldTag] ?? $fieldTag;
    }
} 