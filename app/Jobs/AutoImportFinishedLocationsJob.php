<?php

namespace App\Jobs;

use App\Models\MailchimpAutoImportRun;
use App\Services\AutoImportLocationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Imports a batch of "finished" locations to Mailchimp INLINE, for the scheduled auto-import.
 *
 * The scheduled command dispatchSync()s this on shared hosting that only runs the schedule:run cron
 * (no always-on queue worker), so the whole batch runs in one process — the daily cadence makes a
 * longer run acceptable. The MANUAL "Run now" button does NOT use this job; it fans out one short
 * {@see AutoImportLocationJob} per location so a worker restart can't blow up the whole run.
 *
 * Both paths share the exact same per-location logic via {@see AutoImportLocationRunner}.
 *
 * @see \App\Console\Commands\AutoImportFinishedLocations for how eligible items are chosen.
 */
class AutoImportFinishedLocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generous timeout: batch imports poll the Mailchimp batch API per location. */
    public $timeout = 3600;

    public $tries = 1;

    /**
     * @param  int  $runId  MailchimpAutoImportRun id to write the summary into.
     * @param  array<int, array{event_id:int, event_name:?string, location_id:int, location_name:string, list_id:string, list_name:?string, account:string}>  $items
     */
    public function __construct(
        public int $runId,
        public array $items
    ) {}

    public function handle(AutoImportLocationRunner $runner): void
    {
        $run = MailchimpAutoImportRun::find($this->runId);
        if (! $run) {
            Log::warning('Auto-import job: run record not found', ['run_id' => $this->runId]);

            return;
        }

        $imported = 0;
        $skipped = 0;
        $totalNew = 0;
        $totalUpdated = 0;
        $totalErrors = 0;
        $details = [];

        foreach ($this->items as $item) {
            $locationId = (int) ($item['location_id'] ?? 0);

            try {
                $result = $runner->importOneLocation($item);

                $imported += $result['imported'];
                $skipped += $result['skipped'];
                $totalNew += $result['new'];
                $totalUpdated += $result['updated'];
                $totalErrors += $result['errors'];
                $details[] = $result['detail'];
            } catch (\Throwable $e) {
                // Inline path: one bad location must not abort the whole batch — record and continue.
                $skipped++;
                $totalErrors++;
                $details[] = [
                    'location_id' => $locationId,
                    'location_name' => $item['location_name'] ?? (string) $locationId,
                    'event_id' => $item['event_id'] ?? null,
                    'event_name' => $item['event_name'] ?? null,
                    'status' => 'error',
                    'reason' => substr($e->getMessage(), 0, 300),
                ];
                Log::error('Auto-import job: location failed', [
                    'run_id' => $this->runId,
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ]);
            }

            $run->update([
                'locations_imported' => $imported,
                'locations_skipped' => $skipped,
                'total_new' => $totalNew,
                'total_updated' => $totalUpdated,
                'total_errors' => $totalErrors,
                'details' => $details,
            ]);
        }

        $run->update([
            'locations_imported' => $imported,
            'locations_skipped' => $skipped,
            'total_new' => $totalNew,
            'total_updated' => $totalUpdated,
            'total_errors' => $totalErrors,
            'details' => $details,
            'status' => 'completed',
        ]);

        Log::info('Auto-import job: completed', [
            'run_id' => $this->runId,
            'imported' => $imported,
            'skipped' => $skipped,
            'new' => $totalNew,
            'updated' => $totalUpdated,
            'errors' => $totalErrors,
        ]);
    }

    public function failed(?\Throwable $e = null): void
    {
        $run = MailchimpAutoImportRun::find($this->runId);
        if ($run) {
            $run->update([
                'status' => 'failed',
                'error' => $e ? substr($e->getMessage(), 0, 500) : 'unknown',
            ]);
        }
    }
}
