<?php

namespace App\Http\Controllers;

use App\Exceptions\CsvImportException;
use App\Exceptions\MailchimpApiException;
use App\Models\MailchimpConnection;
use App\Models\MailchimpImport;
use App\Services\CsvImportParser;
use App\Services\MailchimpApi;
use App\Services\MailchimpAuditLog;
use App\Services\MailchimpCredentialResolver;
use App\Services\MailchimpCredentials;
use App\Services\MergeFieldMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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

        $import->update([
            'tag' => $validated['tag'] ?? null,
            'field_map' => $map,
            'consent_confirmed_by_user_id' => $request->user()->id,
            'consent_confirmed_at' => now(),
            'consent_source' => $validated['consent_source'] ?? null,
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
                    : 'That Mailchimp account has no credentials. Connect it on the integration page, or configure an API key for it.',
            ]);
        }

        return $credentials;
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
