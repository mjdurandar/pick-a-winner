<?php

namespace App\Http\Controllers;

use App\Jobs\EventImportAllToMailchimpJob;
use App\Models\Events;
use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Services\MailchimpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Display the Mailchimp import logs page. Optional filter by event_id.
     */
    public function index(Request $request)
    {
        $eventId = $request->query('event_id');
        $source = $request->query('source');

        $logs = MailchimpImportLog::query()
            ->select(
                'mailchimp_import_logs.id',
                'mailchimp_import_logs.location_id',
                'mailchimp_import_logs.imported_by',
                'mailchimp_import_logs.total_data',
                'mailchimp_import_logs.new_contacts',
                'mailchimp_import_logs.updated_data',
                'mailchimp_import_logs.data_with_error',
                'mailchimp_import_logs.errors',
                'mailchimp_import_logs.tags',
                'mailchimp_import_logs.source',
                'mailchimp_import_logs.mailchimp_account',
                'mailchimp_import_logs.list_id',
                'mailchimp_import_logs.list_name',
                'mailchimp_import_logs.status',
                'mailchimp_import_logs.has_import_file',
                'mailchimp_import_logs.failed_rows',
                'mailchimp_import_logs.created_at',
                'locations.name as location_name',
                'events.id as event_id',
                'events.event_name as event_name',
                'users.name as imported_by_name'
            )
            ->join('locations', 'locations.id', '=', 'mailchimp_import_logs.location_id')
            ->join('events', 'events.id', '=', 'locations.event_id')
            ->leftJoin('users', 'users.id', '=', 'mailchimp_import_logs.imported_by')
            ->when($eventId, fn ($q) => $q->where('locations.event_id', $eventId))
            ->when($source && in_array($source, ['signup_form', 'ticket_data']), fn ($q) => $q->where('mailchimp_import_logs.source', $source))
            ->orderByDesc('mailchimp_import_logs.created_at')
            ->get();

        // Ensure failed_rows is always an array for the frontend (decode JSON if stored as string)
        $logs = $logs->map(function ($log) {
            $item = $log instanceof MailchimpImportLog ? $log->toArray() : (array) $log;
            if (isset($item['failed_rows']) && is_string($item['failed_rows'])) {
                $item['failed_rows'] = json_decode($item['failed_rows'], true) ?? [];
            }
            if (isset($item['failed_rows']) && ! is_array($item['failed_rows'])) {
                $item['failed_rows'] = [];
            }
            return $item;
        })->values()->all();

        $events = Events::orderBy('event_name')->get(['id', 'event_name']);

        return Inertia::render('MailchimpImportLogs', [
            'mailchimpImportLogs' => $logs,
            'events' => $events,
            'filterEventId' => $eventId ? (int) $eventId : null,
            'filterSource' => $source && in_array($source, ['signup_form', 'ticket_data']) ? $source : null,
        ]);
    }

    /**
     * Download the imported data file for a log (CSV).
     */
    public function download($id)
    {
        $log = MailchimpImportLog::with('location')->findOrFail($id);
        if (!$log->has_import_file) {
            abort(404, 'No import file for this log.');
        }
        $path = 'mailchimp_imports/' . $log->id . '.csv';
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Import file not found.');
        }
        $locationName = $log->location ? preg_replace('/[^a-z0-9_-]/i', '_', $log->location->name) : 'import';
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
            $path = 'mailchimp_imports/' . $log->id . '.csv';
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
        $log->delete();
        return back();
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
                $path = 'mailchimp_imports/' . $log->id . '.csv';
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
                $path = 'mailchimp_imports/' . $log->id . '.csv';
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
            'subscribers' => 'required|array',
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
            try {
                $result = $mailchimpService->manualImportSubscriber($listId, $subscriber, $tags);
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
                $errors[] = substr("{$subscriber['email_address']}: " . $e->getMessage(), 0, 200);
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
     * Get queued / in-progress Mailchimp Import All jobs for the "locations still importing" modal.
     */
    public function queuedImports(Request $request)
    {
        $jobs = $this->jobsTable()
            ->where('queue', config('queue.connections.database.queue', 'default'))
            ->where('payload', 'like', '%EventImportAllToMailchimpJob%')
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

            $eventIds[] = $eventId;
            foreach ($locationIdsFromPayload as $lid) {
                $locationIds[$lid] = true;
            }

            $result[] = [
                'job_id' => $row->id,
                'status' => $row->reserved_at ? 'in_progress' : 'queued',
                'event_id' => $eventId,
                'locations_count' => count($locationIdsFromPayload),
                'location_ids' => array_values($locationIdsFromPayload),
                'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
            ];
        }

        $locationIds = array_keys($locationIds);
        $events = Events::whereIn('id', array_unique($eventIds))->get()->keyBy('id');
        $locations = $locationIds ? Location::whereIn('id', $locationIds)->get()->keyBy('id') : collect();

        foreach ($result as &$item) {
            if (isset($item['event_id'])) {
                $item['event_name'] = $events->get($item['event_id'])?->event_name ?? '—';
            }
            $item['locations'] = [];
            if (! empty($item['location_ids'])) {
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
            EventImportAllToMailchimpJob::dispatch(
                $job->eventId,
                $job->listId,
                $job->mailchimpAccount,
                $job->listName,
                $remaining,
                $job->userId,
                $job->skipAlreadyImported,
                Str::uuid()->toString(),
                $job->fieldMapping ?? null
            );
        }

        return response()->json([
            'success' => true,
            'message' => count($remaining) > 0
                ? 'Location removed from import. Remaining locations are still queued.'
                : 'Location removed. No locations left in this import.',
        ]);
    }

    /**
     * Cancel a queued Import All job: if queued, deletes the job. If in progress, sets a flag so the job stops after the current location.
     */
    public function cancelQueuedImport(Request $request, $jobId)
    {
        $jobId = (int) $jobId;
        $row = $this->jobsTable()
            ->where('id', $jobId)
            ->where('queue', config('queue.connections.database.queue', 'default'))
            ->where('payload', 'like', '%EventImportAllToMailchimpJob%')
            ->first(['id', 'payload', 'reserved_at']);

        if (! $row) {
            return response()->json(['error' => 'Job not found or already processed.'], 404);
        }

        if ($row->reserved_at !== null) {
            $payload = json_decode($row->payload, true);
            $command = $payload['data']['command'] ?? null;
            if ($command !== null) {
                try {
                    $job = unserialize($command);
                    if ($job instanceof EventImportAllToMailchimpJob && $job->importBatchId) {
                        Cache::put('cancel_import_batch_' . $job->importBatchId, true, 600);
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

        $this->jobsTable()->where('id', $jobId)->delete();

        return response()->json(['success' => true, 'message' => 'Queued import cancelled.']);
    }
}
