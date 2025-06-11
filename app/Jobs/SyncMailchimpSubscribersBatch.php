<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\MailchimpService;
use Illuminate\Support\Facades\Log;

class SyncMailchimpSubscribersBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $subscribers;
    protected $listId;
    protected $tags;
    protected $locationName;

    /**
     * Create a new job instance.
     */
    public function __construct(array $subscribers, string $listId, array $tags, string $locationName)
    {
        $this->subscribers = $subscribers;
        $this->listId = $listId;
        $this->tags = $tags;
        $this->locationName = $locationName;
    }

    /**
     * Execute the job.
     */
    public function handle(MailchimpService $mailchimpService): void
    {
        Log::info('Starting batch sync for location', [
            'location' => $this->locationName,
            'subscriber_count' => count($this->subscribers),
            'tags' => $this->tags
        ]);

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($this->subscribers as $subscriber) {
            try {
                if (empty($subscriber->email_address)) {
                    $results['failed']++;
                    $results['errors'][] = "Skipped subscriber: Missing email address";
                    continue;
                }

                $mailchimpService->addSubscriberToList(
                    $this->listId,
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
                        'age' => $subscriber->age ?? '',
                    ],
                    $this->tags
                );
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Failed to import {$subscriber->email_address}: " . substr($e->getMessage(), 0, 200);
                Log::error('Failed to import subscriber in batch', [
                    'email' => $subscriber->email_address,
                    'error' => $e->getMessage(),
                    'location' => $this->locationName
                ]);
            }
        }

        Log::info('Completed batch sync for location', [
            'location' => $this->locationName,
            'results' => $results
        ]);
    }
} 