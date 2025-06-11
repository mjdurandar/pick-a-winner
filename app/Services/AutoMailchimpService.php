<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\MailchimpService;
use App\Models\Location;
use App\Services\MailchimpLogService;

class AutoMailchimpService
{
    protected $mailchimpService;
    protected $config;
    protected $logService;

    public function __construct(MailchimpService $mailchimpService, MailchimpLogService $logService)
    {
        $this->mailchimpService = $mailchimpService;
        $this->logService = $logService;
        $this->loadConfig();
    }

    protected function loadConfig()
    {
        $configPath = storage_path('app/mailchimp-config.json');
        if (file_exists($configPath)) {
            $this->config = json_decode(file_get_contents($configPath), true);
            
            // Get all location IDs from the database
            $locationIds = Location::pluck('id')->toArray();
            
            // Update enabled_locations to include all locations
            $this->config['enabled_locations'] = $locationIds;
            $this->saveConfig();
        } else {
            // Get all location IDs for initial config
            $locationIds = Location::pluck('id')->toArray();
            
            $this->config = [
                'auto_sync' => false,
                'default_list_id' => '',
                'default_tags' => [],
                'enabled_locations' => $locationIds,
                'film_tour' => 'WM'
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
        $locationIds = Location::pluck('id')->toArray();
        
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

    public function syncSubscribers($subscribers, $locationId)
    {
        Log::info('Attempting to sync subscribers', [
            'count' => count($subscribers),
            'location_id' => $locationId,
            'auto_sync_enabled' => $this->isAutoSyncEnabled(),
            'location_enabled' => $this->isLocationEnabled($locationId)
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
            if (empty($listId)) {
                Log::warning('Auto-sync failed: No default Mailchimp list configured');
                return;
            }
            
            // Get location name for tags
            $location = Location::find($locationId);
            if (!$location) {
                Log::warning('Location not found for auto-sync', ['location_id' => $locationId]);
                return;
            }

            // Generate location-specific tags
            $filmTour = $this->config['film_tour'] ?? 'WM';
            $locationName = $location->name;
            $locationFirstWord = explode(' ', $locationName)[0];
            
            // Create the SOURCE and SHOW tags
            $sourceTag = "SOURCE - {$filmTour} " . strtoupper($locationFirstWord) . " COMP 2025";
            $showTag = "SHOW - " . strtoupper($locationFirstWord);
            
            // Combine with default tags
            $tags = array_merge(
                [$sourceTag, $showTag],
                $this->config['default_tags'] ?? []
            );

            // Process each subscriber immediately
            foreach ($subscribers as $subscriber) {
                try {
                    $this->mailchimpService->addSubscriberToList(
                        $listId,
                        [
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
                        ],
                        $tags
                    );

                    // Log successful import
                    $this->logService->logImport($locationName, [
                        'success' => true,
                        'email' => $subscriber->email_address ?? 'no email',
                        'tags' => $tags
                    ]);

                    Log::info('Successfully synced subscriber', [
                        'email' => $subscriber->email_address ?? 'no email',
                        'location' => $locationName
                    ]);
                } catch (\Exception $e) {
                    // Log failed import
                    $this->logService->logImport($locationName, [
                        'success' => false,
                        'email' => $subscriber->email_address ?? 'no email',
                        'error' => $e->getMessage(),
                        'tags' => $tags
                    ]);

                    Log::error('Failed to sync subscriber', [
                        'email' => $subscriber->email_address ?? 'no email',
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Auto-sync failed', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    // Legacy method for backward compatibility
    public function syncSubscriber($subscriber, $locationId)
    {
        $this->syncSubscribers([$subscriber], $locationId);
    }
} 