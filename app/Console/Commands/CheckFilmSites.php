<?php

namespace App\Console\Commands;

use App\Jobs\RunFilmSiteCheckJob;
use App\Models\FilmSiteCheck;
use App\Models\FilmSiteCheckRun;
use Illuminate\Console\Command;

class CheckFilmSites extends Command
{
    protected $signature = 'film-sites:check
                            {--check= : Run one check by id instead of every enabled one}
                            {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Compare each film site\'s published shows with the Win App locations (read-only on both sides)';

    public function handle(): int
    {
        $checks = FilmSiteCheck::query()
            ->when($this->option('check'), fn ($q, $id) => $q->whereKey($id))
            ->when(! $this->option('check'), fn ($q) => $q->enabled())
            ->get();

        if ($checks->isEmpty()) {
            $this->warn('No film site checks to run. Add one on the Film Site Check screen first.');

            return self::SUCCESS;
        }

        // Scheduled runs come through --queue; someone typing this is watching.
        $trigger = $this->option('queue') ? FilmSiteCheckRun::TRIGGER_SCHEDULE : FilmSiteCheckRun::TRIGGER_CLI;

        foreach ($checks as $check) {
            $this->option('queue')
                ? RunFilmSiteCheckJob::dispatch($check->id, $trigger)
                : RunFilmSiteCheckJob::dispatchSync($check->id, $trigger);

            if ($this->option('queue')) {
                $this->line("  {$check->label()} queued");

                continue;
            }

            $check->refresh();
            $this->line(sprintf(
                '  %-50s %s',
                $check->label(),
                $check->last_status === FilmSiteCheck::STATUS_FAILED
                    ? 'FAILED: '.$check->last_error
                    : "{$check->last_status} — {$check->last_errors} errors, {$check->last_warnings} warnings"
            ));
        }

        return self::SUCCESS;
    }
}
