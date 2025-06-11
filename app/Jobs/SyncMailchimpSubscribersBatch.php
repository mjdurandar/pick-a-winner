<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\MailchimpService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncMailchimpSubscribersBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $subscribers;
    protected $listId;
    protected $tags;
    protected $locationName;
    protected $locationId;
    protected $batchId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $subscribers, string $listId, array $tags, string $locationName, int $locationId, string $batchId)
    {
        $this->subscribers = $subscribers;
        $this->listId = $listId;
        $this->tags = $tags;
        $this->locationName = $locationName;
        $this->locationId = $locationId;
        $this->batchId = $batchId;
    }

    /**
     * Execute the job.
     */
    public function handle(MailchimpService $mailchimpService): void
    {
        Log::info('Starting batch sync for location', [
            'location' => $this->locationName,
            'subscriber_count' => count($this->subscribers),
            'tags' => $this->tags,
            'batch_id' => $this->batchId
        ]);

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'processed' => []
        ];

        foreach ($this->subscribers as $subscriber) {
            try {
                if (empty($subscriber->email_address)) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'email' => 'unknown',
                        'error' => 'Missing email address'
                    ];
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
                $results['processed'][] = [
                    'email' => $subscriber->email_address,
                    'status' => 'success'
                ];

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'email' => $subscriber->email_address ?? 'unknown',
                    'error' => $e->getMessage()
                ];
                $results['processed'][] = [
                    'email' => $subscriber->email_address,
                    'status' => 'failed',
                    'reason' => $e->getMessage()
                ];
            }
        }

        // Update batch information
        DB::table('job_batches')
            ->where('id', $this->batchId)
            ->update([
                'options' => json_encode([
                    'results' => $results,
                    'location_id' => $this->locationId,
                    'location_name' => $this->locationName,
                    'list_id' => $this->listId,
                    'tags' => $this->tags
                ])
            ]);

        Log::info('Completed batch sync for location', [
            'location' => $this->locationName,
            'results' => $results,
            'batch_id' => $this->batchId
        ]);
    }
} 