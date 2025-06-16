<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\MailchimpService;
use App\Services\MailchimpLogService;

class AutoMailchimpService
{
    protected $mailchimpService;
    protected $logService;
    protected $settingsKey = 'mailchimp_autosync_settings_';

    public function __construct(MailchimpService $mailchimpService, MailchimpLogService $logService)
    {
        $this->mailchimpService = $mailchimpService;
        $this->logService = $logService;
    }

    public function getSettings($eventId)
    {
        return Cache::get($this->settingsKey . $eventId, [
            'auto_sync' => false,
            'default_list_id' => '',
            'default_tags' => [],
            'enabled_locations' => [],
            'film_tour' => 'WM',
            'event_id' => $eventId
        ]);
    }

    public function updateSettings($settings)
    {
        $eventId = $settings['event_id'];
        Cache::put($this->settingsKey . $eventId, $settings);
        return $settings;
    }

    public function syncSubscriber($subscriber, $locationId)
    {
        try {
            $location = Location::with('event')->find($locationId);
            if (!$location) {
                Log::error('Location not found', ['location_id' => $locationId]);
                return;
            }

            $settings = $this->getSettings($location->event_id);
            
            // Generate location-specific tags regardless of auto-sync status
            $locationName = $location->name;
            $filmTour = $settings['film_tour'];
            $year = date('Y');
            $locationFirstWord = explode(' ', $locationName)[0];
            
            $sourceTag = "SOURCE - " . strtoupper($filmTour) . " " . strtoupper($locationFirstWord) . " COMP " . $year;
            $showTag = "SHOW - " . strtoupper($locationFirstWord);
            
            // Combine with default tags
            $tags = array_merge(
                [$sourceTag, $showTag],
                is_array($settings['default_tags']) ? $settings['default_tags'] : []
            );

            // Check if auto-sync is enabled and has default list
            if (!$settings['auto_sync'] || !$settings['default_list_id']) {
                Log::info('Auto-sync disabled or no default list configured', [
                    'event_id' => $location->event_id,
                    'auto_sync' => $settings['auto_sync'],
                    'default_list_id' => $settings['default_list_id']
                ]);
                
                // Still log the attempt even if auto-sync is disabled
                $this->logService->logImport($locationId, $locationName, [
                    'success' => false,
                    'email' => $subscriber->email_address ?? 'no email',
                    'error' => 'Auto-sync is disabled or no default list configured',
                    'tags' => $tags
                ]);
                
                return;
            }

            // Add to Mailchimp
            $this->mailchimpService->addSubscriberToList(
                $settings['default_list_id'],
                [
                    'email_address' => $subscriber->email_address,
                    'first_name' => $subscriber->first_name ?? '',
                    'last_name' => $subscriber->last_name ?? '',
                    'mobile_number' => $subscriber->mobile_number ?? '',
                    'street_address' => $subscriber->street_address ?? '',
                    'street_address_2' => $subscriber->street_address_2 ?? '',
                    'city' => $subscriber->city ?? '',
                    'state' => $subscriber->state ?? '',
                    'zip_code' => $subscriber->zip_code ?? '',
                    'country' => $subscriber->country ?? '',
                    'gender' => $subscriber->gender ?? '',
                    'age' => $subscriber->age ?? ''
                ],
                $tags
            );

            // Log successful import
            Log::info('About to log successful import', [
                'locationId' => $locationId,
                'locationName' => $locationName,
                'email' => $subscriber->email_address ?? 'no email',
                'tags' => $tags
            ]);

            $this->logService->logImport($locationId, $locationName, [
                'success' => true,
                'email' => $subscriber->email_address ?? 'no email',
                'tags' => $tags
            ]);

            Log::info('Subscriber auto-synced to Mailchimp', [
                'email' => $subscriber->email_address,
                'location' => $locationName,
                'tags' => $tags
            ]);

        } catch (\Exception $e) {
            // Log failed import
            Log::error('About to log failed import', [
                'locationId' => $locationId,
                'locationName' => $locationName ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            $this->logService->logImport($locationId, $locationName ?? 'unknown', [
                'success' => false,
                'email' => $subscriber->email_address ?? 'no email',
                'error' => $e->getMessage(),
                'tags' => $tags ?? []
            ]);

            Log::error('Failed to auto-sync subscriber', [
                'error' => $e->getMessage(),
                'location_id' => $locationId,
                'subscriber' => $subscriber->email_address ?? 'unknown'
            ]);
        }
    }

    public function syncSubscribers($subscribers, $locationId)
    {
        foreach ($subscribers as $subscriber) {
            $this->syncSubscriber($subscriber, $locationId);
        }
    }
} 