<?php

namespace App\Jobs;

use App\Models\MailchimpImport;
use App\Services\MailchimpApi;
use App\Services\MailchimpAuditLog;
use App\Services\MailchimpCredentialResolver;
use App\Services\MailchimpImportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The only job in this feature that writes to Mailchimp.
 *
 * It is retried, unlike the dry run, because a run interrupted halfway has
 * already changed things and abandoning it would leave the audience half
 * imported. Rows are marked as Mailchimp answers for them, so a retry picks up
 * where the last attempt stopped rather than starting over.
 */
class RunMailchimpImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    /** Rate limits and transient 5xx are the expected failures; both pass. */
    public array $backoff = [30, 120];

    public function __construct(public int $importId) {}

    public function handle(
        MailchimpImportRunner $runner,
        MailchimpCredentialResolver $credentials,
    ): void {
        $import = MailchimpImport::find($this->importId);

        if (! $import || $import->status !== MailchimpImport::STATUS_RUNNING) {
            return;
        }

        $path = $import->stored_path ? Storage::disk('local')->path($import->stored_path) : null;

        if (! $path || ! is_readable($path)) {
            $this->markFailed($import, 'The uploaded file is no longer available.');

            return;
        }

        MailchimpAuditLog::recordForJob(MailchimpAuditLog::IMPORT_STARTED, $import->created_by_user_id, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'attempt' => $this->attempts(),
        ]);

        $runner->run($import, MailchimpApi::for($credentials->resolve($import->account)), $path);

        $import->refresh()->update([
            'status' => MailchimpImport::STATUS_COMPLETE,
            'completed_at' => now(),
            'failure_reason' => null,
        ]);

        // The audience is the record now; keeping the upload adds nothing but a
        // file full of email addresses sitting on disk.
        if ($import->stored_path) {
            Storage::disk('local')->delete($import->stored_path);
            $import->update(['stored_path' => null]);
        }

        MailchimpAuditLog::recordForJob(MailchimpAuditLog::IMPORT_COMPLETED, $import->created_by_user_id, [
            'import_id' => $import->id,
            'subscribed' => $import->subscribed_count,
            'resubscribed' => $import->resubscribed_count,
            'skipped' => $import->skipped_count,
            'failed' => $import->failed_count,
        ]);
    }

    /**
     * Only after the last attempt. Whatever was sent stays sent and is recorded on
     * the rows, so the report still describes what actually reached Mailchimp.
     */
    public function failed(Throwable $e): void
    {
        $import = MailchimpImport::find($this->importId);

        if ($import && ! $import->isFinished()) {
            $this->markFailed($import, $e->getMessage());
        }
    }

    protected function markFailed(MailchimpImport $import, string $reason): void
    {
        $import->update([
            'status' => MailchimpImport::STATUS_FAILED,
            'completed_at' => now(),
            'failure_reason' => $reason,
        ]);

        MailchimpAuditLog::recordForJob(MailchimpAuditLog::IMPORT_FAILED, $import->created_by_user_id, [
            'import_id' => $import->id,
            'stage' => 'run',
            'reason' => $reason,
            'subscribed' => $import->subscribed_count,
            'resubscribed' => $import->resubscribed_count,
        ]);
    }
}
