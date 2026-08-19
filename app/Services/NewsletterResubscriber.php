<?php

namespace App\Services;

use App\Models\Events;
use App\Models\NewsletterResubscribeAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bringing a lapsed contact back onto the newsletter audience.
 *
 * This used to live inline in SignUpFormController. It is here because two callers
 * need it now: the subscription check, which has to answer the browser synchronously
 * so the form knows whether to show the compliance link, and the queued job that
 * follows a submission — where the contact's entry is already saved and the
 * Mailchimp round trips can take as long as they take.
 */
class NewsletterResubscriber
{
    // Newsletter audience names per MC account
    const ANZ_NEWSLETTER_AUDIENCE = 'Adventure Entertainment Newsletter ANZ';

    const USA_NEWSLETTER_AUDIENCE = 'Fly Fishing Film Tour';

    public function __construct(private MailchimpHostedForm $hostedForm) {}

    /**
     * Determine the Mailchimp account based on event country.
     * ANZ countries → 'anz', USA countries → 'usa'
     */
    public function accountForEvent(Events $event): string
    {
        $usaCountries = ['USA', 'USA & CANADA', 'Canada'];

        return in_array($event->event_country, $usaCountries) ? 'usa' : 'anz';
    }

    /**
     * Get the newsletter audience name for a given MC account.
     */
    public function audienceName(string $account): string
    {
        return $account === 'usa' ? self::USA_NEWSLETTER_AUDIENCE : self::ANZ_NEWSLETTER_AUDIENCE;
    }

    /**
     * Cache key holding pending newsletter resubscribes for an email until the
     * user submits the form and we know which location they picked.
     */
    public function pendingCacheKey(int $eventId, string $email): string
    {
        return 'newsletter_resub_pending:'.$eventId.':'.md5(strtolower(trim($email)));
    }

    /**
     * Find the newsletter audience list ID by name from Mailchimp.
     *
     * The audience → list ID mapping is effectively static, but this runs on the
     * public checkSubscription endpoint (fired repeatedly as users type), so calling
     * getLists() every time floods Mailchimp's 10-concurrent-connection limit and
     * triggers 429s. Cache the resolved ID so bursts of checks reuse one API call.
     */
    public function findListId(MailchimpService $mailchimpService, string $audienceName): ?string
    {
        $cacheKey = 'mailchimp_newsletter_list_id:'.md5($audienceName);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $lists = $mailchimpService->getLists();
        foreach ($lists as $list) {
            if ($list['name'] === $audienceName) {
                Cache::put($cacheKey, $list['id'], now()->addHours(6));

                return $list['id'];
            }
        }

        return null;
    }

    /**
     * Bring a lapsed contact back, by whichever route their current state needs.
     *
     * Archived contacts take the upsert endpoint; everyone else — unsubscribed,
     * cleaned, and pending — takes the ordinary resubscribe, which sets them straight
     * to subscribed. Pending deliberately does not wait on a double opt-in
     * confirmation: they have just filled in our form and pressed submit, so the
     * consent the confirmation email would ask for has already been given.
     */
    public function bringBack(MailchimpService $mailchimp, string $listId, string $email, ?string $currentStatus)
    {
        return $currentStatus === 'archived'
            ? $mailchimp->unarchive($listId, $email)
            : $mailchimp->resubscribe($listId, $email);
    }

    /**
     * Record what happened to one contact we tried to bring back, successes and
     * refusals alike.
     *
     * The per-location counters below only ever counted successes, so a refused
     * contact left no trace at all. This is what makes a "could not resubscribe"
     * report possible, per event and so per film.
     */
    public function recordAttempt(
        Events $event,
        string $email,
        string $outcome,
        array $info,
        ?string $detail = null,
        ?int $locationId = null,
    ): void {
        try {
            NewsletterResubscribeAttempt::create([
                'event_id' => $event->id,
                'location_id' => $locationId,
                'email' => $email,
                'mailchimp_account' => $info['account'] ?? null,
                'list_id' => $info['list_id'] ?? null,
                'list_name' => $info['list_name'] ?? null,
                'outcome' => $outcome,
                'detail' => $detail,
            ]);
        } catch (\Exception $e) {
            // Reporting must never cost someone their entry to the draw.
            Log::error('Could not record newsletter resubscribe attempt', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How the hosted form's answer is recorded in the report.
     *
     * Only Mailchimp saying the contact is on the list counts as a resubscribe. A
     * confirmation email is a step towards one, not one — the contact still has to
     * click it — and reporting it as success would overstate what happened.
     */
    public function outcomeForHostedForm(string $status): string
    {
        return match ($status) {
            MailchimpHostedForm::ACCEPTED,
            MailchimpHostedForm::ALREADY_SUBSCRIBED => NewsletterResubscribeAttempt::RESUBSCRIBED,
            MailchimpHostedForm::CONFIRMATION_SENT => NewsletterResubscribeAttempt::CONFIRMATION_SENT,
            // The form was busy, which says nothing about this contact. Filing it as
            // blocked would write off someone who just opted in at a venue — and on a
            // busy night that is most of the queue, since the form starts refusing
            // after a couple of dozen. Deferred instead, and the drip retries it.
            MailchimpHostedForm::THROTTLED => NewsletterResubscribeAttempt::DEFERRED,
            // Rejected or not configured: the contact is still stuck where they were,
            // so the report must keep saying so.
            default => NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
        };
    }

    /**
     * Attach the location the user just picked to the resubscribe attempt that
     * checkSubscription already recorded for them (it ran before a location existed).
     */
    private function attributePendingResubToLocation(Events $event, string $email, ?int $locationId): void
    {
        if (! $locationId) {
            return;
        }

        try {
            // Fetched then saved rather than UPDATE ... ORDER BY ... LIMIT, which SQLite
            // rejects unless it was compiled with the update/delete-limit option.
            $attempt = NewsletterResubscribeAttempt::where('event_id', $event->id)
                ->where('email', $email)
                ->where('outcome', NewsletterResubscribeAttempt::RESUBSCRIBED)
                ->whereNull('location_id')
                ->latest('id')
                ->first();

            if ($attempt) {
                $attempt->location_id = $locationId;
                $attempt->save();
            }
        } catch (\Exception $e) {
            // Reporting must never cost someone their entry to the draw.
            Log::error('Could not attribute newsletter resubscribe to a location', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everything that used to run inline after a sign-up was saved: attribute a
     * resubscribe the subscription check already made, then bring the contact back
     * if they are still off the list.
     *
     * The caller's entry is saved before this runs, so nothing in here can cost
     * someone their place in the draw — the worst case is a logged failure.
     */
    public function resubscribeAfterSubmit(Events $event, string $email, ?int $locationId = null): void
    {
        // If checkSubscription already resubbed this email, attribute it to the picked
        // location. The attempt row was written before the user chose one, so it is
        // backfilled here rather than recorded a second time.
        $pendingKey = $this->pendingCacheKey($event->id, $email);
        if (Cache::has($pendingKey)) {
            Cache::pull($pendingKey);
            $this->attributePendingResubToLocation($event, $email, $locationId);
        }

        $account = null;
        $listId = null;
        $audienceName = null;

        try {
            $account = $this->accountForEvent($event);
            $audienceName = $this->audienceName($account);
            $mailchimpService = new MailchimpService($account);
            $listId = $this->findListId($mailchimpService, $audienceName);

            if (! $listId) {
                return;
            }

            $listInfo = ['account' => $account, 'list_id' => $listId, 'list_name' => $audienceName];
            $status = $mailchimpService->getSubscriberStatus($listId, $email);

            if (! $status['exists'] || $status['status'] === 'subscribed') {
                return;
            }

            $wasArchived = $status['status'] === 'archived';
            $resubResult = $this->bringBack($mailchimpService, $listId, $email, $status['status']);

            if (is_array($resubResult) && ($resubResult['status'] ?? null) === 'compliance_skipped') {
                // The API will not take this contact back at any privilege level. They
                // have just filled in this form and pressed submit, so their own sign-up
                // is relayed to the audience's hosted form — the one route Mailchimp
                // accepts, because it treats that as the contact opting in themselves.
                $hosted = $this->hostedForm->submit($account, $email);

                $this->recordAttempt(
                    $event,
                    $email,
                    $this->outcomeForHostedForm($hosted['status']),
                    $listInfo,
                    // Mailchimp's refusal and its answer to the form are both worth
                    // keeping — the second explains the first.
                    trim(($resubResult['detail'] ?? '').' → '.($hosted['detail'] ?? $hosted['status']), ' →'),
                    $locationId,
                );

                Log::info('Compliance-state contact relayed to the hosted Mailchimp form', [
                    'email' => $email,
                    'account' => $account,
                    'audience' => $audienceName,
                    'hosted_form_status' => $hosted['status'],
                ]);

                return;
            }

            $this->recordAttempt(
                $event,
                $email,
                NewsletterResubscribeAttempt::RESUBSCRIBED,
                $listInfo,
                // Worth spelling out in the report: an unarchive and a plain
                // resubscribe are the same outcome but not the same starting point.
                $wasArchived ? 'Unarchived and resubscribed' : null,
                $locationId,
            );

            Log::info('Auto-resubscribed to newsletter on form submit', [
                'email' => $email,
                'account' => $account,
                'audience' => $audienceName,
                'was_archived' => $wasArchived,
                'previous_status' => $status['status'],
            ]);
        } catch (\Exception $e) {
            // Deliberately not recorded here. This runs on a job that retries, and
            // writing a failure per attempt put the same contact in the report three
            // times — twice as failed and once as resubscribed, if a later try worked.
            // ResubscribeSignupContactJob::failed() records it once, when the retries
            // are actually exhausted.
            Log::warning('Newsletter resubscribe attempt failed, will retry', [
                'event_id' => $event->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
