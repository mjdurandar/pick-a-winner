<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MailchimpLogService
{
    private $logFile = 'mailchimp-imports.log';

    /**
     * Log Mailchimp auto-sync activity to the storage log file.
     */
    public function logImport($locationId, $locationName, $data)
    {
        try {
            $timestamp = now()->format('Y-m-d H:i:s');
            $entry = "\n=== Import Entry: {$timestamp} ===\n";
            $entry .= "Location ID: {$locationId}\n";
            $entry .= "Location: {$locationName}\n";
            $entry .= 'Status: '.(($data['success'] ?? false) ? 'Success' : 'Failed')."\n";

            if (isset($data['email'])) {
                $entry .= "Email: {$data['email']}\n";
            }

            if (isset($data['error'])) {
                $entry .= "Error: {$data['error']}\n";
            }

            if (isset($data['tags'])) {
                $entry .= 'Tags: '.(is_array($data['tags']) ? implode(', ', $data['tags']) : $data['tags'])."\n";
            }

            $entry .= str_repeat('-', 50)."\n";

            Storage::append($this->logFile, $entry);

            return $this->logFile;
        } catch (\Throwable $e) {
            Log::error('Error in logImport (import continues)', [
                'error' => $e->getMessage(),
            ]);
            // Do not rethrow: a logging failure must not abort the import.
        }
    }
}
