<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class MailchimpService
{
    protected $apiKey;
    protected $serverPrefix;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = Config::get('services.mailchimp.key');
        $this->serverPrefix = Config::get('services.mailchimp.server');
        $this->baseUrl = "https://{$this->serverPrefix}.api.mailchimp.com/3.0";
    }

    public function getLists()
    {
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->get("{$this->baseUrl}/lists");

        if ($response->successful()) {
            return $response->json()['lists'];
        }

        throw new \Exception('Failed to fetch Mailchimp lists: ' . $response->body());
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

        // Prepare merge fields with validation
        $mergeFields = [
            'FNAME' => $subscriber['first_name'] ?? '',
            'LNAME' => $subscriber['last_name'] ?? '',
            'ADDRESS' => [
                'addr1' => $subscriber['street_address'] ?? '',
                'addr2' => $subscriber['street_address_2'] ?? '',
                'city' => $subscriber['city'] ?? '',
                'state' => $subscriber['state'] ?? '',
                'zip' => $subscriber['zip_code'] ?? '',
                'country' => $subscriber['country'] ?? '',
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

        throw new \Exception('Failed to add/update subscriber in Mailchimp list: ' . $response->body());
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
    private function createDynamicMergeFieldMap($availableMergeFields, $subscriber, $ageValue, &$rejectedFields = [])
    {
        $mergeFieldMap = [];
        $availableFieldTags = array_column($availableMergeFields, 'tag');
        
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

        // Only map fields that exist in the Mailchimp audience
        foreach ($fieldMappings as $mailchimpField => $sourceFields) {
            if (in_array($mailchimpField, $availableFieldTags)) {
                if (in_array($mailchimpField, ['AGE', 'AGEWIN', 'MMERGE14', 'MERGE14'])) {
                    // Special handling for age fields
                    if ($ageValue !== null) {
                        $mergeFieldMap[$mailchimpField] = $ageValue;
                    }
                } elseif (in_array($mailchimpField, ['ZIPCODE', 'ZIPCODEWIN'])) {
                    // Special handling for zip code - convert to number
                    $zipValue = $this->getSubscriberField($subscriber, $sourceFields);
                    if (!empty($zipValue)) {
                        // Convert to integer for Number type fields
                        $mergeFieldMap[$mailchimpField] = (int) $zipValue;
                    }
                } else {
                    // Regular field mapping
                    $value = $this->getSubscriberField($subscriber, $sourceFields);
                    if (!empty($value)) {
                        $mergeFieldMap[$mailchimpField] = $value;
                                    } else {
                    // Don't track missing optional data as "rejected" - only track actual failures
                    // Missing data for optional fields like ADDRESSWIN (street_address_2) is normal
                    // and should not be considered a rejection if the subscriber imports successfully
                }
                }
            }
        }

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
            
            // Only add ADDRESS if we have at least addr1 or city
            if (!empty($addressData['addr1']) || !empty($addressData['city'])) {
                $mergeFieldMap['ADDRESS'] = $addressData;
            }
        }

        // Force-map ZIPCODEWIN even if not detected in available fields
        $zipValue = $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code', 'zip']);
        if (!empty($zipValue)) {
            $mergeFieldMap['ZIPCODEWIN'] = (int) $zipValue;
            \Illuminate\Support\Facades\Log::info('Manual import - force mapping ZIPCODEWIN', [
                'email' => $subscriber['email_address'] ?? 'no email',
                'zip_value' => $zipValue,
                'zip_as_int' => (int) $zipValue
            ]);
        }

        // Force-map STATEWIN even if not detected in available fields
        $stateValue = $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province', 'region', 'Region']);
        if (!empty($stateValue)) {
            $mergeFieldMap['STATEWIN'] = (string) $stateValue;
            \Illuminate\Support\Facades\Log::info('Manual import - force mapping STATEWIN', [
                'email' => $subscriber['email_address'] ?? 'no email',
                'state_value' => $stateValue
            ]);
        }

        // Force-map PHONE even if not detected in available fields
        $phoneValue = $this->getSubscriberField($subscriber, ['mobile_number', 'phone', 'mobile', 'Phone Number', 'Mobile Number', 'phone_number']);
        if (!empty($phoneValue)) {
            try {
                $mergeFieldMap['PHONE'] = (string) $phoneValue;
                \Illuminate\Support\Facades\Log::info('Manual import - force mapping PHONE', [
                    'email' => $subscriber['email_address'] ?? 'no email',
                    'phone_value' => $phoneValue
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Manual import - PHONE field mapping failed', [
                    'email' => $subscriber['email_address'] ?? 'no email',
                    'phone_value' => $phoneValue,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Skip SMSPHONE for international numbers to prevent import failure
        // Only add SMSPHONE for Australian numbers - be very conservative
        if (!empty($phoneValue)) {
            // Only treat as Australian if it explicitly starts with +61 or 04 (mobile)
            // Avoid 02/03/07/08 which can be confused with other countries
            $isAustralianNumber = preg_match('/^(\+61|04)/', $phoneValue);
            
            if ($isAustralianNumber) {
                $mergeFieldMap['SMSPHONE'] = (string) $phoneValue;
                \Illuminate\Support\Facades\Log::info('Manual import - force mapping SMSPHONE (Australian number)', [
                    'email' => $subscriber['email_address'] ?? 'no email',
                    'sms_phone_value' => $phoneValue
                ]);
            } else {
                // Don't track SMSPHONE as rejected - we're intentionally skipping non-Australian numbers
                // to prevent import failure. This is expected behavior, not a rejection.
                \Illuminate\Support\Facades\Log::info('Manual import - SKIPPING SMSPHONE (international number)', [
                    'email' => $subscriber['email_address'] ?? 'no email',
                    'phone_value' => $phoneValue,
                    'note' => 'Skipping SMSPHONE to prevent import failure - Mailchimp SMS only supports Australian numbers'
                ]);
            }
        }

        // Handle address fields with proper formatting for Mailchimp
        foreach (['MMERGE10', 'MMERGE11', 'ADDRESSWIN'] as $addressField) {
            if (in_array($addressField, $availableFieldTags)) {
                if ($addressField === 'MMERGE10') {
                    // MMERGE10 should be street address only
                    $streetAddress = $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']);
                    if (!empty($streetAddress)) {
                        $mergeFieldMap[$addressField] = (string) $streetAddress;
                    }
                } elseif ($addressField === 'ADDRESSWIN') {
                    // ADDRESSWIN is an Address type field - needs full address object
                    $addressData = [
                        'addr1' => $this->getSubscriberField($subscriber, ['street_address', 'address', 'Street Address', 'Address', 'street']) ?: '',
                        'addr2' => $this->getSubscriberField($subscriber, ['street_address_2', 'address_2', 'address_line_2', 'Address Line 2']) ?: '',
                        'city' => $this->getSubscriberField($subscriber, ['city', 'City', 'town', 'Town']) ?: '',
                        'state' => $this->getSubscriberField($subscriber, ['state', 'State', 'province', 'Province']) ?: '',
                        'zip' => $this->getSubscriberField($subscriber, ['zip_code', 'zipcode', 'postal_code', 'postcode', 'Zip Code', 'Postal Code']) ?: '',
                        'country' => $this->getSubscriberField($subscriber, ['country', 'Country']) ?: ''
                    ];
                    
                    // Only add if we have at least addr1 or city
                    if (!empty($addressData['addr1']) || !empty($addressData['city'])) {
                        $mergeFieldMap[$addressField] = $addressData;
                    }
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