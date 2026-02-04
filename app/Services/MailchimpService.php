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
     * Format phone number to E.164 (international standard) for Mailchimp PHONE/SMSPHONE.
     * Mailchimp requires "SMS number in the international standard format" (e.g. +61412345678).
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
        // Leading 0: use country hint for AU vs NZ
        if (substr($digits, 0, 1) === '0' && strlen($digits) >= 9) {
            $countryUpper = strtoupper((string) $country);
            if (strpos($countryUpper, 'NEW ZEALAND') !== false || $countryUpper === 'NZ') {
                return '+64' . substr($digits, 1);
            }
            return '+61' . substr($digits, 1); // Australia default for ANZ
        }
        // US/Canada: 10 digits, no leading 0
        if (strlen($digits) === 10 && substr($digits, 0, 1) !== '0') {
            return '+1' . $digits;
        }
        // Fallback: 10–15 digits, prepend +
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+' . ltrim($digits, '0');
        }
        return '';
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

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->post("{$this->baseUrl}/lists/{$listId}/members", [
                'email_address' => $subscriber['email_address'],
                'status' => 'subscribed',
                'merge_fields' => $mergeFields,
                'tags' => $tagsData
            ]);

        if ($response->successful()) {
            return $response->json();
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

        $doRequest = function ($mergeFieldsToSend) use ($emailHash, $listId, $subscriber, $tagsData) {
            $response = Http::withBasicAuth('anystring', $this->apiKey)
                ->patch("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}", [
                    'merge_fields' => $mergeFieldsToSend,
                    'tags' => $tagsData
                ]);
            if (! $response->successful() && $response->status() === 404) {
                $response = Http::withBasicAuth('anystring', $this->apiKey)
                    ->post("{$this->baseUrl}/lists/{$listId}/members", [
                        'email_address' => $subscriber['email_address'],
                        'status' => 'subscribed',
                        'merge_fields' => $mergeFieldsToSend,
                        'tags' => $tagsData
                    ]);
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

        // When user provides custom mapping, use it for source field lookup
        $getSubscriberValueForTag = function ($tag, $fallbackKeys) use ($subscriber, $fieldMapping) {
            if ($fieldMapping && isset($fieldMapping[$tag]) && $fieldMapping[$tag] !== '') {
                $key = $fieldMapping[$tag];
                $val = $subscriber[$key] ?? null;
                return $val !== null && $val !== '' ? (string) $val : null;
            }
            return $this->getSubscriberField($subscriber, $fallbackKeys);
        };

        // Define field mappings based on your EXACT Mailchimp merge fields from screenshot
        $fieldMappings = [
            // Name fields - EXACT tags from your Mailchimp
            'FNAME' => ['first_name', 'firstname', 'First Name', 'fname'],
            'LNAME' => ['last_name', 'lastname', 'Last Name', 'lname', 'surname'],

            // Address fields - address_full = concatenated street + city + state + zip + country
            'MMERGE10' => ['address_full', 'street_address', 'address', 'Street Address', 'Address', 'street', 'address_line_1'], // Street Address
            'MMERGE11' => ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2'], // Address (for street_address_2)
            'CITY' => ['city', 'City', 'town', 'Town'],
            'SHOWCITY' => ['city', 'City', 'town', 'Town'], // Show City (duplicate of city)
            'STATE' => ['state', 'State', 'province', 'Province', 'region', 'Region'],
            'STATEWIN' => ['state', 'State', 'province', 'Province', 'region', 'Region'],
            'ZIPCODE' => ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code', 'zip'],
            'ZIPCODEWIN' => ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code', 'zip'], // Your actual zip field
            'COUNTRY' => ['country', 'Country'],
            'COUNTRYWIN' => ['country', 'Country'], // New field tag from your logs
            
            // Phone fields - STILL MISSING from your audience
            'PHONE' => ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number'],
            'SMSPHONE' => ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number'],
            
            // Personal info
            'MMERGE14' => [$ageValue], // Age field
            'AGEWIN' => [$ageValue], // New age field tag from your logs
            'GENDER' => ['gender', 'Gender'],
            'ADDRESSWIN' => ['address_full', 'street_address', 'address_2', 'address_line_2', 'Address Line 2'], // Address (ANZ Newsletter)
            // 'MMERGE18' => ['household_income', 'income', 'What is your average household income'], // Household income - EXCLUDED completely
            // 'MMERGE12' => ['how_often_climb_overseas', 'overseas', 'How Often do you go overseas'], // Overseas sports - REMOVED per user request
            
            // Alternative MERGE number tags (backup options from your screenshot)
            'MERGE1' => ['first_name', 'firstname', 'First Name', 'fname'],
            'MERGE2' => ['last_name', 'lastname', 'Last Name', 'lname', 'surname'],
            'MERGE3' => ['city', 'City', 'town', 'Town'],
            'MERGE5' => ['city', 'City', 'town', 'Town'], // Show City backup
            'MERGE6' => ['state', 'State', 'province', 'Province', 'region', 'Region'], // State backup
            'MERGE7' => ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code', 'zip'], // Zip backup
            'MERGE8' => ['country', 'Country'], // Country backup
            'MERGE10' => ['address_full', 'street_address', 'address', 'Street Address', 'Address', 'street', 'address_line_1'], // Street Address backup
            'MERGE11' => ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2'], // Address backup
            // 'MERGE12' => ['how_often_climb_overseas', 'overseas', 'How Often do you go overseas'], // Overseas sports - REMOVED per user request
            'MERGE13' => ['how_much_spend', 'spend', 'How much would you spend'], // Equipment spending
            'MERGE14' => [$ageValue], // Age backup
            'MERGE17' => ['gender', 'Gender'], // Gender backup
            // 'MERGE18' => ['household_income', 'income', 'What is your average household income'], // Household income backup - EXCLUDED completely
            'MERGE4' => ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number'], // Phone backup
            'MERGE30' => ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number'], // SMS Phone backup
        ];

        // Only map fields that exist in the Mailchimp audience (never send a tag the audience doesn't have)
        foreach ($fieldMappings as $mailchimpField => $sourceFields) {
            if (!in_array($mailchimpField, $availableFieldTags)) {
                continue;
            }
            $info = $fieldInfoByTag[$mailchimpField] ?? null;
            $fieldType = $info['type'] ?? 'text';

            if (in_array($mailchimpField, ['AGE', 'AGEWIN', 'MMERGE14', 'MERGE14'])) {
                if ($ageValue !== null) {
                    $mergeFieldMap[$mailchimpField] = $ageValue;
                } elseif ($info && !empty($info['required'])) {
                    $mergeFieldMap[$mailchimpField] = $placeholderForRequired;
                }
            } elseif (in_array($mailchimpField, ['ZIPCODE', 'ZIPCODEWIN', 'MERGE7'])) {
                $zipValue = $getSubscriberValueForTag($mailchimpField, $sourceFields);
                $normalized = $normalizeValue($zipValue ?: '', $mailchimpField);
                if ($normalized !== null) {
                    if ($normalized === $placeholderForRequired || $zipValue === '') {
                        $mergeFieldMap[$mailchimpField] = ($fieldType === 'number') ? 0 : $placeholderForRequired;
                    } else {
                        $mergeFieldMap[$mailchimpField] = ($fieldType === 'number') ? (int) $zipValue : (string) $zipValue;
                    }
                }
            } elseif (in_array($mailchimpField, ['PHONE', 'SMSPHONE', 'MERGE4', 'MERGE30'])) {
                // Mailchimp requires phone/SMS in international standard format (E.164)
                $rawPhone = $getSubscriberValueForTag($mailchimpField, $sourceFields);
                $country = $this->getSubscriberField($subscriber, ['country', 'Country']);
                $formatted = $this->formatPhoneE164($rawPhone, $country);
                if ($formatted !== '') {
                    $mergeFieldMap[$mailchimpField] = $formatted;
                }
            } else {
                $value = $getSubscriberValueForTag($mailchimpField, $sourceFields);
                $normalized = $normalizeValue($value, $mailchimpField);
                if ($normalized !== null) {
                    // For dropdown/radio, value must match an allowed choice (USA/ANZ strict validation)
                    if (in_array($fieldType, ['dropdown', 'radio']) && isset($info['options']['choices']) && is_array($info['options']['choices'])) {
                        $choices = $info['options']['choices'];
                        $valueStr = (string) $normalized;
                        $matched = false;
                        foreach ($choices as $choice) {
                            $choiceStr = is_string($choice) ? $choice : ($choice['value'] ?? (string) $choice);
                            if (strcasecmp(trim($choiceStr), trim($valueStr)) === 0) {
                                $mergeFieldMap[$mailchimpField] = $choiceStr;
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched) {
                            if ($info && !empty($info['required']) && count($choices) > 0) {
                                $first = $choices[0];
                                $mergeFieldMap[$mailchimpField] = is_string($first) ? $first : ($first['value'] ?? $placeholderForRequired);
                            }
                            // optional dropdown/radio with invalid value: skip to avoid "merge fields invalid"
                        }
                    } else {
                        $mergeFieldMap[$mailchimpField] = $normalized;
                    }
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

        // Handle special ADDRESS field format if it exists
        if (in_array('ADDRESS', $availableFieldTags)) {
            $addr1Source = ($fieldMapping && isset($fieldMapping['ADDRESS']) && $fieldMapping['ADDRESS'] === 'address_full')
                ? ($subscriber['address_full'] ?? '')
                : $this->getSubscriberField($subscriber, ['address_full', 'street_address', 'address', 'Street Address', 'Address', 'street']);
            $addressData = [
                'addr1' => $addr1Source ?: $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']),
                'addr2' => $this->getSubscriberField($subscriber, ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2']),
                'city' => $this->getSubscriberField($subscriber, ['city', 'City', 'town', 'Town']),
                'state' => $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province']),
                'zip' => $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code']),
                'country' => $this->getSubscriberField($subscriber, ['country', 'Country']),
            ];
            // Mailchimp requires a "complete" address: fill any empty required parts with placeholder so import succeeds
            $addr1 = trim((string) ($addressData['addr1'] ?? ''));
            $city = trim((string) ($addressData['city'] ?? ''));
            $state = trim((string) ($addressData['state'] ?? ''));
            $zip = trim((string) ($addressData['zip'] ?? ''));
            $country = trim((string) ($addressData['country'] ?? ''));
            if ($addr1 === '') {
                $addressData['addr1'] = $placeholderForRequired;
            }
            if ($city === '') {
                $addressData['city'] = $placeholderForRequired;
            }
            if ($state === '') {
                $addressData['state'] = $placeholderForRequired;
            }
            if ($zip === '') {
                $addressData['zip'] = $placeholderForRequired;
            }
            if ($country === '') {
                $addressData['country'] = $placeholderForRequired;
            }
            $addressData['addr2'] = $addressData['addr2'] ?? '';
            $mergeFieldMap['ADDRESS'] = $addressData;
        }

        // Fill any required merge fields from the audience that we haven't set (USA/ANZ may have different required fields)
        foreach ($availableMergeFields as $field) {
            $tag = $field['tag'] ?? null;
            if (!$tag || !empty($field['required']) === false) {
                continue;
            }
            if (array_key_exists($tag, $mergeFieldMap)) {
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

        // Handle address fields with proper formatting for Mailchimp
        // MMERGE10 can be Address type (ANZ) or text (USA) — check audience field type and send full address object with placeholders when type is address
        foreach (['MMERGE10', 'MERGE10', 'MMERGE11', 'MERGE11', 'ADDRESSWIN'] as $addressField) {
            if (in_array($addressField, $availableFieldTags)) {
                $fieldType = ($fieldInfoByTag[$addressField] ?? [])['type'] ?? 'text';
                if (in_array($addressField, ['MMERGE10', 'MERGE10'])) {
                    if ($fieldType === 'address') {
                        // ANZ Adventure Entertainment Newsletter: MMERGE10 = Street Address (Address type); use address_full when mapped
                        $addr1Source = ($fieldMapping && isset($fieldMapping['MMERGE10']) && $fieldMapping['MMERGE10'] === 'address_full')
                            ? ($subscriber['address_full'] ?? '')
                            : $this->getSubscriberField($subscriber, ['address_full', 'street_address', 'address', 'Street Address', 'Address', 'street']);
                        $addressData = [
                            'addr1' => trim((string) ($addr1Source ?: $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']))),
                            'addr2' => trim((string) $this->getSubscriberField($subscriber, ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2'])),
                            'city' => trim((string) $this->getSubscriberField($subscriber, ['city', 'City', 'town', 'Town'])),
                            'state' => trim((string) $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province'])),
                            'zip' => trim((string) $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code'])),
                            'country' => trim((string) $this->getSubscriberField($subscriber, ['country', 'Country'])),
                        ];
                        $addressData['addr1'] = $addressData['addr1'] !== '' ? $addressData['addr1'] : $placeholderForRequired;
                        $addressData['city'] = $addressData['city'] !== '' ? $addressData['city'] : $placeholderForRequired;
                        $addressData['state'] = $addressData['state'] !== '' ? $addressData['state'] : $placeholderForRequired;
                        $addressData['zip'] = $addressData['zip'] !== '' ? $addressData['zip'] : $placeholderForRequired;
                        $addressData['country'] = $addressData['country'] !== '' ? $addressData['country'] : $placeholderForRequired;
                        $addressData['addr2'] = $addressData['addr2'] ?? '';
                        $mergeFieldMap[$addressField] = $addressData;
                    } else {
                        // Text type: street address string; use placeholder if empty and required
                        $streetAddress = $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']);
                        $info = $fieldInfoByTag[$addressField] ?? null;
                        $mergeFieldMap[$addressField] = (string) ($streetAddress !== '' ? $streetAddress : (!empty($info['required']) ? $placeholderForRequired : ''));
                    }
                } elseif (in_array($addressField, ['ADDRESSWIN', 'MMERGE11', 'MERGE11'])) {
                    // ADDRESSWIN / MERGE11 = Address (ANZ Newsletter); use address_full when mapped for concatenated address
                    $addr1Source = ($fieldMapping && (($fieldMapping['ADDRESSWIN'] ?? null) === 'address_full' || ($fieldMapping['MMERGE11'] ?? null) === 'address_full' || ($fieldMapping['MERGE11'] ?? null) === 'address_full'))
                        ? ($subscriber['address_full'] ?? '')
                        : $this->getSubscriberField($subscriber, ['address_full', 'street_address', 'address', 'Street Address', 'Address', 'street']);
                    $addressData = [
                        'addr1' => trim((string) ($addr1Source ?: $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']))),
                        'addr2' => trim((string) $this->getSubscriberField($subscriber, ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2'])),
                        'city' => trim((string) $this->getSubscriberField($subscriber, ['city', 'City', 'town', 'Town'])),
                        'state' => trim((string) $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province'])),
                        'zip' => trim((string) $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code'])),
                        'country' => trim((string) $this->getSubscriberField($subscriber, ['country', 'Country'])),
                    ];
                    if ($addressData['addr1'] === '') {
                        $addressData['addr1'] = $placeholderForRequired;
                    }
                    if ($addressData['city'] === '') {
                        $addressData['city'] = $placeholderForRequired;
                    }
                    if ($addressData['state'] === '') {
                        $addressData['state'] = $placeholderForRequired;
                    }
                    if ($addressData['zip'] === '') {
                        $addressData['zip'] = $placeholderForRequired;
                    }
                    if ($addressData['country'] === '') {
                        $addressData['country'] = $placeholderForRequired;
                    }
                    $mergeFieldMap[$addressField] = $addressData;
                }
            }
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
            // 'MMERGE18' => 'Household Income', // Excluded from imports
            'MMERGE14' => 'Age',
        ];
        
        return $displayNames[$fieldTag] ?? $fieldTag;
    }
} 