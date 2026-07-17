<?php

namespace App\Jobs;

use App\Models\MailchimpAutoImportRun;
use App\Services\AutoImportLocationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports ONE finished location for a manual auto-import run.
 *
 * The manual "Run now" button fans out one of these per eligible location instead of running a
 * single hour-long job over every location. Each job is short, so a worker restart, a low queue
 * retry_after, or a shared-hosting process kill only affects (and retries) one location — not the
 * whole batch. This is the fix for the MaxAttemptsExceededException the monolithic job hit.
 *
 * Completion is tracked with the run's pending_locations counter: whichever job decrements it to 0
 * flips the run to "completed" (see {@see record()}). Every counter/detail write is wrapped in a
 * locked transaction so concurrent workers can't clobber the JSON details array.
 *
 * @see \App\Services\AutoImportLocationRunner for the actual import work (shared with the inline path).
 */
class AutoImportLocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Per-location timeout — one slow location can't block the queue. Keep well under retry_after. */
    public $timeout = 1800;

    /** Retry transient Mailchimp/API/DB hiccups. Safe: the import is idempotent (upsert by email). */
    public $tries = 3;

    /** Wait 1 min, then 5 min between retries so a rate-limit/outage has time to clear. */
    public $backoff = [60, 300];

    /**
     * @param  array{event_id:int, event_name:?string, location_id:int, location_name:string, list_id:string, list_name:?string, account:string}  $item
     */
    public function __construct(
        public int $runId,
        public array $item
    ) {}

    public function handle(AutoImportLocationRunner $runner): void
    {
        if (! MailchimpAutoImportRun::whereKey($this->runId)->exists()) {
            Log::warning('Auto-import location job: run record not found', ['run_id' => $this->runId]);

            return;
        }

        // May throw — that is intentional. A throw here fails the attempt and Laravel retries
        // (up to $tries). Only after retries are exhausted does failed() record the error.
        $result = $runner->importOneLocation($this->item);

        $this->record($result['detail'], $result);

        Log::info('Auto-import location job: completed', [
            'run_id' => $this->runId,
            'location_id' => $this->item['location_id'] ?? null,
            'status' => $result['detail']['status'] ?? null,
        ]);
    }

    /**
     * Called once after all retries are exhausted. Record the failure as an "error" detail and
     * still decrement the counter so the run can finalise instead of hanging at "running".
     */
    public function failed(?\Throwable $e = null): void
    {
        Log::error('Auto-import location job: failed after retries', [
            'run_id' => $this->runId,
            'location_id' => $this->item['location_id'] ?? null,
            'error' => $e?->getMessage(),
        ]);

        $this->record([
            'location_id' => $this->item['location_id'] ?? null,
            'location_name' => $this->item['location_name'] ?? (string) ($this->item['location_id'] ?? ''),
            'event_id' => $this->item['event_id'] ?? null,
            'event_name' => $this->item['event_name'] ?? null,
            'status' => 'error',
            'reason' => $e ? substr($e->getMessage(), 0, 300) : 'unknown error',
        ], ['imported' => 0, 'skipped' => 1, 'new' => 0, 'updated' => 0, 'errors' => 1]);
    }

    /**
     * Atomically append this location's detail, add its counts, decrement the pending counter, and
     * flip the run to "completed" when this was the last outstanding location. lockForUpdate keeps
     * concurrent workers from stomping the JSON details array (read-modify-write race).
     *
     * @param  array<string, mixed>  $detail
     * @param  array{imported:int, skipped:int, new:int, updated:int, errors:int}  $counts
     */
    private function record(array $detail, array $counts): void
    {
        DB::transaction(function () use ($detail, $counts) {
            $run = MailchimpAutoImportRun::lockForUpdate()->find($this->runId);
            if (! $run) {
                return;
            }

            $details = $run->details ?? [];
            $details[] = $detail;

            $pending = max(0, (int) ($run->pending_locations ?? 1) - 1);

            $run->details = $details;
            $run->locations_imported += $counts['imported'] ?? 0;
            $run->locations_skipped += $counts['skipped'] ?? 0;
            $run->total_new += $counts['new'] ?? 0;
            $run->total_updated += $counts['updated'] ?? 0;
            $run->total_errors += $counts['errors'] ?? 0;
            $run->pending_locations = $pending;

            if ($pending <= 0 && $run->status === 'running') {
                $run->status = 'completed';
            }

            $run->save();
        });
    }
}
