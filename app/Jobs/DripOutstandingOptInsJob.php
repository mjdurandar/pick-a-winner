<?php

namespace App\Jobs;

use App\Models\MailchimpFormRelayRun;
use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;
use App\Models\NewsletterResubscribeAttempt;
use App\Services\MailchimpApi;
use App\Services\MailchimpCredentialResolver;
use App\Services\MailchimpHostedForm;
use App\Services\OptInRelay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works through the contacts an import could not finish, a few at a time.
 *
 * The hosted signup form rate-limits and Mailchimp publishes no figure for it, so
 * every run of this job is an experiment: it relays at the pacing the last run
 * settled on, stops the moment the form pushes back, and records how far it got.
 * MailchimpFormRelayRun turns that history into the next run's pacing — which is
 * how the schedule arrives at the real limit without anyone having to know it.
 *
 * Only imports that were previewed, consented to and actually sent are eligible.
 * This never introduces contacts an admin has not already put through the wizard.
 */
class DripOutstandingOptInsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?string $onlyAccount = null) {}

    protected MailchimpHostedForm $hostedForm;

    public function handle(
        OptInRelay $relay,
        MailchimpCredentialResolver $credentials,
        MailchimpHostedForm $hostedForm,
    ): void {
        $this->hostedForm = $hostedForm;

        foreach ($this->accountsWithWork() as $account) {
            if ($this->onlyAccount && $account !== $this->onlyAccount) {
                continue;
            }

            $plan = MailchimpFormRelayRun::plan($account);

            if (! $plan['may_run']) {
                continue;
            }

            try {
                $this->drip($account, $plan, $relay, $credentials);
            } catch (Throwable $e) {
                // One account's failure must not stop the other's.
                Log::error('Opt-in drip failed', ['account' => $account, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param  array{interval: int, batch: int}  $plan
     */
    protected function drip(
        string $account,
        array $plan,
        OptInRelay $relay,
        MailchimpCredentialResolver $credentials,
    ): void {
        $run = MailchimpFormRelayRun::create([
            'account' => $account,
            'started_at' => now(),
            'interval_seconds' => $plan['interval'],
            'planned' => $plan['batch'],
        ]);

        $api = MailchimpApi::for($credentials->resolve($account));
        $accepted = 0;
        $throttled = false;

        // Sign-up form first. These people stood at a venue and opted in minutes or
        // hours ago; an imported contact has been waiting since whenever the file was
        // uploaded and will not mind waiting a little longer.
        [$accepted, $throttled] = $this->dripDeferredSignups($account, $api, $plan, $accepted);

        $remaining = max(0, $plan['batch'] - $accepted);

        foreach ($throttled || $remaining === 0 ? [] : $this->outstanding($account, $remaining) as $row) {
            $import = $row->import;

            // Asked again rather than assumed: the row was classified when the file
            // was previewed, and the contact may have come back since.
            $refusal = $api->resubscribe($import->audience_id, $row->email);

            if ($refusal === null) {
                $row->update([
                    'outcome' => MailchimpImportRow::RESUBSCRIBED,
                    'processed_at' => now(),
                ]);
                $accepted++;

                continue;
            }

            $outcome = $relay->relay($import, $api, $row->row_number, $row->email, $refusal);

            if ($outcome === OptInRelay::THROTTLED) {
                // The form has had enough. Stop at once — pressing on is what turns a
                // short block into a long one, and this run has already learned the
                // one thing it came to find out.
                $throttled = true;
                break;
            }

            if ($outcome === OptInRelay::RECOVERED) {
                $accepted++;
            }

            sleep($plan['interval']);
        }

        $run->settle($accepted, $throttled);

        Log::info('Opt-in drip finished', [
            'account' => $account,
            'accepted' => $accepted,
            'throttled' => $throttled,
            'interval_used' => $plan['interval'],
            'next_interval' => $run->fresh()->interval_seconds,
            'next_attempt_at' => $run->fresh()->next_attempt_at?->toDateTimeString(),
        ]);
    }

    /**
     * Retry the people whose opt-in arrived while the form was rate limiting.
     *
     * They filled in the sign-up form themselves and were told nothing was wrong,
     * because a failed resubscribe never blocks entry to the draw. Leaving them
     * unretried would make that silence a lie.
     *
     * @param  array{interval: int, batch: int}  $plan
     * @return array{0: int, 1: bool} accepted so far, and whether the form pushed back
     */
    protected function dripDeferredSignups(string $account, MailchimpApi $api, array $plan, int $accepted): array
    {
        $deferred = NewsletterResubscribeAttempt::query()
            ->where('outcome', NewsletterResubscribeAttempt::DEFERRED)
            ->where('mailchimp_account', $account)
            ->whereNotNull('list_id')
            ->orderBy('created_at')
            ->limit($plan['batch'])
            ->get();

        foreach ($deferred as $attempt) {
            // Asked again rather than assumed: they may have opted back in themselves
            // through the link the form showed them.
            $refusal = $api->resubscribe($attempt->list_id, $attempt->email);

            if ($refusal === null) {
                $attempt->update(['outcome' => NewsletterResubscribeAttempt::RESUBSCRIBED, 'detail' => null]);
                $accepted++;

                continue;
            }

            $submission = $this->hostedForm->submit($account, $attempt->email);

            if ($submission['status'] === MailchimpHostedForm::THROTTLED) {
                // Still busy. Left deferred for the next attempt.
                return [$accepted, true];
            }

            $key = strtolower(trim($attempt->email));
            $status = $api->memberStatusesFor($attempt->list_id, [$key])[$key] ?? null;

            if (in_array($status, ['subscribed', 'pending'], true)) {
                $attempt->update(['outcome' => NewsletterResubscribeAttempt::RESUBSCRIBED, 'detail' => null]);
                $accepted++;
            } else {
                $attempt->update([
                    'outcome' => NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
                    'detail' => $refusal.' → signup form: '.($submission['detail'] ?? $submission['status']),
                ]);
            }

            sleep($plan['interval']);
        }

        return [$accepted, false];
    }

    /**
     * Accounts with contacts still waiting on the form.
     *
     * @return array<int, string>
     */
    protected function accountsWithWork(): array
    {
        $fromImports = MailchimpImport::query()
            ->where('status', MailchimpImport::STATUS_COMPLETE)
            ->whereNotNull('consent_details')
            ->whereHas('rows', fn ($q) => $q->where('outcome', MailchimpImportRow::WILL_RESUBSCRIBE))
            ->distinct()
            ->pluck('account');

        // Sign-up form opt-ins the form was too busy to take. These have no import
        // behind them, so an account whose only outstanding work is people who
        // queued at a venue would otherwise never be visited.
        $fromSignups = NewsletterResubscribeAttempt::query()
            ->where('outcome', NewsletterResubscribeAttempt::DEFERRED)
            ->whereNotNull('mailchimp_account')
            ->distinct()
            ->pluck('mailchimp_account');

        return $fromImports->merge($fromSignups)->unique()->values()->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, MailchimpImportRow>
     */
    protected function outstanding(string $account, int $limit)
    {
        return MailchimpImportRow::query()
            ->with('import')
            ->where('outcome', MailchimpImportRow::WILL_RESUBSCRIBE)
            ->whereNotNull('email')
            ->whereHas('import', fn ($q) => $q
                ->where('account', $account)
                ->where('status', MailchimpImport::STATUS_COMPLETE)
                ->whereNotNull('consent_details'))
            // Oldest first, so a file does not sit behind one uploaded after it.
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->limit($limit)
            ->get();
    }
}
