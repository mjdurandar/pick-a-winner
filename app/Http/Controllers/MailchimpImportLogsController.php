<?php

namespace App\Http\Controllers;

use App\Jobs\EventImportAllToMailchimpJob;
use App\Jobs\EventImportLocationToMailchimpJob;
use App\Jobs\ManualImportToMailchimpJob;
use App\Models\Events;
use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Services\MailchimpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MailchimpImportLogsController extends Controller
{
    /**
     * Query builder for the queue's jobs table (same connection/table Laravel uses for queue).
     * Fixes "queued import gone after refresh" when queue uses a different DB connection or table.
     */
    private function jobsTable()
    {
        $connection = config('queue.connections.database.connection');
        $table = config('queue.connections.database.table', 'jobs');

        return $connection
            ? DB::connection($connection)->table($table)
            : DB::table($table);
    }

    /**
     * Display the Mailchimp import logs page. Optional filter by event_id and source.
     * Pagination: per_page = 10, 50, 100, or "all". When "all", no pagination.
     * Logs with null location_id (manual CSV import from this page) show location_name as "Manual import".
     */
    public function index(Request $request)
    {
        $eventId = $request->query('event_id');
        $source = $request->query('source');
        $search = $request->query('search');
        $search = is_string($search) ? trim($search) : '';
        $perPageParam = $request->query('per_page', '10');
        $perPage = in_array($perPageParam, ['10', '50', '100'], true)
            ? (int) $perPageParam
            : 'all';

        // Whitelist of sortable columns -> raw SQL expression used for ORDER BY
        $sortMap = [
            'event_name' => "COALESCE(mailchimp_import_logs.custom_event_name, events.event_name, '')",
            'location_name' => "COALESCE(locations.name, 'Manual import')",
            'created_at' => 'mailchimp_import_logs.created_at',
            'imported_by_name' => 'users.name',
            'mailchimp_account' => 'mailchimp_import_logs.mailchimp_account',
            'list_name' => 'COALESCE(mailchimp_import_logs.list_name, mailchimp_import_logs.list_id)',
            'source' => 'COALESCE(mailchimp_import_logs.custom_source, mailchimp_import_logs.source)',
            'status' => 'mailchimp_import_logs.status',
            'total_data' => 'mailchimp_import_logs.total_data',
            'new_contacts' => 'mailchimp_import_logs.new_contacts',
            'updated_data' => 'mailchimp_import_logs.updated_data',
            'data_with_error' => 'mailchimp_import_logs.data_with_error',
            'total_resubscribed' => 'mailchimp_import_logs.total_resubscribed',
        ];
        $sortByParam = $request->query('sort_by');
        $sortBy = is_string($sortByParam) && isset($sortMap[$sortByParam]) ? $sortByParam : null;
        $sortDir = strtolower((string) $request->query('sort_dir')) === 'asc' ? 'asc' : 'desc';

        $applySearch = function ($q) use ($search) {
            if ($search === '') {
                return;
            }
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(function ($q) use ($term) {
                $q->where('mailchimp_import_logs.list_name', 'like', $term)
                    ->orWhere('mailchimp_import_logs.list_id', 'like', $term)
                    ->orWhere('mailchimp_import_logs.notes', 'like', $term)
                    ->orWhere('mailchimp_import_logs.custom_event_name', 'like', $term)
                    ->orWhere('mailchimp_import_logs.custom_source', 'like', $term)
                    ->orWhere('mailchimp_import_logs.tags', 'like', $term)
                    ->orWhere('locations.name', 'like', $term)
                    ->orWhere('events.event_name', 'like', $term)
                    ->orWhere('users.name', 'like', $term);
            });
        };

        // Totals use only event/source filter (not search), so "Totals" = all matching event/source
        $baseQuery = MailchimpImportLog::query()
            ->leftJoin('locations', 'locations.id', '=', 'mailchimp_import_logs.location_id')
            ->when($eventId, fn ($q) => $q->where(function ($q) use ($eventId) {
                $q->where('locations.event_id', $eventId)->orWhereNull('mailchimp_import_logs.location_id');
            }))
            ->when($source && in_array($source, ['signup_form', 'ticket_data', 'manual_csv', 'signup_form_resub']), fn ($q) => $q->where('mailchimp_import_logs.source', $source));

        // Totals across all matching logs (filtered by event/source only, not pagination)
        $totalsFiltered = [
            'totalImports' => (clone $baseQuery)->count(),
            'totalData' => (clone $baseQuery)->sum('mailchimp_import_logs.total_data'),
            'newContacts' => (clone $baseQuery)->sum('mailchimp_import_logs.new_contacts'),
            'updatedData' => (clone $baseQuery)->sum('mailchimp_import_logs.updated_data'),
            'dataWithError' => (clone $baseQuery)->sum('mailchimp_import_logs.data_with_error'),
            'totalResubscribed' => (clone $baseQuery)->sum('mailchimp_import_logs.total_resubscribed'),
        ];

        $query = MailchimpImportLog::query()
            ->select(
                'mailchimp_import_logs.id',
                'mailchimp_import_logs.location_id',
                'mailchimp_import_logs.imported_by',
                'mailchimp_import_logs.total_data',
                'mailchimp_import_logs.new_contacts',
                'mailchimp_import_logs.updated_data',
                'mailchimp_import_logs.data_with_error',
                'mailchimp_import_logs.total_resubscribed',
                'mailchimp_import_logs.errors',
                'mailchimp_import_logs.tags',
                'mailchimp_import_logs.source',
                'mailchimp_import_logs.mailchimp_account',
                'mailchimp_import_logs.list_id',
                'mailchimp_import_logs.list_name',
                'mailchimp_import_logs.custom_event_name',
                'mailchimp_import_logs.custom_source',
                'mailchimp_import_logs.status',
                'mailchimp_import_logs.has_import_file',
                'mailchimp_import_logs.notes',
                'mailchimp_import_logs.failed_rows',
                'mailchimp_import_logs.created_at',
                DB::raw("COALESCE(locations.name, 'Manual import') as location_name"),
                'events.id as event_id',
                DB::raw("COALESCE(mailchimp_import_logs.custom_event_name, events.event_name, '-') as event_name"),
                'users.name as imported_by_name'
            )
            ->leftJoin('locations', 'locations.id', '=', 'mailchimp_import_logs.location_id')
            ->leftJoin('events', 'events.id', '=', 'locations.event_id')
            ->leftJoin('users', 'users.id', '=', 'mailchimp_import_logs.imported_by')
            ->when($eventId, fn ($q) => $q->where(function ($q) use ($eventId) {
                $q->where('locations.event_id', $eventId)->orWhereNull('mailchimp_import_logs.location_id');
            }))
            ->when($source && in_array($source, ['signup_form', 'ticket_data', 'manual_csv', 'signup_form_resub']), fn ($q) => $q->where('mailchimp_import_logs.source', $source));

        if ($sortBy !== null) {
            $query->orderByRaw($sortMap[$sortBy].' '.strtoupper($sortDir));
            if ($sortBy !== 'created_at') {
                $query->orderByDesc('mailchimp_import_logs.created_at');
            }
        } else {
            $query->orderByDesc('mailchimp_import_logs.created_at');
        }

        $applySearch($query);

        $normalizeLog = function ($log) {
            $item = $log instanceof MailchimpImportLog ? $log->toArray() : (array) $log;
            if (isset($item['failed_rows']) && is_string($item['failed_rows'])) {
                $item['failed_rows'] = json_decode($item['failed_rows'], true) ?? [];
            }
            if (isset($item['failed_rows']) && ! is_array($item['failed_rows'])) {
                $item['failed_rows'] = [];
            }

            return $item;
        };

        if ($perPage === 'all') {
            $logs = $query->get()->map($normalizeLog)->values()->all();
            $events = Events::orderBy('event_name')->get(['id', 'event_name']);

            return Inertia::render('MailchimpImportLogs', [
                'mailchimpImportLogs' => $logs,
                'pagination' => null,
                'perPage' => 'all',
                'totalsFiltered' => $totalsFiltered,
                'events' => $events,
                'filterEventId' => $eventId ? (int) $eventId : null,
                'filterSource' => $source && in_array($source, ['signup_form', 'ticket_data', 'manual_csv', 'signup_form_resub']) ? $source : null,
                'searchKeyword' => $search !== '' ? $search : null,
                'sortBy' => $sortBy,
                'sortDir' => $sortBy ? $sortDir : null,
            ]);
        }

        $paginator = $query->paginate($perPage)->withQueryString()->through($normalizeLog);
        $events = Events::orderBy('event_name')->get(['id', 'event_name']);

        return Inertia::render('MailchimpImportLogs', [
            'mailchimpImportLogs' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'perPage' => (string) $perPage,
            'totalsFiltered' => $totalsFiltered,
            'events' => $events,
            'filterEventId' => $eventId ? (int) $eventId : null,
            'filterSource' => $source && in_array($source, ['signup_form', 'ticket_data', 'manual_csv', 'signup_form_resub']) ? $source : null,
            'searchKeyword' => $search !== '' ? $search : null,
            'sortBy' => $sortBy,
            'sortDir' => $sortBy ? $sortDir : null,
        ]);
    }

    /**
     * Queue a manual CSV import to run in the background. Returns immediately so the user can keep using the app.
     */
    public function queueManualImport(Request $request)
    {
        $request->validate([
            'subscribers' => 'required|array',
            'subscribers.*' => 'array',
            'list_id' => 'required|string|max:64',
            'list_name' => 'nullable|string|max:255',
            'mailchimp_account' => 'required|string|max:32|in:anz,usa',
            'tags' => 'required|array',
            'tags.*' => 'nullable|string|max:255',
            'field_mapping' => 'nullable|array',
            'field_mapping.*' => 'nullable|string|max:100',
            'custom_event_name' => 'nullable|string|max:255',
            'custom_source' => 'nullable|string|max:255',
        ]);

        ManualImportToMailchimpJob::dispatch(
            $request->subscribers,
            $request->list_id,
            $request->mailchimp_account,
            $request->tags,
            $request->input('field_mapping'),
            $request->input('list_name'),
            $request->filled('custom_event_name') ? trim($request->custom_event_name) : null,
            $request->filled('custom_source') ? trim($request->custom_source) : null,
            auth()->id()
        );

        return response()->json([
            'queued' => true,
            'message' => 'Import queued. You can continue using the app. The log will appear when the import finishes.',
            'subscribers_count' => count($request->subscribers),
        ]);
    }

    /**
     * Create a Mailchimp import log for a manual CSV import (from this page).
     * No location_id; source = manual_csv. Optionally stores the imported rows as a CSV file.
     */
    public function logManualImport(Request $request)
    {
        $request->validate([
            'total_data' => 'required|integer|min:0',
            'new_contacts' => 'required|integer|min:0',
            'updated_data' => 'required|integer|min:0',
            'data_with_error' => 'required|integer|min:0',
            'tags' => 'required|array',
            'tags.*' => 'nullable|string|max:255',
            'errors' => 'nullable|array',
            'errors.*' => 'string',
            'failed_rows' => 'nullable|array',
            'list_id' => 'required|string|max:64',
            'list_name' => 'nullable|string|max:255',
            'custom_event_name' => 'nullable|string|max:255',
            'custom_source' => 'nullable|string|max:255',
            'mailchimp_account' => 'required|string|max:32|in:anz,usa',
            'subscribers' => 'nullable|array',
            'subscribers.*' => 'array',
        ]);

        $tags = array_values(array_filter(array_map(function ($t) {
            return is_string($t) ? trim($t) : (string) $t;
        }, $request->tags ?: []), fn ($t) => $t !== ''));

        $log = MailchimpImportLog::create([
            'location_id' => null,
            'imported_by' => auth()->id(),
            'total_data' => $request->total_data,
            'new_contacts' => $request->new_contacts,
            'updated_data' => $request->updated_data,
            'data_with_error' => $request->data_with_error,
            'errors' => $request->input('errors', []),
            'failed_rows' => $request->input('failed_rows', []),
            'tags' => $tags,
            'source' => 'manual_csv',
            'mailchimp_account' => $request->mailchimp_account,
            'list_id' => $request->list_id,
            'list_name' => $request->input('list_name'),
            'custom_event_name' => $request->filled('custom_event_name') ? trim($request->custom_event_name) : null,
            'custom_source' => $request->filled('custom_source') ? trim($request->custom_source) : null,
            'status' => 'import',
        ]);

        $subscribers = $request->input('subscribers', []);
        if (! empty($subscribers)) {
            $headers = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];
            $escape = function ($v) {
                $s = $v === null || $v === '' ? '' : (string) $v;

                return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                    ? '"'.str_replace('"', '""', $s).'"' : $s;
            };
            $lines = [implode(',', $headers)];
            foreach ($subscribers as $row) {
                $lines[] = implode(',', array_map(function ($key) use ($row, $escape) {
                    return $escape($row[$key] ?? '');
                }, $headers));
            }
            $csv = "\xEF\xBB\xBF".implode("\r\n", $lines);
            Storage::disk('local')->put('mailchimp_imports/'.$log->id.'.csv', $csv);
            $log->update(['has_import_file' => true]);
        }

        return response()->json(['ok' => true, 'log_id' => $log->id]);
    }

    /**
     * Download the imported data file for a log (CSV).
     */
    public function download($id)
    {
        $log = MailchimpImportLog::with('location')->findOrFail($id);
        if (! $log->has_import_file) {
            abort(404, 'No import file for this log.');
        }
        $path = 'mailchimp_imports/'.$log->id.'.csv';
        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Import file not found.');
        }
        $locationName = $log->location
            ? preg_replace('/[^a-z0-9_-]/i', '_', $log->location->name)
            : ($log->source === 'manual_csv' ? 'manual-import' : 'import');
        $date = $log->created_at->format('Y-m-d');
        $filename = "mailchimp-import-{$locationName}-{$date}.csv";

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Delete a Mailchimp import log and its stored file (if any).
     * Only allowed for mj@adventureentertainment.com.
     */
    public function destroy($id)
    {
        if (strtolower(auth()->user()?->email ?? '') !== 'mj@adventureentertainment.com') {
            abort(403, 'You are not allowed to delete import logs.');
        }

        $log = MailchimpImportLog::findOrFail($id);
        if ($log->has_import_file) {
            $path = 'mailchimp_imports/'.$log->id.'.csv';
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
        $log->delete();

        return back();
    }

    /**
     * Update notes for a Mailchimp import log (inline edit, save on blur).
     */
    public function updateNotes(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:65535',
        ]);

        $log = MailchimpImportLog::findOrFail($id);
        $log->notes = $request->input('notes') ?? '';
        $log->save();

        return response()->json(['success' => true, 'notes' => $log->notes]);
    }

    /**
     * Delete all Mailchimp import logs and their stored CSV files.
     * Only allowed for mj@adventureentertainment.com.
     */
    public function destroyAll(Request $request)
    {
        if (strtolower(auth()->user()?->email ?? '') !== 'mj@adventureentertainment.com') {
            abort(403, 'You are not allowed to delete all import logs.');
        }

        $logs = MailchimpImportLog::all();
        $deleted = 0;
        foreach ($logs as $log) {
            if ($log->has_import_file) {
                $path = 'mailchimp_imports/'.$log->id.'.csv';
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            }
            $log->delete();
            $deleted++;
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'deleted' => $deleted]);
        }

        return back()->with('message', "All import logs deleted ({$deleted} logs).");
    }

    /**
     * Delete multiple Mailchimp import logs and their stored CSV files by ID.
     * Only allowed for mj@adventureentertainment.com.
     */
    public function destroyMultiple(Request $request)
    {
        if (strtolower(auth()->user()?->email ?? '') !== 'mj@adventureentertainment.com') {
            abort(403, 'You are not allowed to delete import logs.');
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:mailchimp_import_logs,id',
        ]);

        $ids = array_values(array_unique($request->ids));
        $deleted = 0;
        foreach ($ids as $id) {
            $log = MailchimpImportLog::find($id);
            if (! $log) {
                continue;
            }
            if ($log->has_import_file) {
                $path = 'mailchimp_imports/'.$log->id.'.csv';
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            }
            $log->delete();
            $deleted++;
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'deleted' => $deleted]);
        }

        return back()->with('message', "{$deleted} import log(s) deleted.");
    }

    /**
     * Re-import corrected failed rows: accept edited subscriber data, import to Mailchimp, create a new log.
     */
    public function reimportFailedRows(Request $request)
    {
        $request->validate([
            'log_id' => 'required|integer|exists:mailchimp_import_logs,id',
            'subscribers' => 'required|array|min:1',
            'subscribers.*.email_address' => 'required|string|email',
            'subscribers.*.first_name' => 'nullable|string|max:255',
            'subscribers.*.last_name' => 'nullable|string|max:255',
            'subscribers.*.mobile_number' => 'nullable|string|max:50',
            'subscribers.*.street_address' => 'nullable|string|max:255',
            'subscribers.*.street_address_2' => 'nullable|string|max:255',
            'subscribers.*.city' => 'nullable|string|max:100',
            'subscribers.*.state' => 'nullable|string|max:100',
            'subscribers.*.zip_code' => 'nullable|string|max:20',
            'subscribers.*.country' => 'nullable|string|max:100',
            'subscribers.*.gender' => 'nullable|string|max:50',
            'subscribers.*.age' => 'nullable|string|max:20',
        ]);

        $log = MailchimpImportLog::findOrFail($request->log_id);
        $listId = $log->list_id;
        $tags = is_array($log->tags) ? $log->tags : (is_string($log->tags) ? json_decode($log->tags, true) : []);
        $account = $log->mailchimp_account ?? 'anz';

        try {
            $mailchimpService = new MailchimpService($account);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Mailchimp configuration error.', 'message' => $e->getMessage()], 500);
        }

        $newCount = 0;
        $updatedCount = 0;
        $errorCount = 0;
        $errors = [];
        $failedRows = [];

        // Use the mapping stored with this import when the user chose audience + mapping; fallback for older logs
        $fieldMapping = is_array($log->field_mapping) && ! empty($log->field_mapping)
            ? $log->field_mapping
            : [
                'FNAME' => 'first_name',
                'LNAME' => 'last_name',
                'PHONE' => 'mobile_number',
                'SMSPHONE' => 'mobile_number',
                'CITY' => 'city',
                'STATE' => 'state',
                'ZIPCODE' => 'zip_code',
                'COUNTRY' => 'country',
                'GENDER' => 'gender',
                'AGE' => 'age',
                'ADDRESS' => 'street_address',
                'STREETADD' => 'street_address',
            ];

        foreach ($request->subscribers as $row) {
            $subscriber = [
                'email_address' => $row['email_address'] ?? '',
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'mobile_number' => $row['mobile_number'] ?? '',
                'street_address' => $row['street_address'] ?? '',
                'street_address_2' => $row['street_address_2'] ?? '',
                'city' => $row['city'] ?? '',
                'state' => $row['state'] ?? '',
                'zip_code' => $row['zip_code'] ?? '',
                'country' => $row['country'] ?? '',
                'gender' => $row['gender'] ?? '',
                'age' => $row['age'] ?? '',
            ];
            if (empty($subscriber['address_full'] ?? '')) {
                $subscriber['address_full'] = implode(', ', array_filter([
                    $subscriber['street_address'] ?? '',
                    $subscriber['street_address_2'] ?? '',
                    $subscriber['city'] ?? '',
                    $subscriber['state'] ?? '',
                    $subscriber['zip_code'] ?? '',
                    $subscriber['country'] ?? '',
                ]));
            }
            try {
                $result = $mailchimpService->manualImportSubscriber($listId, $subscriber, $tags, $fieldMapping);
                if (isset($result['import_type'])) {
                    if ($result['import_type'] === 'new') {
                        $newCount++;
                    } elseif ($result['import_type'] === 'updated') {
                        $updatedCount++;
                    }
                } else {
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = substr("{$subscriber['email_address']}: ".$e->getMessage(), 0, 200);
                $failedRows[] = array_merge($subscriber, ['error_message' => $e->getMessage()]);
            }
        }

        $successCount = $newCount + $updatedCount;
        MailchimpImportLog::create([
            'location_id' => $log->location_id,
            'imported_by' => auth()->id(),
            'total_data' => count($request->subscribers),
            'new_contacts' => $newCount,
            'updated_data' => $updatedCount,
            'data_with_error' => $errorCount,
            'errors' => array_slice($errors, 0, 50),
            'failed_rows' => array_slice($failedRows, 0, 100),
            'tags' => $tags,
            'source' => $log->source,
            'mailchimp_account' => $account,
            'list_id' => $listId,
            'list_name' => $log->list_name,
            'custom_event_name' => $log->custom_event_name,
            'custom_source' => $log->custom_source,
            'status' => 'reimport',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Re-imported: {$successCount} succeeded, {$errorCount} failed.",
            'new_contacts' => $newCount,
            'updated_data' => $updatedCount,
            'data_with_error' => $errorCount,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }

    /**
     * Get queued / in-progress Mailchimp import jobs (Import All + Manual CSV) for the "View queued imports" modal.
     */
    public function queuedImports(Request $request)
    {
        $queueName = config('queue.connections.database.queue', 'default');
        $jobs = $this->jobsTable()
            ->where('queue', $queueName)
            ->where(function ($q) {
                $q->where('payload', 'like', '%EventImportAllToMailchimpJob%')
                    ->orWhere('payload', 'like', '%EventImportLocationToMailchimpJob%')
                    ->orWhere('payload', 'like', '%ManualImportToMailchimpJob%');
            })
            ->orderBy('id')
            ->get(['id', 'payload', 'attempts', 'reserved_at', 'created_at']);

        $result = [];
        $eventIds = [];
        $locationIds = [];

        foreach ($jobs as $row) {
            $payload = json_decode($row->payload, true);
            $command = $payload['data']['command'] ?? null;
            if ($command === null) {
                $result[] = [
                    'job_id' => $row->id,
                    'status' => $row->reserved_at ? 'in_progress' : 'queued',
                    'event_name' => null,
                    'event_id' => null,
                    'locations' => [],
                    'job_type' => null,
                    'source' => '—',
                    'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
                ];

                continue;
            }

            try {
                $job = unserialize($command);
            } catch (\Throwable $e) {
                $result[] = [
                    'job_id' => $row->id,
                    'status' => $row->reserved_at ? 'in_progress' : 'queued',
                    'event_name' => null,
                    'event_id' => null,
                    'locations' => [],
                    'job_type' => null,
                    'source' => '—',
                    'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
                ];

                continue;
            }

            if ($job instanceof ManualImportToMailchimpJob) {
                $subscribersCount = count($job->subscribers);
                $result[] = [
                    'job_id' => $row->id,
                    'status' => $row->reserved_at ? 'in_progress' : 'queued',
                    'event_name' => 'Manual CSV import',
                    'event_id' => null,
                    'locations_count' => 1,
                    'location_ids' => [],
                    'locations' => [
                        ['location_id' => null, 'location_name' => "Manual import ({$subscribersCount} rows)"],
                    ],
                    'job_type' => 'manual_import',
                    'source' => 'Manual CSV',
                    'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
                ];

                continue;
            }

            if ($job instanceof EventImportLocationToMailchimpJob) {
                $locationId = (int) ($job->locationPayload['location_id'] ?? 0);
                $eventIds[] = $job->eventId;
                if ($locationId) {
                    $locationIds[$locationId] = true;
                }
                $source = 'Signup form';
                if (! empty($job->locationPayload['import_ticket'])) {
                    $source = ! empty($job->locationPayload['import_form']) ? 'Ticket + Signup form' : 'Ticket';
                } elseif (! empty($job->locationPayload['import_form'])) {
                    $source = 'Signup form';
                }
                $result[] = [
                    'job_id' => $row->id,
                    'status' => $row->reserved_at ? 'in_progress' : 'queued',
                    'event_id' => $job->eventId,
                    'locations_count' => 1,
                    'location_ids' => $locationId ? [$locationId] : [],
                    'job_type' => 'import_location',
                    'import_batch_id' => $job->importBatchId,
                    'source' => $source,
                    'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
                ];

                continue;
            }

            if (! $job instanceof EventImportAllToMailchimpJob) {
                continue;
            }

            $eventId = $job->eventId;
            $locationsPayload = $job->locationsPayload ?? [];
            $locationIdsFromPayload = array_map(fn ($loc) => (int) ($loc['location_id'] ?? 0), $locationsPayload);
            $locationIdsFromPayload = array_filter($locationIdsFromPayload);
            $totalLocations = count($locationIdsFromPayload);

            $eventIds[] = $eventId;
            foreach ($locationIdsFromPayload as $lid) {
                $locationIds[$lid] = true;
            }

            $source = 'Signup form';
            $first = $locationsPayload[0] ?? null;
            if ($first !== null) {
                if (! empty($first['import_ticket'])) {
                    $source = ! empty($first['import_form']) ? 'Ticket + Signup form' : 'Ticket';
                } elseif (! empty($first['import_form'])) {
                    $source = 'Signup form';
                }
            }
            $item = [
                'job_id' => $row->id,
                'status' => $row->reserved_at ? 'in_progress' : 'queued',
                'event_id' => $eventId,
                'locations_count' => $totalLocations,
                'total_locations_to_import' => $totalLocations,
                'location_ids' => array_values($locationIdsFromPayload),
                'job_type' => 'import_all',
                'import_batch_id' => $job->importBatchId,
                'source' => $source,
                'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
            ];
            if ($row->reserved_at && $job->importBatchId) {
                $progress = Cache::get('event_import_progress_'.$job->importBatchId, []);
                $item['locations_imported_so_far'] = (int) ($progress['locations_imported'] ?? 0);
                $item['locations_failed_so_far'] = (int) ($progress['locations_failed'] ?? 0);
            }
            $result[] = $item;
        }

        $locationIds = array_keys($locationIds);
        $events = Events::whereIn('id', array_unique($eventIds))->get()->keyBy('id');
        $locations = $locationIds ? Location::whereIn('id', $locationIds)->get()->keyBy('id') : collect();

        foreach ($result as &$item) {
            if (isset($item['event_id'])) {
                $item['event_name'] = $events->get($item['event_id'])?->event_name ?? '—';
            }
            if (! isset($item['locations'])) {
                $item['locations'] = [];
            }
            if (! empty($item['location_ids'] ?? null)) {
                foreach ($item['location_ids'] as $lid) {
                    $name = $locations->get($lid)?->name ?? "Location #{$lid}";
                    $item['locations'][] = ['location_id' => $lid, 'location_name' => $name];
                }
            }
            $item['locations_count'] = count($item['locations']);
            unset($item['location_ids']);
        }

        return response()->json([
            'queued_imports' => $result,
            'job_timeout_seconds' => config('queue.mailchimp_import_job_timeout', 7200),
            'queue_connection' => config('queue.default'),
        ]);
    }

    /**
     * Cancel one location from a queued (not in-progress) Import All job.
     * Removes that location from the job; if no locations remain, deletes the job. Otherwise re-dispatches with remaining locations.
     */
    public function cancelQueuedLocation(Request $request)
    {
        $request->validate([
            'job_id' => 'required|integer',
            'location_id' => 'required|integer',
        ]);
        $jobId = (int) $request->job_id;
        $locationIdToRemove = (int) $request->location_id;

        $row = $this->jobsTable()
            ->where('id', $jobId)
            ->where('queue', config('queue.connections.database.queue', 'default'))
            ->where('payload', 'like', '%EventImportAllToMailchimpJob%')
            ->first(['id', 'payload', 'reserved_at']);

        if (! $row) {
            return response()->json(['error' => 'Job not found or already processed.'], 404);
        }

        if ($row->reserved_at !== null) {
            return response()->json(['error' => 'Cannot cancel: this import is already in progress.'], 422);
        }

        $payload = json_decode($row->payload, true);
        $command = $payload['data']['command'] ?? null;
        if ($command === null) {
            return response()->json(['error' => 'Could not read job data.'], 400);
        }

        try {
            $job = unserialize($command);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not read job data.'], 400);
        }

        if (! $job instanceof EventImportAllToMailchimpJob) {
            return response()->json(['error' => 'Invalid job type.'], 400);
        }

        $locationsPayload = $job->locationsPayload ?? [];
        $remaining = array_values(array_filter($locationsPayload, function ($loc) use ($locationIdToRemove) {
            return (int) ($loc['location_id'] ?? 0) !== $locationIdToRemove;
        }));

        $this->jobsTable()->where('id', $jobId)->delete();

        if (count($remaining) > 0) {
            $newBatchId = Str::uuid()->toString();
            EventImportAllToMailchimpJob::dispatch(
                $job->eventId,
                $job->listId,
                $job->mailchimpAccount,
                $job->listName,
                $remaining,
                $job->userId,
                $job->skipAlreadyImported,
                $newBatchId,
                $job->fieldMapping ?? null
            );
            $subscribersEstimate = array_reduce($remaining, fn ($sum, $loc) => $sum + count($loc['attendees'] ?? []), 0);
            Log::info('Event import all queued – total locations to import (after removing one location)', [
                'event_id' => $job->eventId,
                'list_id' => $job->listId,
                'import_batch_id' => $newBatchId,
                'total_locations_to_import' => count($remaining),
                'subscribers_estimate' => $subscribersEstimate,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => count($remaining) > 0
                ? 'Location removed from import. Remaining locations are still queued.'
                : 'Location removed. No locations left in this import.',
        ]);
    }

    /**
     * Cancel a queued job: Import All (if queued, delete; if in progress, set flag to stop). Manual import: delete from queue.
     */
    public function cancelQueuedImport(Request $request, $jobId)
    {
        $jobId = (int) $jobId;
        $row = $this->jobsTable()
            ->where('id', $jobId)
            ->where('queue', config('queue.connections.database.queue', 'default'))
            ->where(function ($q) {
                $q->where('payload', 'like', '%EventImportAllToMailchimpJob%')
                    ->orWhere('payload', 'like', '%EventImportLocationToMailchimpJob%')
                    ->orWhere('payload', 'like', '%ManualImportToMailchimpJob%');
            })
            ->first(['id', 'payload', 'reserved_at']);

        if (! $row) {
            return response()->json(['error' => 'Job not found or already processed.'], 404);
        }

        $isManualImport = str_contains($row->payload, 'ManualImportToMailchimpJob');
        $isLocationJob = str_contains($row->payload, 'EventImportLocationToMailchimpJob')
            && ! str_contains($row->payload, 'EventImportAllToMailchimpJob');

        if ($isManualImport) {
            $this->jobsTable()->where('id', $jobId)->delete();
            Log::info('Import cancelled by user: manual import job removed from queue', ['job_id' => $jobId]);

            return response()->json(['success' => true, 'message' => 'Manual import removed from queue.']);
        }

        if ($isLocationJob) {
            if ($row->reserved_at !== null) {
                return response()->json(['error' => 'Cannot cancel: this location import is already in progress.'], 422);
            }
            $this->jobsTable()->where('id', $jobId)->delete();
            Log::info('Import cancelled by user: per-location import job removed from queue', ['job_id' => $jobId]);

            return response()->json(['success' => true, 'message' => 'Location import removed from queue.']);
        }

        if ($row->reserved_at !== null) {
            $payload = json_decode($row->payload, true);
            $command = $payload['data']['command'] ?? null;
            if ($command !== null) {
                try {
                    $job = unserialize($command);
                    if ($job instanceof EventImportAllToMailchimpJob && $job->importBatchId) {
                        Cache::put('cancel_import_batch_'.$job->importBatchId, true, 600);
                        Log::info('Import cancelled by user: stop requested for in-progress Event import', [
                            'job_id' => $jobId,
                            'import_batch_id' => $job->importBatchId,
                            'event_id' => $job->eventId,
                            'list_id' => $job->listId,
                            'locations_in_payload' => count($job->locationsPayload ?? []),
                            'reason' => 'User clicked Stop. Job will exit after current location, then the queue entry will be removed.',
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Import will stop after the current location finishes.',
                        ]);
                    }
                } catch (\Throwable $e) {
                    // fall through to error
                }
            }

            return response()->json(['error' => 'Cannot cancel: this import could not be stopped (no batch id).'], 422);
        }

        // Queued (not yet running): delete job and log what was removed
        $payload = json_decode($row->payload, true);
        $command = $payload['data']['command'] ?? null;
        $logContext = ['job_id' => $jobId, 'reason' => 'User cancelled queued import; job deleted from queue.'];
        if ($command !== null) {
            try {
                $job = unserialize($command);
                if ($job instanceof EventImportAllToMailchimpJob) {
                    $logContext['import_batch_id'] = $job->importBatchId;
                    $logContext['event_id'] = $job->eventId;
                    $logContext['list_id'] = $job->listId;
                    $logContext['locations_queued'] = count($job->locationsPayload ?? []);
                    $logContext['subscribers_estimate'] = array_reduce($job->locationsPayload ?? [], function ($sum, $loc) {
                        return $sum + count($loc['attendees'] ?? []);
                    }, 0);
                }
            } catch (\Throwable $e) {
                $logContext['payload_parse'] = 'could not unserialize job';
            }
        }
        Log::info('Import cancelled by user: queued Event import job removed from queue', $logContext);
        $this->jobsTable()->where('id', $jobId)->delete();

        return response()->json(['success' => true, 'message' => 'Queued import cancelled.']);
    }
}
