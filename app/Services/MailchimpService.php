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
                }
            }
        }

        // Format tags as simple strings
        $tagsData = array_map(function($tag) {
            return (string)$tag;
        }, $tags);

        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->post("{$this->baseUrl}/lists/{$listId}/members", [
                'email_address' => $subscriber['email_address'],
                'status' => 'subscribed',
                'merge_fields' => [
                    'FNAME' => $subscriber['first_name'] ?? '',
                    'LNAME' => $subscriber['last_name'] ?? '',
                    'PHONE' => $subscriber['mobile_number'] ?? '',
                    'GENDER' => $subscriber['gender'] ?? '',
                    'AGE' => $ageValue,
                    'SMSPHONE' => isset($subscriber['mobile_number']) ? $this->formatPhone($subscriber['mobile_number']) : '',
                    'ADDRESS' => [
                        'addr1' => $subscriber['street_address'] ?? '',
                        'addr2' => $subscriber['street_address_2'] ?? '',
                        'city' => $subscriber['city'] ?? '',
                        'state' => $subscriber['state'] ?? '',
                        'zip' => $subscriber['zip_code'] ?? '',
                        'country' => $subscriber['country'] ?? '',
                    ],
                ],
                'tags' => $tagsData
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Failed to add subscriber to Mailchimp list: ' . $response->body());
    }
} 