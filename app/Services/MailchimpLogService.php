<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use App\Models\Location;
use App\Models\MailchimpLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MailchimpLogService
{
    private $logFile = 'mailchimp-imports.log';

    public function logImport($locationId, $locationName, $data)
    {
        try {
            Log::info('Starting logImport', [
                'locationId' => $locationId,
                'locationName' => $locationName,
                'data' => $data
            ]);

            // 1. Log to file (keep this for backup and immediate access)
            $timestamp = now()->format('Y-m-d H:i:s');
            $entry = "\n=== Import Entry: {$timestamp} ===\n";
            $entry .= "Location ID: {$locationId}\n";
            $entry .= "Location: {$locationName}\n";
            $entry .= "Status: " . ($data['success'] ? 'Success' : 'Failed') . "\n";
            
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

            // Append to the log file
            Storage::append($this->logFile, $entry);

            Log::info('File log created', ['entry' => $entry]);

            // 2. Log to database
            $logEntry = MailchimpLog::create([
                'location_id' => $locationId,
                'email_address' => $data['email'] ?? null,
                'status' => $data['success'] ? 'Success' : 'Failed',
                'error_message' => $data['error'] ?? null,
                'tags' => $data['tags'] ?? []
            ]);

            Log::info('Database log created', ['log_entry' => $logEntry]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error in logImport', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getLogContent($eventId = null)
    {
        try {
            Log::info('Getting log content', ['eventId' => $eventId]);

            if ($eventId === null) {
                // Return all logs from file if no event ID specified
                $exists = Storage::exists($this->logFile);
                Log::info('Checking file existence', ['exists' => $exists]);
                
                return $exists 
                    ? Storage::get($this->logFile) 
                    : "No import logs found.";
            }

            // Get logs from database for specific event
            $logs = MailchimpLog::query()
                ->select('mailchimp_logs.*', 'locations.name as location_name')
                ->join('locations', 'locations.id', '=', 'mailchimp_logs.location_id')
                ->where('locations.event_id', $eventId)
                ->orderBy('mailchimp_logs.created_at', 'desc')
                ->get();

            Log::info('Retrieved logs from database', [
                'eventId' => $eventId,
                'count' => $logs->count()
            ]);

            if ($logs->isEmpty()) {
                return "No import logs found for this event.";
            }

            // Format logs in a readable way
            $formattedLogs = $logs->map(function($log) {
                $entry = "=== Import Entry: {$log->created_at} ===\n";
                $entry .= "Location ID: {$log->location_id}\n";
                $entry .= "Location: {$log->location_name}\n";
                $entry .= "Status: {$log->status}\n";
                
                if ($log->email_address) {
                    $entry .= "Email: {$log->email_address}\n";
                }
                
                if ($log->error_message) {
                    $entry .= "Error: {$log->error_message}\n";
                }

                if ($log->tags) {
                    $entry .= "Tags: " . implode(', ', $log->tags) . "\n";
                }

                $entry .= str_repeat('-', 50) . "\n";
                return $entry;
            })->join("\n");

            Log::info('Formatted logs', ['formattedLogs' => $formattedLogs]);

            return $formattedLogs;
        } catch (\Exception $e) {
            Log::error('Error in getLogContent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getLogStats($eventId)
    {
        try {
            Log::info('Getting log stats', ['eventId' => $eventId]);

            $stats = MailchimpLog::query()
                ->join('locations', 'locations.id', '=', 'mailchimp_logs.location_id')
                ->where('locations.event_id', $eventId)
                ->select(
                    DB::raw('COUNT(*) as total_imports'),
                    DB::raw('SUM(CASE WHEN status = "Success" THEN 1 ELSE 0 END) as successful_imports'),
                    DB::raw('SUM(CASE WHEN status = "Failed" THEN 1 ELSE 0 END) as failed_imports')
                )
                ->first();

            Log::info('Retrieved stats', ['stats' => $stats]);

            return $stats;
        } catch (\Exception $e) {
            Log::error('Error in getLogStats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
} 