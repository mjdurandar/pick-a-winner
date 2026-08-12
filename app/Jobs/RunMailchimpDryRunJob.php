<?php

namespace App\Jobs;

use App\Models\MailchimpImport;
use App\Services\MailchimpApi;
use App\Services\MailchimpAuditLog;
use App\Services\MailchimpCredentialResolver;
use App\Services\MailchimpDryRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs the dry run off the request cycle.
 *
 * Reading a large audience is several seconds of API calls and classifying the
 * file is a row per record, neither of which belongs in an HTTP request. The
 * wizard starts this and polls the import's status.
 */
class RunMailchimpDryRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long enough for a large audience to page in; the work is all IO. */
    public int $timeout = 900;

    /**
     * Not retried. A failed dry run leaves a message on the record and the admin
     * decides whether to run it again — a silent retry against a rate-limited or
     * rejected credential would only repeat the same failure.
     */
    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(
        MailchimpDryRun $dryRun,
        MailchimpCredentialResolver $credentials,
    ): void {
        $import = MailchimpImport::find($this->importId);

        if (! $import || $import->status !== MailchimpImport::STATUS_DRY_RUN_RUNNING) {
            return;
        }

        $path = $import->stored_path ? Storage::disk('local')->path($import->stored_path) : null;

        if (! $path || ! is_readable($path)) {
            $this->fail($import, 'The uploaded file is no longer available. Upload it again.');

            return;
        }

        try {
            $counts = $dryRun->run($import, MailchimpApi::for($credentials->resolve($import->account)), $path);
        } catch (Throwable $e) {
            $this->fail($import, $e->getMessage());

            return;
        }

        $import->update([
            'status' => MailchimpImport::STATUS_DRY_RUN_COMPLETE,
            'failure_reason' => null,
        ]);

        MailchimpAuditLog::recordForJob(MailchimpAuditLog::IMPORT_DRY_RUN, $import->created_by_user_id, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'rows' => $import->original_row_count,
        ] + $counts);
    }

    /**
     * The queue's own failure path, for a worker killed mid-run rather than an
     * error the job caught.
     */
    public function failed(Throwable $e): void
    {
        $import = MailchimpImport::find($this->importId);

        if ($import && $import->status === MailchimpImport::STATUS_DRY_RUN_RUNNING) {
            $this->fail($import, $e->getMessage());
        }
    }

    protected function fail(MailchimpImport $import, string $reason): void
    {
        // Back to pending, not failed: nothing was sent, the upload and mapping are
        // still good, and the admin can simply run the preview again.
        $import->update([
            'status' => MailchimpImport::STATUS_PENDING,
            'failure_reason' => $reason,
        ]);

        MailchimpAuditLog::recordForJob(MailchimpAuditLog::IMPORT_FAILED, $import->created_by_user_id, [
            'import_id' => $import->id,
            'stage' => 'dry_run',
            'reason' => $reason,
        ]);
    }
}
