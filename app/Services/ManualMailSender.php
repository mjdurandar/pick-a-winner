<?php

namespace App\Services;

use App\Exceptions\NothingToSendException;
use App\Jobs\RunFilmSiteCheckJob;
use App\Jobs\SyncSheetSourceJob;
use App\Listeners\AddAlwaysCcRecipient;
use App\Mail\FilmSiteDifferencesFound;
use App\Mail\SheetChangesAwaitingApproval;
use App\Mail\WeeklyDigest;
use App\Models\FilmSiteCheck;
use App\Models\FilmSiteCheckRun;
use App\Models\SheetSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the automated alerts on demand, built from the data as it is at the
 * moment the button is pressed, to whoever the person pressing it chose.
 *
 * The To and CC typed into the prompt are the whole list. The prompt opens
 * pre-filled with the configured recipients and CCs, so anyone who should be
 * copied is visible and removable there — adding the standing CC again behind
 * the person's back would make that field a lie.
 */
class ManualMailSender
{
    public const WEEK_THIS = 'this_week';

    public const WEEK_LAST = 'last_week';

    /** Every config key that quietly adds a CC to one of the alerts. */
    protected const CC_KEYS = [
        'mail.always_cc',
        'mail.site_check_cc',
        'mail.weekly_digest_cc',
    ];

    public function __construct(protected WeeklyDigestService $digests) {}

    /**
     * What the prompt opens with: the addresses the scheduled send would use.
     *
     * @param  list<string>  $to
     * @param  list<string>  $cc  this mail's own CC; the standing CC is added here
     * @return array{to: list<string>, cc: list<string>}
     */
    public static function defaults(array $to, array $cc): array
    {
        $cc = array_unique(array_merge($cc, AddAlwaysCcRecipient::addresses()));

        return [
            'to' => array_values($to),
            'cc' => array_values(array_diff($cc, $to)),
        ];
    }

    /**
     * Re-check the site now, and mail every difference it finds — not only the
     * new ones the daily alert carries, since the question is what is wrong now.
     *
     * @throws NothingToSendException
     */
    public function filmCheck(FilmSiteCheck $check, ?User $by): FilmSiteDifferencesFound
    {
        RunFilmSiteCheckJob::dispatchSync($check->id, FilmSiteCheckRun::TRIGGER_MANUAL, $by?->id);

        $check->refresh()->loadMissing('event.film');

        if ($check->last_status === FilmSiteCheck::STATUS_FAILED) {
            throw new \RuntimeException("The check failed: {$check->last_error}");
        }

        $run = $check->runs()->latest('id')->first();

        if (! $run || FilmSiteDifferencesFound::actionable($run, everything: true)->isEmpty()) {
            throw new NothingToSendException('Matches the Win App — nothing to email.');
        }

        return new FilmSiteDifferencesFound($check, $run, everything: true);
    }

    /**
     * Re-read the tab now, and mail whatever it is holding for approval.
     *
     * A paused tab is not re-read — the job would skip it — so it mails the batch
     * already parked from its last run.
     *
     * @throws NothingToSendException
     */
    public function sheetSource(SheetSource $source): SheetChangesAwaitingApproval
    {
        if ($source->enabled) {
            // notify: false — this send replaces the automatic alert, and records
            // the batch as seen so the scheduler does not mail it again.
            SyncSheetSourceJob::dispatchSync($source->id, notify: false);
            $source->refresh();

            if ($source->last_status === SheetSource::STATUS_FAILED) {
                throw new \RuntimeException("The sync failed: {$source->last_error}");
            }
        }

        $counts = $source->reviewCounts();

        if (array_sum($counts) === 0) {
            throw new NothingToSendException('Up to date — nothing to email.');
        }

        return new SheetChangesAwaitingApproval($source->loadMissing('event.film'), $counts);
    }

    /**
     * The digest for this week so far (Monday to now) or for last week — the
     * latter being exactly what Monday's scheduled send carries, re-read now.
     */
    public function weeklyDigest(string $period): WeeklyDigest
    {
        $now = CarbonImmutable::now();

        [$start, $end] = $period === self::WEEK_LAST
            ? [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()]
            : [$now->startOfWeek(), $now];

        return new WeeklyDigest($this->digests->build($start, $end));
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function send(Mailable $mailable, array $to, array $cc, ?User $by, string $what): void
    {
        // The configured CCs are read from config by the mailables' envelopes and
        // by the send listener. Emptying them for this one request is the only way
        // to hold them back; the next request boots with them again.
        config(array_fill_keys(self::CC_KEYS, null));

        Mail::to($to)->cc($cc)->send($mailable);

        Log::info('Sent '.$what.' manually', [
            'to' => $to,
            'cc' => $cc,
            'user_id' => $by?->id,
        ]);
    }
}
