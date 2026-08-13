<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Audit trail for the Mailchimp CSV import feature. Connect, disconnect and every
 * import run land here with the acting user and their IP.
 *
 * Nothing written through this class may contain a full email address — callers
 * mask with MailchimpImportRow::maskEmail() first. Full addresses live only in
 * the import_rows table and the downloadable report.
 */
class MailchimpAuditLog
{
    public const CHANNEL = 'mailchimp_audit';

    public const CONNECTED = 'connection.connected';

    public const DISCONNECTED = 'connection.disconnected';

    public const REVOKE_FAILED = 'connection.revoke_failed';

    public const NEEDS_RECONNECT = 'connection.needs_reconnect';

    public const IMPORT_UPLOADED = 'import.uploaded';

    public const IMPORT_DRY_RUN = 'import.dry_run';

    public const IMPORT_QUEUED = 'import.queued';

    public const IMPORT_STARTED = 'import.started';

    public const IMPORT_COMPLETED = 'import.completed';

    public const IMPORT_FAILED = 'import.failed';

    public const IMPORT_EXPORTED = 'import.exported';

    public const IMPORT_DISCARDED = 'import.discarded';

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $action, array $context = []): void
    {
        // User id and IP only. The acting admin's address is deliberately not
        // recorded — no full email address belongs in the application log, and the
        // id resolves to a user record when an audit actually needs a name.
        Log::channel(self::CHANNEL)->info($action, array_merge([
            'user_id' => Auth::id(),
            'ip' => Request::ip(),
        ], $context));
    }

    /**
     * For the queue worker, where there is no authenticated user or request. The
     * user who started the run is passed through from the import record instead.
     */
    public static function recordForJob(string $action, ?int $userId, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($action, array_merge([
            'user_id' => $userId,
            'ip' => null,
            'source' => 'queue',
        ], $context));
    }
}
