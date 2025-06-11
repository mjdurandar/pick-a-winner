<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class MailchimpLogService
{
    private $logFile = 'mailchimp-imports.log';

    public function logImport($locationName, $data)
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $entry = "\n=== Import Entry: {$timestamp} ===\n";
        $entry .= "Location: {$locationName}\n";
        $entry .= "Status: " . ($data['success'] ? 'Success' : 'Failed') . "\n";
        
        if (isset($data['email'])) {
            $entry .= "Email: {$data['email']}\n";
        }
        
        if (isset($data['error'])) {
            $entry .= "Error: {$data['error']}\n";
        }

        if (isset($data['tags'])) {
            $entry .= "Tags: " . implode(', ', $data['tags']) . "\n";
        }

        $entry .= str_repeat('-', 50) . "\n";

        // Append to the log file
        Storage::append($this->logFile, $entry);
    }

    public function getLogContent()
    {
        if (!Storage::exists($this->logFile)) {
            return "No import logs found.";
        }

        return Storage::get($this->logFile);
    }
} 