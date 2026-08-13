<?php

namespace App\Http\Controllers;

use App\Exceptions\CsvImportException;
use App\Exceptions\MailchimpApiException;
use App\Jobs\RunMailchimpDryRunJob;
use App\Jobs\RunMailchimpImportJob;
use App\Models\MailchimpConnection;
use App\Models\MailchimpFormRelayRun;
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
            'recentImports' => $this->recentImports(),
        ]);
    }

    /**
     * Files already put through the wizard, so one can be opened again.
     *
     * An import whose contacts the signup form rate-limited carries on in the
     * background for hours afterwards. Without a way back to it the admin would have
     * nowhere to watch that happen — the wizard always opens on step one, and a
     * finished import was unreachable the moment they navigated away.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function recentImports(): array
    {
        return MailchimpImport::query()
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(function (MailchimpImport $import) {
                $counts = $import->outcomeCounts();

                return [
                    'id' => $import->id,
                    'filename' => $import->filename,
                    'account' => $import->account,
                    'audience_name' => $import->audience_name,
                    'status' => $import->status,
                    'rows' => $import->original_row_count,
                    'created_at' => $import->created_at?->diffForHumans(),
                    // What is still owed, and what the signup form has recovered so
                    // far — the two numbers worth seeing without opening the file.
                    'outstanding' => $import->actionableCount(),
                    'recovered' => ($counts[MailchimpImportRow::RECOVERED_VIA_FORM] ?? 0)
                        + ($counts[MailchimpImportRow::RESUBSCRIBED] ?? 0)
                        + ($counts[MailchimpImportRow::SUBSCRIBED] ?? 0),
                ];
            })
            ->all();
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
            'consent_source' => ['required', 'string', 'max:255'],
            // What Mailchimp Support asks for when a contact it holds in a compliance
            // state has to be restored on documented consent. Recorded at upload,
            // while whoever ran the collection still remembers it.
            'consent_details' => ['nullable', 'array'],
            'consent_details.method' => ['required', 'string', 'max:100'],
            'consent_details.wording' => ['required', 'string', 'max:1000'],
            'consent_details.collected_at' => ['nullable', 'string', 'max:255'],
            'consent_details.collected_on' => ['nullable', 'date'],
        ], [
            'consent_confirmed.accepted' => 'Confirm that these contacts opted in before continuing.',
            'consent_source.required' => 'Say where these contacts opted in — it is the record you will need if Mailchimp asks.',
            'consent_details.method.required' => 'Choose how the opt-in was collected.',
            'consent_details.wording.required' => 'Record what these contacts agreed to, in the words they saw.',
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
            'consent_source' => $validated['consent_source'],
            'consent_details' => $validated['consent_details'] ?? null,
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
            // Cleared here and stamped by the job, so a second preview waits for its
            // own worker rather than trusting the first one's mark.
            'dry_run_started_at' => null,
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
        // A finished run can be sent again when contacts are still outstanding — the
        // signup form throttles, and whatever it turned away keeps its actionable
        // classification rather than being written off. Rows already sent are no
        // longer actionable, so the second pass only picks up what is left.
        if (! in_array($import->status, [
            MailchimpImport::STATUS_DRY_RUN_COMPLETE,
            MailchimpImport::STATUS_FAILED,
            MailchimpImport::STATUS_RUNNING,
        ], true) && ! ($import->status === MailchimpImport::STATUS_COMPLETE && $import->actionableCount() > 0)) {
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
     * The contacts Mailchimp refused on compliance grounds, with the consent this
     * import was run under attached to every row.
     *
     * A compliance state is Mailchimp's record that the address once unsubscribed,
     * bounced or was deleted; nothing the API or this app can send will clear it,
     * because clearing it is the contact's own act. Where the opt-in was collected
     * offline and can be evidenced, Mailchimp Support will restore them on that
     * evidence — this is that evidence, per contact, in one file.
     */
    public function consentEvidence(MailchimpImport $import): StreamedResponse
    {
        $consent = $import->consent_details ?? [];

        MailchimpAuditLog::record(MailchimpAuditLog::IMPORT_EXPORTED, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'scope' => 'consent evidence',
        ]);

        $filename = 'compliance-restore-request-'.$import->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($import, $consent) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Email', 'Audience', 'Mailchimp reason', 'Opt-in method', 'Opt-in wording',
                'Collected at', 'Collected on', 'Recorded by', 'Recorded at', 'Source file',
            ]);

            $import->rows()
                ->where('outcome', MailchimpImportRow::BLOCKED_UNSUBSCRIBED)
                ->orderBy('row_number')
                ->chunk(500, function ($rows) use ($handle, $import, $consent) {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->email,
                            $import->audience_name,
                            $row->detail,
                            $consent['method'] ?? '—',
                            $consent['wording'] ?? '—',
                            $consent['collected_at'] ?? $import->consent_source,
                            $consent['collected_on'] ?? '—',
                            $import->consentConfirmedBy?->name ?? '—',
                            $import->consent_confirmed_at?->toDateTimeString(),
                            $import->filename,
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
            // Whether a worker has actually picked the dry run up. The page waits
            // minutes for a large audience once it has, and gives up in seconds when
            // it has not.
            'dry_run_started' => $import->dry_run_started_at !== null,
            // What the run is waiting for, and proof it is still breathing. A relay
            // waiting out the signup form's rate limit answers for nobody for over a
            // minute at a time, so silence is not evidence of a dead worker.
            'progress_note' => $import->progress_note,
            'heartbeat' => $import->updated_at?->toIso8601String(),
            // The schedule that finishes whatever the signup form's rate limit left
            // over. Without this the page can only say a run stopped early, never
            // that something is still working through the remainder.
            'relay' => $this->relayStatus($import),
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
     * Drop an import: stop it if it is going, and take its contacts out of the
     * queue the signup form is working through.
     *
     * Contacts already sent are not undone — they are in Mailchimp, and nothing here
     * reaches them. What this removes is the file's remaining claim on the schedule,
     * which is the point when the same CSV has been uploaded four times and its
     * duplicates are queueing the same people over and over.
     */
    public function destroy(Request $request, MailchimpImport $import): JsonResponse
    {
        $wasRunning = in_array($import->status, [
            MailchimpImport::STATUS_RUNNING,
            MailchimpImport::STATUS_DRY_RUN_RUNNING,
        ], true);

        // Both jobs check the status before every stage and stop when it is not the
        // one they claimed, so this is what actually cancels them. A job already
        // inside a Mailchimp call finishes that contact first.
        if ($wasRunning) {
            $import->update([
                'status' => MailchimpImport::STATUS_FAILED,
                'failure_reason' => 'Cancelled by '.($request->user()->name ?? 'an admin').'.',
            ]);
        }

        MailchimpAuditLog::record(MailchimpAuditLog::IMPORT_DISCARDED, [
            'import_id' => $import->id,
            'account' => $import->account,
            'audience_id' => $import->audience_id,
            'filename' => $import->filename,
            'was_running' => $wasRunning,
            'outstanding' => $import->actionableCount(),
        ]);

        if ($import->stored_path) {
            Storage::disk('local')->delete($import->stored_path);
        }

        $import->rows()->delete();
        $import->delete();

        return response()->json([
            'cancelled' => $wasRunning,
            'recentImports' => $this->recentImports(),
        ]);
    }

    /**
     * Where the drip has got to for this import's account.
     *
     * Null when nothing is outstanding, so the page only talks about the schedule
     * when the schedule has something to do.
     *
     * @return array<string, mixed>|null
     */
    protected function relayStatus(MailchimpImport $import): ?array
    {
        $outstanding = $import->actionableCount();

        if ($outstanding === 0) {
            return null;
        }

        $last = MailchimpFormRelayRun::latestFor($import->account);
        $plan = MailchimpFormRelayRun::plan($import->account);

        return [
            'outstanding' => $outstanding,
            'recovered' => $import->rows()->where('outcome', MailchimpImportRow::RECOVERED_VIA_FORM)->count(),
            // Every account queues against the same form, so a second import's
            // contacts are ahead of or behind this one's.
            'queued_account_wide' => MailchimpImportRow::query()
                ->where('outcome', MailchimpImportRow::WILL_RESUBSCRIBE)
                ->whereHas('import', fn ($q) => $q
                    ->where('account', $import->account)
                    ->where('status', MailchimpImport::STATUS_COMPLETE)
                    ->whereNotNull('consent_details'))
                ->distinct()
                ->count('email'),
            'next_batch' => $plan['batch'],
            'next_attempt_at' => $last?->next_attempt_at?->toIso8601String(),
            'next_attempt_human' => $last?->next_attempt_at?->isFuture()
                ? $last->next_attempt_at->diffForHumans()
                : 'due now',
            'last_accepted' => $last?->accepted,
            'last_throttled' => (bool) $last?->throttled,
            'ever_run' => $last !== null,
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
