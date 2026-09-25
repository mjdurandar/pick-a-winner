<?php

namespace App\Console\Commands;

use App\Listeners\AddAlwaysCcRecipient;
use App\Mail\FilmSiteDifferencesFound;
use App\Mail\SheetChangesAwaitingApproval;
use App\Models\FilmSiteCheck;
use App\Models\SheetSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Proves the SMTP credentials work, before a real alert depends on them.
 *
 * Without this the first test of the mail setup is an approval alert that
 * silently fails at 3am and only shows up as a line in the log.
 */
class TestMail extends Command
{
    protected $signature = 'mail:test
                            {to? : Where to send it; defaults to the approval recipients}
                            {--source= : Send the real master sheet approval alert for this sheet source id}
                            {--check= : Send the real film site difference alert for this check id}
                            {--no-cc : Skip MAIL_ALWAYS_CC and SITE_CHECK_CC}';

    protected $description = 'Send a test email through the configured mailer';

    public function handle(): int
    {
        $to = $this->argument('to')
            ? [$this->argument('to')]
            : SheetChangesAwaitingApproval::recipients();

        if ($to === []) {
            $this->error('No recipient. Pass one, or set APPROVAL_NOTIFICATION_EMAIL / MASTER_SHEET_OWNER_EMAIL.');

            return self::FAILURE;
        }

        // The standing CC is applied on the send event, so the only way to hold it
        // back for one send is to empty what that listener reads.
        if ($this->option('no-cc')) {
            config(['mail.always_cc' => null, 'mail.site_check_cc' => null]);
        }

        $this->line('mailer:  '.config('mail.default'));
        $this->line('host:    '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('from:    '.config('mail.from.address'));
        $this->line('to:      '.implode(', ', $to));
        $this->line('cc:      '.(implode(', ', AddAlwaysCcRecipient::addresses()) ?: '—'));
        $this->newLine();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log" — this will be written to the log, not sent.');
        }

        try {
            match (true) {
                (bool) $this->option('source') => $this->sendSheetAlert($to),
                (bool) $this->option('check') => $this->sendSiteAlert($to),
                default => $this->sendPlain($to),
            };
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent.');

        return self::SUCCESS;
    }

    protected function sendPlain(array $to): void
    {
        Mail::raw(
            'This is a test from '.config('mail.brand').".\n\nIf you are reading it, SMTP is configured correctly.",
            fn ($message) => $message->to($to)->subject(config('mail.brand').': SMTP test')
        );
    }

    /** The genuine article, so the wording and links can be checked for real. */
    protected function sendSheetAlert(array $to): void
    {
        $source = SheetSource::with('event.film')->findOrFail($this->option('source'));

        Mail::to($to)->send(new SheetChangesAwaitingApproval($source, $source->reviewCounts()));
    }

    /**
     * The site alert for a check's most recent run.
     *
     * A real run only mails when it finds something new, so a test on a quiet
     * check would have nothing to list. Falling back to every difference in the
     * run keeps the preview representative rather than empty.
     */
    protected function sendSiteAlert(array $to): void
    {
        $check = FilmSiteCheck::with('event.film')->findOrFail($this->option('check'));
        $run = $check->runs()->latest('id')->first();

        if (! $run) {
            throw new \RuntimeException('That check has never run. Run film-sites:check --check='.$check->id.' first.');
        }

        if ((int) $run->new_issues === 0) {
            $this->warn('That run found nothing new, so the preview lists every current difference instead.');

            // Assigned, not unioned: the stored rows already carry a "new" key and
            // + would keep the false that made this preview empty in the first place.
            $items = collect($run->items ?? [])
                ->map(function (array $i) {
                    $i['new'] = ($i['severity'] ?? '') !== 'ok';

                    return $i;
                })
                ->all();

            $run->setAttribute('items', $items);
            $run->setAttribute('new_issues', collect($items)->where('new', true)->count());
        }

        Mail::to($to)->send(new FilmSiteDifferencesFound($check, $run));
    }
}
