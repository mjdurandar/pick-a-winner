<?php

namespace App\Jobs;

use App\Models\SignUpForm;
use App\Services\AutoMailchimpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put one sign-up onto the event's Mailchimp audience, after the fact.
 *
 * This used to run inline in the submit handler, so a person at a venue waited on
 * two or three Mailchimp round trips — a status check, sometimes a resubscribe, and
 * the upsert — before the page came back. Their entry is saved before this is
 * dispatched, so a slow or rate-limiting Mailchimp can no longer hold up the queue
 * at the door, and a failure retries instead of being logged and forgotten.
 *
 * The row is looked up again here rather than carried in the payload: the table is
 * per-form, and re-reading it means the sync always sends what is currently stored.
 */
class SyncSignupToMailchimpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A status check plus an upsert; generous, but nowhere near a wedged worker. */
    public int $timeout = 120;

    public int $tries = 3;

    /** Rate limiting is the usual failure, so back off rather than hammering. */
    public array $backoff = [60, 300];

    public function __construct(
        public int $eventId,
        public int $rowId,
        public int $locationId,
    ) {}

    public function handle(AutoMailchimpService $autoMailchimp): void
    {
        // The table name comes from the form record, never from the caller, so a
        // job payload can never point this at an arbitrary table.
        $form = SignUpForm::where('event_id', $this->eventId)->first();

        if (! $form || blank($form->table_name)) {
            Log::warning('Skipping sign-up sync: no form table for this event', [
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $subscriber = DB::table($form->table_name)->where('id', $this->rowId)->first();

        if (! $subscriber) {
            // Deleted between submit and sync. Nothing to send, and nothing wrong.
            Log::info('Skipping sign-up sync: entry no longer exists', [
                'event_id' => $this->eventId,
                'row_id' => $this->rowId,
            ]);

            return;
        }

        // rethrow: the service logs its own failures and would otherwise swallow
        // them, leaving this job to report success on a sync that did not happen.
        $autoMailchimp->syncSubscriber($subscriber, $this->locationId, rethrow: true);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Sign-up Mailchimp sync failed for good', [
            'event_id' => $this->eventId,
            'row_id' => $this->rowId,
            'location_id' => $this->locationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
