<?php

namespace App\Console\Commands;

use App\Jobs\AutoImportFinishedLocationsJob;
use App\Models\MailchimpAutoImportRun;
use App\Services\FinishedLocationSelector;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoImportFinishedLocations extends Command
{
    protected $signature = 'mailchimp:auto-import-finished
        {--days=4 : Import locations whose screening date is at least this many days in the past}
        {--dry-run : List what would be imported without dispatching or calling Mailchimp}';

    protected $description = 'Auto-import locations whose event finished N+ days ago into Mailchimp (per-event opt-in).';

    public function handle(FinishedLocationSelector $selector): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        // Self-heal: a run can be left stuck at 'running' forever if the inline job's process
        // is killed mid-run (e.g. a transient DB drop takes down the schedule:run cron). The job
        // timeout is 1h, so anything still 'running' after 2h is dead — mark it failed so the UI
        // stops showing a perpetual "running" and the locations become eligible again.
        $stuck = MailchimpAutoImportRun::where('status', 'running')
            ->where('ran_at', '<', Carbon::now()->subHours(2))
            ->get();
        foreach ($stuck as $stuckRun) {
            $stuckRun->update([
                'status' => 'failed',
                'error' => 'Run did not complete (process interrupted); auto-marked failed by next scheduled run.',
            ]);
            Log::warning('Auto-import command: marked stale run as failed', ['run_id' => $stuckRun->id]);
        }

        // Only events explicitly opted in AND with a configured audience.
        $items = $selector->select($selector->optedInEvents(), $days);

        $eventsProcessed = count(array_unique(array_column($items, 'event_id')));

        $this->info(sprintf(
            '%d location(s) across %d event(s) eligible (finished %d+ days ago, not yet imported).',
            count($items),
            $eventsProcessed,
            $days
        ));

        if (empty($items)) {
            // Still record an (empty) run so the log page shows the automation ran.
            MailchimpAutoImportRun::create([
                'ran_at' => Carbon::now(),
                'days_threshold' => $days,
                'dry_run' => $dryRun,
                'events_processed' => 0,
                'locations_imported' => 0,
                'locations_skipped' => 0,
                'total_new' => 0,
                'total_updated' => 0,
                'total_errors' => 0,
                'details' => [],
                'status' => 'completed',
            ]);

            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $this->line(sprintf('  - [%s] %s → %s (%s)', $item['event_id'], $item['location_name'], $item['list_name'] ?: $item['list_id'], $item['account']));
        }

        if ($dryRun) {
            MailchimpAutoImportRun::create([
                'ran_at' => Carbon::now(),
                'days_threshold' => $days,
                'dry_run' => true,
                'events_processed' => $eventsProcessed,
                'locations_imported' => 0,
                'locations_skipped' => count($items),
                'total_new' => 0,
                'total_updated' => 0,
                'total_errors' => 0,
                'details' => array_map(fn ($i) => [
                    'location_id' => $i['location_id'],
                    'location_name' => $i['location_name'],
                    'event_id' => $i['event_id'],
                    'event_name' => $i['event_name'] ?? null,
                    'list_name' => $i['list_name'],
                    'account' => $i['account'],
                    'status' => 'would_import',
                ], $items),
                'status' => 'completed',
            ]);
            $this->warn('Dry run: nothing dispatched, no Mailchimp calls made.');

            return self::SUCCESS;
        }

        $run = MailchimpAutoImportRun::create([
            'ran_at' => Carbon::now(),
            'days_threshold' => $days,
            'dry_run' => false,
            'events_processed' => $eventsProcessed,
            'locations_imported' => 0,
            'locations_skipped' => 0,
            'total_new' => 0,
            'total_updated' => 0,
            'total_errors' => 0,
            'details' => [],
            'status' => 'running',
        ]);

        // Run inline (not queued) so this works on shared hosting with only the schedule:run cron —
        // no separate always-on queue worker required. The daily cadence makes a longer run fine.
        Log::info('Auto-import command: starting inline import', ['run_id' => $run->id, 'locations' => count($items)]);
        try {
            AutoImportFinishedLocationsJob::dispatchSync($run->id, $items);
        } catch (\Throwable $e) {
            // Inline dispatch doesn't route through the queue's failed-job handler, so mark the
            // run failed here rather than leaving it stuck at 'running'.
            $run->update([
                'status' => 'failed',
                'error' => substr($e->getMessage(), 0, 500),
            ]);
            Log::error('Auto-import command: run failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);

            $this->error(sprintf('Run #%d failed: %s', $run->id, $e->getMessage()));

            return self::FAILURE;
        }

        $run->refresh();
        $this->info(sprintf(
            'Run #%d %s: imported %d, skipped %d (new %d, updated %d, errors %d).',
            $run->id,
            $run->status,
            $run->locations_imported,
            $run->locations_skipped,
            $run->total_new,
            $run->total_updated,
            $run->total_errors
        ));

        return self::SUCCESS;
    }
}
