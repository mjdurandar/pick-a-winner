<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\MailchimpImportLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MailchimpImportLogsController extends Controller
{
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
                'mailchimp_import_logs.has_import_file',
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
     */
    public function destroy($id)
    {
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
}
