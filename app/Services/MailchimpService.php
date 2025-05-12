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

    public function addSubscriberToList($listId, $subscriber)
    {
        $response = Http::withBasicAuth('anystring', $this->apiKey)
            ->post("{$this->baseUrl}/lists/{$listId}/members", [
                'email_address' => $subscriber['email_address'],
                'status' => 'subscribed',
                'merge_fields' => [
                    'FNAME' => $subscriber['first_name'] ?? '',
                    'LNAME' => $subscriber['last_name'] ?? '',
                    'PHONE' => $subscriber['mobile_number'] ?? '',
                    'ADDRESS' => [
                        'addr1' => $subscriber['street_address'] ?? '',
                        'addr2' => $subscriber['street_address_2'] ?? '',
                        'city' => $subscriber['city'] ?? '',
                        'state' => $subscriber['state'] ?? '',
                        'zip' => $subscriber['zip_code'] ?? '',
                        'country' => $subscriber['country'] ?? '',
                    ],
                ],
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Failed to add subscriber to Mailchimp list: ' . $response->body());
    }
} 