<?php

namespace App\Console\Commands;

use App\Jobs\DripOutstandingOptInsJob;
use App\Models\MailchimpFormRelayRun;
use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;
use Illuminate\Console\Command;

class DripOptIns extends Command
{
    protected $signature = 'mailchimp:drip-optins
                            {--account= : Limit to one account (anz or usa)}
                            {--status : Show what the schedule has learned and exit}';

    protected $description = 'Relay outstanding opt-ins to the hosted signup form, pacing to whatever rate it allows';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        // Run inline rather than queued: someone typing this wants to watch it.
        DripOutstandingOptInsJob::dispatchSync($this->option('account'));

        $this->newLine();

        return $this->showStatus();
    }

    protected function showStatus(): int
    {
        $outstanding = MailchimpImportRow::query()
            ->where('outcome', MailchimpImportRow::WILL_RESUBSCRIBE)
            ->whereHas('import', fn ($q) => $q
                ->where('status', MailchimpImport::STATUS_COMPLETE)
                ->whereNotNull('consent_details'))
            ->selectRaw('count(*) as total')
            ->value('total');

        $this->line("Contacts waiting on the signup form: <info>{$outstanding}</info>");
        $this->newLine();

        $rows = [];

        foreach (['anz', 'usa'] as $account) {
            $last = MailchimpFormRelayRun::latestFor($account);

            if (! $last) {
                $rows[] = [$account, '—', '—', '—', 'never run', '—'];

                continue;
            }

            $rows[] = [
                $account,
                $last->interval_seconds.'s',
                $last->planned,
                $last->accepted,
                $last->throttled ? 'yes' : 'no',
                $last->next_attempt_at?->diffForHumans() ?? 'now',
            ];
        }

        $this->table(
            ['Account', 'Next interval', 'Next batch', 'Last accepted', 'Was throttled', 'Next attempt'],
            $rows,
        );

        $this->line('<comment>The batch and interval are learned from what the form allowed last time.</comment>');

        return self::SUCCESS;
    }
}
