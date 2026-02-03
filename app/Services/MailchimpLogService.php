<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use App\Models\Location;
use App\Models\MailchimpImportLog;
use Illuminate\Support\Facades\Log;

class MailchimpLogService
{
    private $logFile = 'mailchimp-imports.log';

    /**
     * Log import activity to file only (mailchimp_import_logs is used for DB; batch logs are created in controllers).
     */
    public function logImport($locationId, $locationName, $data)
    {
        try {
            $timestamp = now()->format('Y-m-d H:i:s');
            $entry = "\n=== Import Entry: {$timestamp} ===\n";
            $entry .= "Location ID: {$locationId}\n";
            $entry .= "Location: {$locationName}\n";
            $entry .= "Status: " . (($data['success'] ?? false) ? 'Success' : 'Failed') . "\n";

            if (isset($data['email'])) {
                $entry .= "Email: {$data['email']}\n";
            }

            if (isset($data['error'])) {
                $entry .= "Error: {$data['error']}\n";
            }

            if (isset($data['tags'])) {
                $entry .= "Tags: " . (is_array($data['tags']) ? implode(', ', $data['tags']) : $data['tags']) . "\n";
            }

            $entry .= str_repeat('-', 50) . "\n";

            Storage::append($this->logFile, $entry);

            return $this->logFile;
        } catch (\Throwable $e) {
            Log::error('Error in logImport (import continues)', [
                'error' => $e->getMessage(),
            ]);
            // Do not rethrow: a logging failure must not abort the import.
        }
    }

    /**
     * Get log content. If only $eventIdOrLocationId given: treat as event_id (for downloadMailchimpLogs).
     * If $contextName is also given: treat first param as location_id (for getSyncLogs, downloadImportLog).
     */
    public function getLogContent($eventIdOrLocationId = null, $contextName = null)
    {
        try {
            if ($eventIdOrLocationId === null) {
                $exists = Storage::exists($this->logFile);
                return $exists ? Storage::get($this->logFile) : "No import logs found.";
            }

            $isLocation = $contextName !== null && $contextName !== '';

            if ($isLocation) {
                $logs = MailchimpImportLog::where('location_id', $eventIdOrLocationId)
                    ->with('location')
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else {
                $locationIds = Location::where('event_id', $eventIdOrLocationId)->pluck('id');
                $logs = MailchimpImportLog::whereIn('location_id', $locationIds)
                    ->with('location')
                    ->orderBy('created_at', 'desc')
                    ->get();
            }

            if ($logs->isEmpty()) {
                return $isLocation ? "No import logs found for this location." : "No import logs found for this event.";
            }

            $formattedLogs = $logs->map(function ($log) {
                $locationName = $log->relationLoaded('location') ? $log->location->name : ('Location #' . $log->location_id);
                $entry = "=== Import: {$log->created_at} ===\n";
                $entry .= "Location ID: {$log->location_id}\n";
                $entry .= "Location: {$locationName}\n";
                $entry .= "Source: " . ($log->source ?? 'n/a') . "\n";
                $entry .= "Total: {$log->total_data}, New: {$log->new_contacts}, Updated: {$log->updated_data}, Failed: {$log->data_with_error}\n";
                if ($log->tags && is_array($log->tags)) {
                    $entry .= "Tags: " . implode(', ', $log->tags) . "\n";
                }
                if (!empty($log->errors) && is_array($log->errors)) {
                    $entry .= "Errors: " . implode('; ', array_slice($log->errors, 0, 5)) . "\n";
                }
                $entry .= str_repeat('-', 50) . "\n";
                return $entry;
            })->join("\n");

            return $formattedLogs;
        } catch (\Exception $e) {
            Log::error('Error in getLogContent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get aggregate stats for an event from mailchimp_import_logs.
     */
    public function getLogStats($eventId)
    {
        try {
            $locationIds = Location::where('event_id', $eventId)->pluck('id');

            $stats = MailchimpImportLog::whereIn('location_id', $locationIds)
                ->selectRaw('COUNT(*) as total_imports')
                ->selectRaw('COALESCE(SUM(total_data), 0) as total_rows')
                ->selectRaw('COALESCE(SUM(total_data) - SUM(data_with_error), 0) as successful_imports')
                ->selectRaw('COALESCE(SUM(data_with_error), 0) as failed_imports')
                ->first();

            return (object) [
                'total_imports' => (int) ($stats->total_imports ?? 0),
                'successful_imports' => (int) ($stats->successful_imports ?? 0),
                'failed_imports' => (int) ($stats->failed_imports ?? 0),
            ];
        } catch (\Exception $e) {
            Log::error('Error in getLogStats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
