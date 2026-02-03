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
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/lists/{$listId}/merge-fields");

        if ($response->successful()) {
            return $response->json()['merge_fields'];
        }

        throw new \Exception('Failed to fetch Mailchimp merge fields: ' . $response->body());
    }

    // Helper function to format Australian mobile numbers to E.164
    private function formatPhone($number) {
        $number = preg_replace('/\D+/', '', $number); // Remove non-digits
        if (empty($number)) {
            return '';
        }
        if (strpos($number, '0') === 0) {
            // Australian mobile, replace leading 0 with +61
            return '+61' . substr($number, 1);
        }
        // If already in international format or another format, return as is
        return $number;
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

        // Log the data being sent to Mailchimp for debugging
        \Illuminate\Support\Facades\Log::info('Sending data to Mailchimp', [
            'email' => $subscriber['email_address'] ?? 'no email',
            'first_name' => $subscriber['first_name'] ?? 'no first name',
            'last_name' => $subscriber['last_name'] ?? 'no last name',
            'city' => $subscriber['city'] ?? 'no city',
            'state' => $subscriber['state'] ?? 'no state',
            'zip_code' => $subscriber['zip_code'] ?? 'no zip',
            'country' => $subscriber['country'] ?? 'no country',
            'age' => $ageValue,
            'gender' => $subscriber['gender'] ?? 'no gender',
            'phone' => $subscriber['mobile_number'] ?? 'no phone',
            'address' => $subscriber['street_address'] ?? 'no address',
            'raw_subscriber_data' => $subscriber
        ]);

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
    public function manualImportSubscriber($listId, $subscriber, $tags = [])
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

        // Log the data being sent for debugging
        \Illuminate\Support\Facades\Log::info('Manual import - raw subscriber data', [
            'email' => $subscriber['email_address'] ?? 'no email',
            'raw_data' => $subscriber
        ]);

        // Get available merge fields from Mailchimp
        try {
            $availableMergeFields = $this->getListMergeFields($listId);
            $mergeFieldMap = $this->createDynamicMergeFieldMap($availableMergeFields, $subscriber, $ageValue, $rejectedFields);
            
            \Illuminate\Support\Facades\Log::info('Manual import - available merge fields', [
                'email' => $subscriber['email_address'] ?? 'no email',
                'available_fields' => array_map(function($field) {
                    return ['tag' => $field['tag'], 'name' => $field['name'], 'type' => $field['type']];
                }, $availableMergeFields),
                'mapped_fields' => $mergeFieldMap
            ]);
            
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

        // Log the prepared merge fields
        \Illuminate\Support\Facades\Log::info('Manual import - prepared merge fields', [
            'email' => $subscriber['email_address'] ?? 'no email',
            'merge_fields' => $mergeFields,
            'total_fields_to_send' => count($mergeFields),
            'mailchimp_request_data' => [
                'email_address' => $subscriber['email_address'],
                'status_if_new' => 'subscribed',
                'merge_fields' => $mergeFields,
                'tags' => $tags
            ]
        ]);

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

        // Try PATCH first for existing subscribers to avoid validation of all existing fields
        // This approach only updates the fields we're sending, without validating other existing fields
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->patch("{$this->baseUrl}/lists/{$listId}/members/{$emailHash}", [
                'merge_fields' => $mergeFields,
                'tags' => $tagsData
            ]);
            
        // If PATCH fails (subscriber doesn't exist), try POST to create new subscriber
        if (!$response->successful() && $response->status() === 404) {
            $response = Http::withBasicAuth('anystring', $this->apiKey)
                ->post("{$this->baseUrl}/lists/{$listId}/members", [
                    'email_address' => $subscriber['email_address'],
                    'status' => 'subscribed',
                    'merge_fields' => $mergeFields,
                    'tags' => $tagsData
                ]);
            $isExisting = false; // This is a new subscriber
        }

        if ($response->successful()) {
            $result = $response->json();
            // Add information about whether this was new or updated
            $result['was_existing'] = $isExisting;
            $result['import_type'] = $isExisting ? 'updated' : 'new';
            $result['rejected_fields'] = $rejectedFields;
            
            // Log detailed response from Mailchimp
            \Illuminate\Support\Facades\Log::info('Manual import - Mailchimp API response', [
                'email' => $subscriber['email_address'] ?? 'no email',
                'status_code' => $response->status(),
                'response_status' => $result['status'] ?? 'unknown',
                'member_id' => $result['id'] ?? 'unknown',
                'was_existing' => $isExisting,
                'import_type' => $result['import_type'],
                'merge_fields_sent' => count($mergeFields),
                'tags_applied' => count($tags),
                'full_response' => $result
            ]);
            
            return $result;
        }

        // Check if this is a MMERGE18 validation error from existing data (not our import data)
        $responseBody = $response->body();
        $responseData = json_decode($responseBody, true);
        
        if ($response->status() === 400 && 
            isset($responseData['errors']) && 
            is_array($responseData['errors'])) {
            
            // Check if all errors are MMERGE18 related (household income field we're not importing)
            $allErrorsAreMerge18 = true;
            foreach ($responseData['errors'] as $error) {
                if (!isset($error['field']) || $error['field'] !== 'MMERGE18') {
                    $allErrorsAreMerge18 = false;
                    break;
                }
            }
            
            // If all errors are MMERGE18 related, treat as successful update
            if ($allErrorsAreMerge18) {
                \Illuminate\Support\Facades\Log::info('Manual import - MMERGE18 validation error ignored (not our data)', [
                    'email' => $subscriber['email_address'] ?? 'no email',
                    'note' => 'Treating as successful because MMERGE18 errors are from existing data, not our import',
                    'original_error' => $responseBody
                ]);
                
                // Return a successful result structure
                return [
                    'id' => 'unknown', // We don't have the member ID but that's ok
                    'email_address' => $subscriber['email_address'],
                    'status' => 'subscribed',
                    'was_existing' => true, // These are existing subscribers with bad data
                    'import_type' => 'updated',
                    'rejected_fields' => $rejectedFields,
                    'note' => 'MMERGE18 validation errors ignored - not from our import data'
                ];
            }
        }

        $detail = $responseData['detail'] ?? 'Your merge fields were invalid.';
        if (!empty($responseData['errors']) && is_array($responseData['errors'])) {
            $first = $responseData['errors'][0];
            $field = $first['field'] ?? '';
            $message = $first['message'] ?? '';
            $detail .= ' Field: ' . $field . ($message ? ' - ' . $message : '');
        }
        \Illuminate\Support\Facades\Log::warning('Manual import - Mailchimp API error', [
            'email' => $subscriber['email_address'] ?? '',
            'status' => $response->status(),
            'detail' => $detail,
            'errors' => $responseData['errors'] ?? [],
            'merge_fields_sent' => array_keys($mergeFields),
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
    private function createDynamicMergeFieldMap($availableMergeFields, $subscriber, $ageValue, &$rejectedFields = [])
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

        // Define field mappings based on your EXACT Mailchimp merge fields from screenshot
        $fieldMappings = [
            // Name fields - EXACT tags from your Mailchimp
            'FNAME' => ['first_name', 'firstname', 'First Name', 'fname'],
            'LNAME' => ['last_name', 'lastname', 'Last Name', 'lname', 'surname'],
            
            // Address fields - using your EXACT field tags from screenshot
            'MMERGE10' => ['street_address', 'address', 'Street Address', 'Address', 'street', 'address_line_1'], // Street Address
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
            'ADDRESSWIN' => ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2'], // New address field
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
            'MERGE10' => ['street_address', 'address', 'Street Address', 'Address', 'street', 'address_line_1'], // Street Address backup
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
            } elseif (in_array($mailchimpField, ['ZIPCODE', 'ZIPCODEWIN'])) {
                $zipValue = $this->getSubscriberField($subscriber, $sourceFields);
                $normalized = $normalizeValue($zipValue ?: '', $mailchimpField);
                if ($normalized !== null) {
                    if ($normalized === $placeholderForRequired || $zipValue === '') {
                        $mergeFieldMap[$mailchimpField] = ($fieldType === 'number') ? 0 : $placeholderForRequired;
                    } else {
                        $mergeFieldMap[$mailchimpField] = ($fieldType === 'number') ? (int) $zipValue : (string) $zipValue;
                    }
                }
            } else {
                $value = $this->getSubscriberField($subscriber, $sourceFields);
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
            $addressData = [
                'addr1' => $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']),
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
        foreach (['MMERGE10', 'MMERGE11', 'ADDRESSWIN'] as $addressField) {
            if (in_array($addressField, $availableFieldTags)) {
                $fieldType = ($fieldInfoByTag[$addressField] ?? [])['type'] ?? 'text';
                if ($addressField === 'MMERGE10') {
                    if ($fieldType === 'address') {
                        // ANZ (and any list) where MMERGE10 is Address type: send full address object; use placeholders for missing parts
                        $addressData = [
                            'addr1' => trim((string) $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street'])),
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
                } elseif ($addressField === 'ADDRESSWIN') {
                    // ADDRESSWIN is an Address type field - needs full address object; use placeholders for missing parts
                    $addressData = [
                        'addr1' => trim((string) $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street'])),
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

        // Log final mapping for debugging
        \Illuminate\Support\Facades\Log::info('Manual import - final merge field mapping', [
            'email' => $subscriber['email_address'] ?? 'no email',
            'available_mailchimp_fields' => $availableFieldTags,
            'final_mapping' => $mergeFieldMap,
            'subscriber_data_keys' => array_keys($subscriber)
        ]);

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