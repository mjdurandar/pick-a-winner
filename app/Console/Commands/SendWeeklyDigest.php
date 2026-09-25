<?php

namespace App\Console\Commands;

use App\Listeners\AddAlwaysCcRecipient;
use App\Mail\WeeklyDigest;
use App\Services\WeeklyDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the weekly digest.
 *
 * Scheduled for Monday morning, so the window it reports on is the week that
 * just finished — a digest sent on Monday about the week starting that morning
 * would be empty every time.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'reports:weekly
                            {--to= : Send to these addresses instead of the configured recipients (comma-separated)}
                            {--week-of= : Any date inside the week to report on; defaults to last week}
                            {--no-cc : Skip MAIL_ALWAYS_CC and WEEKLY_DIGEST_CC — for test sends}
                            {--dry-run : Print what would be sent, send nothing}';

    protected $description = 'Email the weekly screening and sync digest';

    public function handle(WeeklyDigestService $digests): int
    {
        [$start, $end] = $this->window();

        $this->line('week:    '.$start->format('D j M Y').' – '.$end->format('D j M Y'));

        $digest = $digests->build($start, $end);
        $mailable = new WeeklyDigest($digest);

        $to = $this->recipients();

        if ($to === []) {
            $this->error('No recipient. Pass --to, or set WEEKLY_DIGEST_EMAIL / APPROVAL_NOTIFICATION_EMAIL.');

            return self::FAILURE;
        }

        // The standing CC is applied on the send event, so the only way to hold it
        // back for one send is to empty what that listener reads.
        if ($this->option('no-cc')) {
            config(['mail.always_cc' => null, 'mail.weekly_digest_cc' => null]);
        }

        $this->summarise($digest, $to);

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing sent.');

            return self::SUCCESS;
        }

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log" — this will be written to the log, not sent.');
        }

        try {
            Mail::to($to)->send($mailable);
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());
            Log::error('Weekly digest failed to send', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info('Sent.');

        return self::SUCCESS;
    }

    /**
     * The week to report on: Monday to Sunday, last week unless --week-of names
     * a date inside another one.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    protected function window(): array
    {
        $anchor = $this->option('week-of')
            ? CarbonImmutable::parse($this->option('week-of'))
            : CarbonImmutable::now()->subWeek();

        return [$anchor->startOfWeek(), $anchor->endOfWeek()];
    }

    /** @return list<string> */
    protected function recipients(): array
    {
        if (! $this->option('to')) {
            return WeeklyDigest::recipients();
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('to'))),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /** @param  list<string>  $to */
    protected function summarise(array $digest, array $to): void
    {
        $this->line('to:      '.implode(', ', $to));
        $cc = array_unique(array_merge(WeeklyDigest::ccRecipients(), AddAlwaysCcRecipient::addresses()));
        $this->line('cc:      '.(implode(', ', $cc) ?: '—'));
        $this->newLine();

        $t = $digest['totals'];
        $this->line(sprintf(
            'screenings: %d across %d event(s), %d sign-ups',
            $t['screenings'], $t['events'], $t['signups']
        ));

        $s = $digest['sheetTotals'];
        $this->line(sprintf(
            'sheet sync: %d new, %d changed (%d date/time), %d gone, on %d tab(s)',
            $s['creates'], $s['updates'], $s['dates'], $s['missing'], count($digest['sheetSources'])
        ));

        $w = $digest['siteTotals'];
        $this->line(sprintf(
            'site sync:  %d error(s), %d warning(s), %d stale, across %d check(s)',
            $w['errors'], $w['warnings'], $w['stale'], count($digest['siteChecks'])
        ));
        $this->newLine();
    }
}
