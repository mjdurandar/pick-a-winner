<?php

namespace App\Jobs;

use App\Models\Events;
use App\Models\NewsletterResubscribeAttempt;
use App\Services\NewsletterResubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Bring a sign-up back onto the newsletter audience, after the fact.
 *
 * This used to run inline in the submit handler, which meant every entry at a venue
 * waited on three or four Mailchimp round trips — and on a compliance-blocked
 * contact, on the hosted form as well — before the page came back. The entry is
 * saved before this is dispatched, so the data is never at risk if Mailchimp is slow,
 * rate-limiting, or down.
 */
class ResubscribeSignupContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Long enough for the hosted-form relay, which waits out the form's own rate
     * limit, but not so long that a wedged request holds a worker all day.
     */
    public int $timeout = 300;

    public int $tries = 3;

    /**
     * Mailchimp rate limits are the usual failure here, so back off rather than
     * hammering: a minute, then five.
     */
    public array $backoff = [60, 300];

    public function __construct(
        public int $eventId,
        public string $email,
        public ?int $locationId = null,
    ) {}

    public function handle(NewsletterResubscriber $resubscriber): void
    {
        $event = Events::find($this->eventId);

        if (! $event) {
            Log::warning('Skipping newsletter resubscribe for a deleted event', [
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $resubscriber->resubscribeAfterSubmit($event, $this->email, $this->locationId);
    }

    /**
     * Out of retries. This is where the failure is recorded, not in the service —
     * once per contact, after the last try, so the report shows one row rather than
     * one per attempt. Without this a contact the queue gave up on would leave no
     * trace in the report at all.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Newsletter resubscribe job failed for good', [
            'event_id' => $this->eventId,
            'email' => $this->email,
            'location_id' => $this->locationId,
            'error' => $exception->getMessage(),
        ]);

        $event = Events::find($this->eventId);

        if (! $event) {
            return;
        }

        /** @var NewsletterResubscriber $resubscriber */
        $resubscriber = app(NewsletterResubscriber::class);
        $account = $resubscriber->accountForEvent($event);

        $resubscriber->recordAttempt(
            $event,
            $this->email,
            NewsletterResubscribeAttempt::FAILED,
            // The audience is known without an API call; the list id is not, and this
            // runs after a failure, so it is not worth another call to Mailchimp.
            ['account' => $account, 'list_id' => null, 'list_name' => $resubscriber->audienceName($account)],
            $exception->getMessage(),
            $this->locationId,
        );
    }
}
