<?php

namespace App\Http\Controllers;

use App\Exceptions\CsvImportException;
use App\Exceptions\MailchimpApiException;
use App\Jobs\RunMailchimpDryRunJob;
use App\Jobs\RunMailchimpImportJob;
use App\Models\MailchimpConnection;
use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;
use App\Services\CsvImportParser;
use App\Services\MailchimpApi;
use App\Services\MailchimpAuditLog;
use App\Services\MailchimpCredentialResolver;
use App\Services\MailchimpCredentials;
use App\Services\MergeFieldMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV import wizard. Admin only — enforced on the route group.
 *
 * The wizard is one page with client-side step state; these endpoints back it.
 * Parsing happens here rather than in the browser so the row count, headers and
 * every later classification come from the same reading of the file.
 */
class MailchimpImportController extends Controller
{
    /** Matches the 10 MB limit in the upload rules (kilobytes). */
    private const MAX_UPLOAD_KB = 10240;

    /**
     * Rows sent to the preview table per outcome. The preview is there to be read,
     * not to reproduce the file — the full classification is in the row log.
     */
    private const PREVIEW_ROW_LIMIT = 100;

    /**
     * How long an import may sit claimed but untouched before the admin is allowed
     * to start it again. Comfortably longer than any single batch or audience page,
     * so a slow run is never mistaken for a dead one.
     */
    private const STALLED_AFTER_MINUTES = 15;

    public function __construct(
        protected CsvImportParser $parser,
        protected MergeFieldMatcher $matcher,
        protected MailchimpCredentialResolver $credentials,
    ) {}

    public function index(): Response
    {
        // An account is offered when it is reachable at all — by OAuth connection or
        // by the API key already configured for it.
        $accounts = collect(MailchimpConnection::ACCOUNTS)
            ->map(function (string $label, string $key) {
                $status = $this->credentials->describe($key);
                $connection = MailchimpConnection::where('account', $key)->first();

                return [
                    'key' => $key,
                    'label' => $label,
                    'name' => $connection?->mailchimp_account_name,
                    'active' => $status['usable'],
                    'source' => $status['source'],
                ];
            })
            ->values();

        return Inertia::render('MailchimpImport', [
            'accounts' => $accounts,
            'maxUploadMb' => self::MAX_UPLOAD_KB / 1024,
        ]);
    }

    /**
     * Open an import against a chosen account and audience, before any file is
     * involved. Destination first: the audience decides which merge fields the
     * mapping step can offer and whether contacts are subscribed or invited, so
     * fixing it up front keeps the rest of the wizard consistent.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account' => ['required', 'string', 'in:'.implode(',', array_keys(MailchimpConnection::ACCOUNTS))],
            'audience_id' => ['required', 'string'],
        ]);

        $api = MailchimpApi::for($this->credentialsFor($validated['account']));

        try {
            $audience = $api->list($validated['audience_id']);
            $mergeFields = $api->mergeFields($validated['audience_id']);
        } catch (MailchimpApiException $e) {
            return $this->apiError($e);
        }

        $import = MailchimpImport::create([
            'account' => $validated['account'],
            'filename' => '',
            'audience_id' => $audience['id'],
            'audience_name' => $audience['name'],
            // Read from Mailchimp, never from the request, so a stale page cannot
            // decide what status contacts are sent with.
            'double_optin' => $audience['double_optin'],
            'status' => MailchimpImport::STATUS_PENDING,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'import' => [
                'id' => $import->id,
                'audience_id' => $import->audience_id,
                'audience_name' => $import->audience_name,
                'double_optin' => $import->double_optin,
            ],
            'merge_fields' => $mergeFields,
        ]);
    }

    /**
     * Accept the file and parse it. Nothing reaches Mailchimp here — this only
     * establishes what is in the file and suggests a mapping against the merge
     * fields of the audience already chosen.
     */
    public function upload(Request $request, MailchimpImport $import): JsonResponse
    {
        $this->assertConfigurable($import);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_UPLOAD_KB,
                'extensions:csv',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/octet-stream',
            ],
        ], [
            'file.max' => 'The file must be '.(self::MAX_UPLOAD_KB / 1024).' MB or smaller.',
            'file.extensions' => 'Only .csv files can be imported.',
            'file.mimetypes' => 'Only .csv files can be imported.',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $size = $file->getSize();

        // Stored under a generated name: the admin's filename is kept on the record
        // for display but never used as a path.
        $storedPath = $file->storeAs('mailchimp-imports', Str::uuid()->toString().'.csv', 'local');

        try {
            $inspection = $this->parser->inspect(Storage::disk('local')->path($storedPath));
        } catch (CsvImportException $e) {
            Storage::disk('local')->delete($storedPath);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($inspection['row_count'] === 0) {
            Storage::disk('local')->delete($storedPath);

            throw ValidationException::withMessages([
                'file' => 'This file has a header row but no data rows.',
            ]);
        }

        // Replacing the file on an import that already had one — the admin went back
        // a step — must not leave the old upload behind on disk.
        if ($import->stored_path && $import->stored_path !== $storedPath) {
            Storage::disk('local')->delete($import->stored_path);
        }

        $import->update([
            'filename' => $originalName,
            'stored_path' => $storedPath,
            'file_size' => $size,
            'original_row_count' => $inspection['row_count'],
        ]);

        MailchimpAuditLog::record(MailchimpAuditLog::IMPORT_UPLOADED, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'filename' => $originalName,
            'rows' => $inspection['row_count'],
            'bytes' => $size,
        ]);

        $mergeFields = MailchimpApi::for($this->credentialsFor($import->account))
            ->mergeFields($import->audience_id);

        return response()->json([
            'import' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'file_size' => $import->file_size,
                'row_count' => $import->original_row_count,
            ],
            'headers' => $inspection['headers'],
            'suggested_map' => $this->matcher->match($inspection['headers'], $mergeFields),
        ]);
    }

    /**
     * Audiences for the dropdown.
     */
    public function audiences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account' => ['required', 'string', 'in:'.implode(',', array_keys(MailchimpConnection::ACCOUNTS))],
        ]);

        $api = MailchimpApi::for($this->credentialsFor($validated['account']));

        try {
            return response()->json(['audiences' => $api->lists()]);
        } catch (MailchimpApiException $e) {
            return $this->apiError($e);
        }
    }

    /**
     * Save the configuration and the consent attestation. The dry run reads the
     * import record from here, so nothing is confirmed twice.
     */
    public function configure(Request $request, MailchimpImport $import): JsonResponse
    {
        $this->assertConfigurable($import);

        $validated = $request->validate([
            'tag' => ['nullable', 'string', 'max:100'],
            'field_map' => ['required', 'array'],
            'field_map.*' => ['nullable', 'string', 'max:100'],
            'consent_confirmed' => ['required', 'accepted'],
            'consent_source' => ['nullable', 'string', 'max:255'],
        ], [
            'consent_confirmed.accepted' => 'Confirm that these contacts opted in before continuing.',
        ]);

        $map = array_filter($validated['field_map'], fn ($tag) => filled($tag));

        if (! in_array('EMAIL', $map, true)) {
            throw ValidationException::withMessages([
                'field_map' => 'Map a column to the email address before continuing.',
            ]);
        }

        if (! $import->stored_path) {
            throw ValidationException::withMessages([
                'field_map' => 'Upload a CSV before confirming the mapping.',
            ]);
        }

        // Coming back to change the mapping invalidates whatever the last dry run
        // decided, so the classification is dropped rather than left to describe a
        // file read through a different map.
        if ($import->status === MailchimpImport::STATUS_DRY_RUN_COMPLETE) {
            $import->rows()->delete();
        }

        $import->update([
            'tag' => $validated['tag'] ?? null,
            'field_map' => $map,
            'consent_confirmed_by_user_id' => $request->user()->id,
            'consent_confirmed_at' => now(),
            'consent_source' => $validated['consent_source'] ?? null,
            'status' => MailchimpImport::STATUS_PENDING,
            'failure_reason' => null,
        ]);

        return response()->json([
            'import' => [
                'id' => $import->id,
                'audience_id' => $import->audience_id,
                'audience_name' => $import->audience_name,
                'double_optin' => $import->double_optin,
                'tag' => $import->tag,
                'field_map' => $import->field_map,
            ],
        ]);
    }

    /**
     * Start the dry run. Nothing is sent to Mailchimp by this — the run reads the
     * audience and classifies the file against it.
     */
    public function dryRun(MailchimpImport $import): JsonResponse
    {
        // Still working — a second click just gets the current state back. Once it
        // has gone quiet for long enough the worker is assumed dead (killed by a
        // process timeout, say) and starting again is allowed; nothing was sent, so
        // the worst case is the file being classified twice.
        if ($import->status === MailchimpImport::STATUS_DRY_RUN_RUNNING && ! $this->hasStalled($import)) {
            return response()->json($this->previewPayload($import));
        }

        if ($import->status !== MailchimpImport::STATUS_DRY_RUN_RUNNING) {
            $this->assertConfigurable($import);
        }

        if (! $import->isConsentConfirmed() || ! $import->field_map) {
            throw ValidationException::withMessages([
                'field_map' => 'Confirm the column mapping before previewing.',
            ]);
        }

        if (! $import->stored_path || ! Storage::disk('local')->exists($import->stored_path)) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is no longer available. Upload it again.',
            ]);
        }

        // Claimed before dispatch: the job refuses to run against any other status,
        // so a double-clicked button cannot start two runs over the same rows.
        $import->update([
            'status' => MailchimpImport::STATUS_DRY_RUN_RUNNING,
            'failure_reason' => null,
        ]);

        RunMailchimpDryRunJob::dispatch($import->id);

        return response()->json($this->previewPayload($import));
    }

    /**
     * Send the import. This is the only endpoint in the wizard that causes a write
     * to Mailchimp, and it will only act on a run that has been previewed.
     */
    public function run(MailchimpImport $import): JsonResponse
    {
        // A live run touches the record after every batch, so silence here really
        // does mean the worker is gone. Resuming is safe: rows already sent are no
        // longer actionable and the runner passes over them.
        if ($import->status === MailchimpImport::STATUS_RUNNING && ! $this->hasStalled($import)) {
            return response()->json($this->previewPayload($import));
        }

        // A failed run can be started again. Rows are written back as Mailchimp
        // answers for them, so whatever already went through is no longer actionable
        // and the second attempt only picks up what is left.
        if (! in_array($import->status, [
            MailchimpImport::STATUS_DRY_RUN_COMPLETE,
            MailchimpImport::STATUS_FAILED,
            MailchimpImport::STATUS_RUNNING,
        ], true)) {
            abort(422, 'Preview this import before sending it.');
        }

        if (! $import->isConsentConfirmed()) {
            throw ValidationException::withMessages([
                'consent_confirmed' => 'Confirm that these contacts opted in before sending.',
            ]);
        }

        if ($import->actionableCount() === 0) {
            throw ValidationException::withMessages([
                'field_map' => 'There is nothing to send — no row in this file would change anything.',
            ]);
        }

        if (! $import->stored_path || ! Storage::disk('local')->exists($import->stored_path)) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is no longer available. Upload it again.',
            ]);
        }

        // Claimed before dispatch, same as the dry run: the job only runs against
        // STATUS_RUNNING, so a second click cannot start a second send.
        $import->update([
            'status' => MailchimpImport::STATUS_RUNNING,
            'started_at' => $import->started_at ?? now(),
            'completed_at' => null,
            'failure_reason' => null,
        ]);

        RunMailchimpImportJob::dispatch($import->id);

        MailchimpAuditLog::record(MailchimpAuditLog::IMPORT_QUEUED, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'contacts' => $import->actionableCount(),
        ]);

        return response()->json($this->previewPayload($import));
    }

    /**
     * Polled by the wizard through both phases — while the dry run works, and again
     * while the send runs. The outcome filter backs the row list in each.
     */
    public function preview(Request $request, MailchimpImport $import): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['nullable', 'string', 'in:'.implode(',', array_keys(MailchimpImportRow::OUTCOME_LABELS))],
        ]);

        return response()->json($this->previewPayload($import, $validated['outcome'] ?? null));
    }

    /**
     * The whole row log as CSV — every address with what happened to it and why.
     *
     * Streamed rather than built in memory: this is one line per row of the original
     * upload, which can be six figures.
     */
    public function download(MailchimpImport $import): StreamedResponse
    {
        abort_unless($import->rows()->exists(), 404, 'This import has no report yet.');

        $labels = array_merge(MailchimpImportRow::OUTCOME_LABELS, [
            MailchimpImportRow::WILL_SUBSCRIBE => $import->subscribeMetricLabel(),
        ]);

        $filename = 'mailchimp-import-'.$import->id.'-'.$import->created_at->format('Y-m-d').'.csv';

        MailchimpAuditLog::record(MailchimpAuditLog::IMPORT_EXPORTED, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'rows' => $import->rows()->count(),
        ]);

        return response()->streamDownload(function () use ($import, $labels) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Row', 'Email', 'Outcome', 'Reason', 'Mailchimp status', 'Processed at']);

            $import->rows()->orderBy('row_number')->chunk(1000, function ($rows) use ($handle, $labels) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->row_number,
                        $row->email,
                        $labels[$row->outcome] ?? $row->outcome,
                        $row->detail,
                        $row->mailchimp_status_code,
                        $row->processed_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewPayload(MailchimpImport $import, ?string $outcome = null): array
    {
        $ready = in_array($import->status, [
            MailchimpImport::STATUS_DRY_RUN_COMPLETE,
            MailchimpImport::STATUS_RUNNING,
            MailchimpImport::STATUS_COMPLETE,
            MailchimpImport::STATUS_FAILED,
        ], true);

        $payload = [
            'status' => $import->status,
            'ready' => $ready,
            'running' => $import->status === MailchimpImport::STATUS_RUNNING,
            'finished' => $import->isFinished(),
            'failure_reason' => $import->failure_reason,
            'import' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'audience_name' => $import->audience_name,
                'double_optin' => $import->double_optin,
                'tag' => $import->tag,
                'row_count' => $import->original_row_count,
            ],
        ];

        if (! $ready) {
            return $payload;
        }

        return $payload + [
            'counts' => $import->outcomeCounts(),
            'actionable' => $import->actionableCount(),
            // On a double opt-in audience this import invites rather than subscribes,
            // so the preview must not promise more than the run will do.
            'labels' => array_merge(MailchimpImportRow::OUTCOME_LABELS, [
                MailchimpImportRow::WILL_SUBSCRIBE => $import->subscribeMetricLabel(),
            ]),
            'rows' => $import->rows()
                ->outcome($outcome)
                ->orderBy('row_number')
                ->limit(self::PREVIEW_ROW_LIMIT)
                ->get(['row_number', 'email', 'outcome', 'detail'])
                ->all(),
            'row_limit' => self::PREVIEW_ROW_LIMIT,
            'signup_url' => $this->signupUrlIfNeeded($import),
        ];
    }

    /**
     * Contacts Mailchimp refused on compliance grounds can only opt back in through
     * Mailchimp's own hosted form, so the report hands the admin that link to pass
     * on. Fetched only when there is someone to pass it to, and cached — it is a
     * property of the audience, not of this run.
     */
    protected function signupUrlIfNeeded(MailchimpImport $import): ?string
    {
        if (! $import->isFinished()) {
            return null;
        }

        $blocked = $import->rows()
            ->where('outcome', MailchimpImportRow::BLOCKED_UNSUBSCRIBED)
            ->exists();

        if (! $blocked) {
            return null;
        }

        return Cache::remember(
            'mailchimp_signup_url:'.$import->account.':'.$import->audience_id,
            now()->addDay(),
            function () use ($import) {
                try {
                    return MailchimpApi::for($this->credentialsFor($import->account))
                        ->list($import->audience_id)['subscribe_url'];
                } catch (MailchimpApiException|ValidationException $e) {
                    // The report is worth showing without the link.
                    return null;
                }
            },
        );
    }

    /**
     * @throws ValidationException
     */
    protected function credentialsFor(string $account): MailchimpCredentials
    {
        $credentials = $this->credentials->resolveOrNull($account);

        if (! $credentials) {
            $connection = MailchimpConnection::where('account', $account)->first();

            throw ValidationException::withMessages([
                'account' => $connection
                    ? 'This Mailchimp connection needs to be reconnected before importing.'
                    : 'That Mailchimp account has no credentials. Configure an API key for it in the server environment.',
            ]);
        }

        return $credentials;
    }

    /**
     * A job that claimed this import but has not touched it since is treated as
     * gone. Both jobs write to the record as they work, so quiet for this long means
     * the worker died rather than that it is busy.
     */
    protected function hasStalled(MailchimpImport $import): bool
    {
        return $import->updated_at?->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES)) ?? false;
    }

    protected function assertConfigurable(MailchimpImport $import): void
    {
        if (! in_array($import->status, [MailchimpImport::STATUS_PENDING, MailchimpImport::STATUS_DRY_RUN_COMPLETE], true)) {
            abort(422, 'This import has already started and can no longer be reconfigured.');
        }
    }

    protected function apiError(MailchimpApiException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'needs_reconnect' => $e->needsReconnect,
        ], $e->needsReconnect ? 409 : 502);
    }
}
