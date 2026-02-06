<?php

namespace App\Jobs;

use App\Services\MailchimpLocationImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Imports one location to Mailchimp (ticket and/or win form). Dispatched by EventImportAllToMailchimpJob
 * so each location runs as a separate job and avoids long-running timeouts.
 */
class EventImportLocationToMailchimpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Per-location timeout (e.g. 30 min) so one slow location does not block the queue. */
    public $timeout = 1800;

    public $tries = 1;

    public function __construct(
        public int $eventId,
        public string $listId,
        public string $mailchimpAccount,
        public ?string $listName,
        /** @var array{location_id: int, import_ticket: bool, import_form: bool, attendees: array, tags: array, form_tags: array} */
        public array $locationPayload,
        public ?int $userId,
        public ?string $importBatchId = null,
        public ?array $fieldMapping = null
    ) {
    }

    public function handle(): void
    {
        $locationId = (int) ($this->locationPayload['location_id'] ?? 0);
        try {
            $service = new MailchimpLocationImportService;
            $result = $service->runForOneLocation(
                $this->eventId,
                $this->listId,
                $this->mailchimpAccount,
                $this->listName,
                $this->locationPayload,
                $this->userId ?? 0,
                $this->fieldMapping
            );
            if ($result['skipped']) {
                Log::info('Event import location (job): location skipped (no data or not found)', [
                    'event_id' => $this->eventId,
                    'location_id' => $locationId,
                    'import_batch_id' => $this->importBatchId,
                ]);
            } else {
                Log::info('Event import location (job): completed', [
                    'event_id' => $this->eventId,
                    'location_id' => $locationId,
                    'import_batch_id' => $this->importBatchId,
                    'subscribers_imported' => $result['imported'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Event import location (job): failed', [
                'event_id' => $this->eventId,
                'location_id' => $locationId,
                'import_batch_id' => $this->importBatchId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
