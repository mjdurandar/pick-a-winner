<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Events;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SpreadsheetLogService
{
    /**
     * Generate copy-paste ready data for spreadsheet
     * This creates data in the exact format needed for your DOCU) RUNNATION ANZ spreadsheet
     */
    public function generateCopyPasteData($locationId, $importData, $tags = [])
    {
        try {
            // Get location and event information
            $location = Location::with('event')->findOrFail($locationId);
            $event = $location->event;
            $user = Auth::user();
            
            // Extract location city name
            $cityName = $location->name;
            
            // Get current year
            $year = date('Y');
            
            // Current date imported
            $dateImported = now()->format('Y-m-d H:i:s');
            
            // User who imported (current logged in user)
            $importedBy = $user ? $user->name : 'System';
            
            // Link saved to folder (you may want to customize this)
            $linkSavedToFolder = "Event_{$event->id}_Location_{$locationId}";
            
            // Extract import statistics
            $totalCollectedData = $importData['totalSubscribers'] ?? 0;
            $newFromImport = $importData['newCount'] ?? 0;
            $updatedData = $importData['updateCount'] ?? 0;
            $rejectedData = $importData['failureCount'] ?? 0;
            
            // Process tags (limit to 6 tags as per your spreadsheet columns)
            $tagColumns = [];
            for ($i = 0; $i < 6; $i++) {
                $tagColumns[] = $tags[$i] ?? '';
            }
            
            // Create the data array in the exact order of your spreadsheet columns
            $spreadsheetData = [
                $cityName,                    // A: Location
                $year,                       // B: Year
                $dateImported,               // C: Date Imported
                $importedBy,                 // D: Imported by
                $linkSavedToFolder,          // E: Link Saved to Folder
                $totalCollectedData,         // F: Total Collected Data
                $newFromImport,              // G: NEW from Import
                $updatedData,                // H: Updated Data
                $rejectedData,               // I: Rejected Data
                $tagColumns[0],              // J: TAG 1
                $tagColumns[1],              // K: TAG 2
                $tagColumns[2],              // L: TAG 3
                $tagColumns[3],              // M: TAG 4
                $tagColumns[4],              // N: TAG 5
                $tagColumns[5]               // O: TAG 6
            ];
            
            // Generate tab-separated format for easy copy-paste
            $tabSeparated = implode("\t", $spreadsheetData);
            
            // Debug: Log the tab-separated string to verify tab characters
            Log::info('Tab-separated data debug', [
                'raw_string' => $tabSeparated,
                'string_length' => strlen($tabSeparated),
                'tab_count' => substr_count($tabSeparated, "\t"),
                'hex_representation' => bin2hex($tabSeparated)
            ]);
            
            // Generate CSV format as alternative
            $csvFormat = implode(',', array_map(function($value) {
                return '"' . str_replace('"', '""', $value) . '"';
            }, $spreadsheetData));
            
            // Generate simple comma-separated format (most reliable)
            $simpleCommaSeparated = implode(',', $spreadsheetData);
            
            // Generate alternative format with explicit tab markers
            $alternativeFormat = implode('	', $spreadsheetData); // Using explicit tab character
            
            Log::info('Generated copy-paste data for spreadsheet', [
                'location_id' => $locationId,
                'city' => $cityName,
                'event' => $event->event_name,
                'data' => $spreadsheetData
            ]);
            
            return [
                'tab_separated' => $tabSeparated,
                'csv_format' => $csvFormat,
                'simple_comma_separated' => $simpleCommaSeparated,
                'alternative_format' => $alternativeFormat,
                'data_array' => $spreadsheetData,
                'location_name' => $cityName,
                'event_name' => $event->event_name,
                'import_date' => $dateImported
            ];
            
        } catch (\Exception $e) {
            Log::error('Error generating copy-paste data', [
                'error' => $e->getMessage(),
                'location_id' => $locationId,
                'import_data' => $importData
            ]);
            throw $e;
        }
    }
    
    /**
     * Generate formatted text for easy copy-paste
     */
    public function generateFormattedText($locationId, $importData, $tags = [])
    {
        try {
            $data = $this->generateCopyPasteData($locationId, $importData, $tags);
            
            // Create a simple format that will definitely separate
            $formattedText = "📋 COPY THIS LINE (Paste into A151):\n";
            $formattedText .= $data['tab_separated'] . "\n\n";
            
            $formattedText .= "💡 TIP: Copy the line above and paste into A151. It should separate automatically.";
            
            return $formattedText;
            
        } catch (\Exception $e) {
            Log::error('Error generating formatted text', [
                'error' => $e->getMessage(),
                'location_id' => $locationId
            ]);
            throw $e;
        }
    }
}
