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
                if ($ageStr === 'under 21') {
                    $ageValue = -21;
                } elseif ($ageStr === '22-44') {
                    $ageValue = 22;
                } elseif ($ageStr === '45+') {
                    $ageValue = 45;
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
} 