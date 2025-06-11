<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\MailchimpService;
use App\Jobs\SyncMailchimpSubscribersBatch;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

class AutoMailchimpService
{
    protected $mailchimpService;
    protected $config;
    protected const BATCH_SIZE = 100; // Process 100 subscribers at a time

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
            $locationIds = Location::pluck('id')->toArray();
            
            // Update enabled_locations to include all locations
            $this->config['enabled_locations'] = $locationIds;
            $this->saveConfig();
        } else {
            // Get all location IDs for initial config
            $locationIds = Location::pluck('id')->toArray();
            
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
        Log::info('Attempting to sync subscribers batch', [
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

            // Get delay minutes from config
            $delayMinutes = intval($this->config['delay_minutes'] ?? 0);
            Log::info('Sync delay configuration', ['delay_minutes' => $delayMinutes]);

            // Generate a unique batch ID
            $batchId = "mailchimp_sync_{$locationId}_" . time();

            // Create initial batch record
            DB::table('job_batches')->insert([
                'id' => $batchId,
                'name' => $batchId,
                'total_jobs' => ceil(count($subscribers) / self::BATCH_SIZE),
                'pending_jobs' => ceil(count($subscribers) / self::BATCH_SIZE),
                'failed_jobs' => 0,
                'failed_job_ids' => '[]',
                'options' => json_encode([
                    'location_id' => $locationId,
                    'location_name' => $locationName,
                    'total_subscribers' => count($subscribers)
                ]),
                'created_at' => time(),
                'cancelled_at' => null,
                'finished_at' => null
            ]);

            // Process subscribers in batches
            $chunks = array_chunk($subscribers, self::BATCH_SIZE);
            foreach ($chunks as $chunk) {
                $job = new SyncMailchimpSubscribersBatch(
                    $chunk, 
                    $listId, 
                    $tags, 
                    $location->name,
                    $locationId,
                    $batchId
                );
                
                if ($delayMinutes > 0) {
                    $job->delay(now()->addMinutes($delayMinutes));
                }
                
                dispatch($job);
            }

            Log::info('Batched sync jobs dispatched', [
                'total_subscribers' => count($subscribers),
                'number_of_batches' => count($chunks),
                'batch_size' => self::BATCH_SIZE,
                'delay_minutes' => $delayMinutes,
                'batch_id' => $batchId
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-sync failed for subscriber batch', [
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