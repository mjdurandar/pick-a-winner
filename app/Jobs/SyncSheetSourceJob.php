<?php

namespace App\Jobs;

use App\Exceptions\GoogleOAuthException;
use App\Exceptions\GoogleSheetsException;
use App\Mail\SheetChangesAwaitingApproval;
use App\Models\SheetSource;
use App\Services\SheetSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Re-reads one configured tab, off the request cycle.
 *
 * Writes nothing to locations — it parks what the sheet now says so an admin can
 * review and approve it. See SheetSyncService::refresh().
 *
 * One job per tab rather than one for all of them: a tab whose header moved
 * should not stop the other nineteen from importing, and the settings screen
 * reports success and failure per tab.
 */
class SyncSheetSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A tab is one API read and a few hundred rows; a minute is generous. */
    public int $timeout = 300;

    /**
     * Not retried. Every failure this job can hit is a standing condition — a
     * revoked token, a sheet that is no longer shared, a renamed tab — and none
     * of them clear on their own within a retry window. The next scheduled run
     * picks it up once the admin has fixed it.
     */
    public int $tries = 1;

    /**
     * @param  bool  $notify  Whether a batch nobody has seen should be emailed.
     *                        False for a run someone kicked off from the review
     *                        screen — they are already looking at the answer, and
     *                        an alert about what is on their monitor is noise.
     */
    public function __construct(public int $sourceId, public bool $notify = true) {}

    public function handle(SheetSyncService $sync): void
    {
        $source = SheetSource::find($this->sourceId);

        if (! $source || ! $source->enabled) {
            return;
        }

        try {
            $result = $sync->refresh($source);
        } catch (GoogleSheetsException|GoogleOAuthException $e) {
            // These messages are written for an admin to act on, so they go
            // straight onto the source for the settings screen to show.
            $source->recordFailure($e->getMessage());

            Log::warning('Master sheet sync failed', [
                'sheet_source_id' => $source->id,
                'tab' => $source->tab_name,
                'message' => $e->getMessage(),
            ]);

            return;
        } catch (\Throwable $e) {
            // Anything else is a bug rather than a configuration problem. The
            // admin gets something non-alarming and the detail goes to the log.
            $source->recordFailure('The sync failed unexpectedly. The error has been logged.');

            Log::error('Master sheet sync threw', [
                'sheet_source_id' => $source->id,
                'tab' => $source->tab_name,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $source->recordSuccess($result['created'], $result['updated'], $result['skipped']);

        if ($result['missing'] > 0) {
            // Not an error and not queued work — a screening this tab put here is
            // no longer in the sheet, and only an admin can say whether that was a
            // cancellation or an edit in progress.
            Log::info('Master sheet screenings no longer in the sheet', [
                'sheet_source_id' => $source->id,
                'tab' => $source->tab_name,
                'missing' => $result['missing'],
            ]);
        }

        if ($result['actionable'] > 0) {
            // The settings screen and the nav badge read this off the parked batch;
            // the log line is so a change appearing overnight is traceable later.
            Log::info('Master sheet changes awaiting review', [
                'sheet_source_id' => $source->id,
                'tab' => $source->tab_name,
                'creates' => $result['created'],
                'updates' => $result['updated'],
            ]);
        }

        $this->alert($source);
    }

    /**
     * Email the owner once per distinct batch.
     *
     * The scheduler re-reads every tab every five minutes and re-parks the same
     * plan each time, so "something is waiting" stays true until it is approved.
     * Mailing on that condition would send the same thing all day; mailing on a
     * change to the batch's digest sends it once, and again only if the sheet
     * moves to something genuinely different.
     */
    protected function alert(SheetSource $source): void
    {
        $digest = $source->reviewDigest();

        if ($digest === $source->review_notified_digest) {
            return;
        }

        // Nothing waiting any more, or a run the admin is watching: record where
        // the batch got to and stay quiet. Recording a null clears the marker, so
        // the same change coming back after an approval alerts again.
        if ($digest === null || ! $this->notify) {
            $source->markReviewNotified($digest);

            return;
        }

        if ($this->mail($source)) {
            $source->markReviewNotified($digest);
        }
    }

    /**
     * @return bool whether the alert actually went out — a send that failed is
     *              left unmarked so the next run tries again rather than
     *              swallowing the only notice this batch would ever get
     */
    protected function mail(SheetSource $source): bool
    {
        $recipients = SheetChangesAwaitingApproval::recipients();

        if ($recipients === []) {
            Log::warning('Master sheet changes waiting, but no approval recipient is configured', [
                'sheet_source_id' => $source->id,
                'tab' => $source->tab_name,
            ]);

            return false;
        }

        try {
            Mail::to($recipients)->send(
                new SheetChangesAwaitingApproval($source, $source->reviewCounts())
            );
        } catch (\Throwable $e) {
            // A broken mailbox must never take the sync down with it: the batch is
            // parked and visible on the review screen either way.
            Log::error('Master sheet approval alert failed to send', [
                'sheet_source_id' => $source->id,
                'tab' => $source->tab_name,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
