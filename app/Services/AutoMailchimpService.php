<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\MailchimpService;

class AutoMailchimpService
{
    protected $mailchimpService;
    protected $config;

    public function __construct(MailchimpService $mailchimpService)
    {
        $this->mailchimpService = $mailchimpService;
        $this->loadConfig();
    }

    protected function loadConfig()
    {
        $configPath = storage_path('app/mailchimp-config.json');
        if (file_exists($configPath)) {
            $this->config = json_decode(file_get_contents($configPath), true);
            
            // Get all location IDs from the database
            $locationIds = \App\Models\Location::pluck('id')->toArray();
            
            // Update enabled_locations to include all locations
            $this->config['enabled_locations'] = $locationIds;
            $this->saveConfig();
        } else {
            // Get all location IDs for initial config
            $locationIds = \App\Models\Location::pluck('id')->toArray();
            
            $this->config = [
                'auto_sync' => false,
                'delay_minutes' => 0,
                'default_list_id' => '',
                'default_tags' => [],
                'enabled_locations' => $locationIds
            ];
            $this->saveConfig();
        }
    }

    protected function saveConfig()
    {
        $configPath = storage_path('app/mailchimp-config.json');
        file_put_contents($configPath, json_encode($this->config, JSON_PRETTY_PRINT));
    }

    public function updateConfig($settings)
    {
        // Get all location IDs
        $locationIds = \App\Models\Location::pluck('id')->toArray();
        
        // Merge settings but ensure enabled_locations includes all locations
        $this->config = array_merge($this->config, $settings);
        $this->config['enabled_locations'] = $locationIds;
        
        $this->saveConfig();
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function isAutoSyncEnabled()
    {
        return $this->config['auto_sync'] ?? false;
    }

    public function isLocationEnabled($locationId)
    {
        return in_array($locationId, $this->config['enabled_locations'] ?? []);
    }

    public function syncSubscriber($subscriber, $locationId)
    {
        Log::info('Attempting to sync subscriber', [
            'email' => $subscriber->email_address ?? 'no email',
            'location_id' => $locationId,
            'auto_sync_enabled' => $this->isAutoSyncEnabled(),
            'location_enabled' => $this->isLocationEnabled($locationId),
            'config' => $this->config
        ]);

        if (!$this->isAutoSyncEnabled()) {
            Log::info('Auto-sync is disabled');
            return;
        }

        if (!$this->isLocationEnabled($locationId)) {
            Log::info('Location is not enabled for auto-sync', ['location_id' => $locationId]);
            return;
        }

        try {
            $listId = $this->config['default_list_id'];
            
            // Get location name for tags
            $location = \App\Models\Location::find($locationId);
            if (!$location) {
                Log::warning('Location not found for auto-sync', ['location_id' => $locationId]);
                return;
            }

            // Generate location-specific tags
            $filmTour = $this->config['film_tour'] ?? 'WM';
            $locationName = $location->name;
            $locationFirstWord = explode(' ', $locationName)[0]; // Get first word only
            
            // Create the SOURCE and SHOW tags
            $sourceTag = "SOURCE - {$filmTour} " . strtoupper($locationFirstWord) . " COMP 2025";
            $showTag = "SHOW - " . strtoupper($locationFirstWord); // Keep full name for SHOW tag
            
            // Combine with default tags
            $tags = array_merge(
                [$sourceTag, $showTag],
                $this->config['default_tags'] ?? []
            );

            if (empty($listId)) {
                Log::warning('Auto-sync failed: No default Mailchimp list configured');
                return;
            }

            // Get delay minutes from config
            $delayMinutes = intval($this->config['delay_minutes'] ?? 0);
            Log::info('Sync delay configuration', ['delay_minutes' => $delayMinutes]);

            // Prepare subscriber data
            $subscriberData = [
                'email_address' => $subscriber->email_address ?? '',
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
                'age' => $subscriber->age ?? '',
            ];

            if ($delayMinutes > 0) {
                Log::info('Scheduling delayed sync', [
                    'email' => $subscriber->email_address,
                    'delay_minutes' => $delayMinutes
                ]);

                // Use Laravel's dispatch helper with delay
                dispatch(function() use ($listId, $subscriberData, $tags) {
                    try {
                        Log::info('Executing delayed sync', [
                            'email' => $subscriberData['email_address']
                        ]);
                        
                        $this->mailchimpService->addSubscriberToList(
                            $listId,
                            $subscriberData,
                            $tags
                        );

                        Log::info('Delayed sync completed successfully', [
                            'email' => $subscriberData['email_address']
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Delayed sync failed', [
                            'email' => $subscriberData['email_address'],
                            'error' => $e->getMessage()
                        ]);
                    }
                })->delay(now()->addMinutes($delayMinutes));

                Log::info('Delayed sync scheduled', [
                    'email' => $subscriber->email_address,
                    'scheduled_time' => now()->addMinutes($delayMinutes)
                ]);
            } else {
                // Sync immediately
                Log::info('Executing immediate sync', [
                    'email' => $subscriber->email_address
                ]);

                $this->mailchimpService->addSubscriberToList(
                    $listId,
                    $subscriberData,
                    $tags
                );

                Log::info('Immediate sync completed', [
                    'email' => $subscriber->email_address
                ]);
            }

            Log::info('Auto-sync process completed', [
                'email' => $subscriber->email_address,
                'location_id' => $locationId,
                'delay_minutes' => $delayMinutes,
                'tags' => $tags
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-sync failed for subscriber', [
                'email' => $subscriber->email_address ?? 'no email',
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
        }
    }
} 