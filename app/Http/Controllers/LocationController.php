<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;    
use App\Models\Events;
use App\Models\Location;
use App\Services\MailchimpService;
use App\Services\AutoMailchimpService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\MailchimpLogService;
use App\Services\SpreadsheetLogService;

class LocationController extends Controller
{
    protected $mailchimpService;
    protected $autoMailchimpService;

    public function __construct(MailchimpService $mailchimpService, AutoMailchimpService $autoMailchimpService)
    {
        $this->mailchimpService = $mailchimpService;
        $this->autoMailchimpService = $autoMailchimpService;
    }

    public function index()
    {
        $events = Events::latest()->get();
        return Inertia::render('Locations', [
            'events' => $events
        ]);
    }

    public function locationpage($eventId)
    {
        $event = Events::findOrFail($eventId);
        $locations = Location::where('event_id', $eventId)->get();

        return Inertia::render('LocationPage', [
            'event' => $event,
            'locations' => $locations
        ]);
    }

    public function updatePassword(Request $request, Location $location)
    {
        $request->validate([
            'password' => 'required|string|min:8'
        ]);

        $location->update([
            'password' => $request->password
        ]);

        return back()->with('success', 'Password updated successfully');
    }

    public function store(Request $request)
    {   
        $request->validate([
            'name' => 'required|string|max:255',
            'event_id' => 'required|exists:events,id',
            'date' => 'required|date',
            'time' => 'required'
        ]);

        $location = Location::create([
            'name' => $request->name,
            'event_id' => $request->event_id,
            'date' => $request->date,
            'time' => $request->time,
            'password' => Str::random(10) // Generate a random password for the location
        ]);

        return back()->with('success', 'Location created successfully');
    }

    public function update(Request $request, Location $location)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'time' => 'required'
        ]);

        $location->update([
            'name' => $request->name,
            'date' => $request->date,
            'time' => $request->time
        ]);

        return back()->with('success', 'Location updated successfully');
    }

    public function destroy(Location $location)
    {
        $location->delete();
        return back()->with('success', 'Location deleted successfully');
    }

    public function updateAllPasswords(Request $request, $eventId)
    {
        $request->validate([
            'password' => 'required|string|min:8'
        ]);

        $locations = Location::where('event_id', $eventId)->get();
        
        foreach ($locations as $location) {
            $location->update([
                'password' => $request->password
            ]);
        }

        return back()->with('success', 'All location passwords updated successfully');
    }

    public function getMailchimpLists()
    {
        try {
            $lists = $this->mailchimpService->getLists();
            return response()->json(['lists' => $lists]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getMailchimpMergeFields(Request $request)
    {
        try {
            $request->validate([
                'list_id' => 'required|string'
            ]);

            $mergeFields = $this->mailchimpService->getListMergeFields($request->list_id);
            
            // Check for your actual available fields based on screenshots
            $availableFields = [
                'FNAME', 'LNAME', 'CITY', 'SHOWCITY', 'STATE', 'ZIPCODE', 'COUNTRY', 
                'MMERGE11', 'GENDER', 'MMERGE18', 'MMERGE10', 'MMERGE12', 'MMERGE13', 'MMERGE14',
                'PHONE', 'SMSPHONE' // Now available based on your latest screenshot
            ];
            $existingTags = array_column($mergeFields, 'tag');
            
            // No missing ideal fields anymore since you added phone fields
            $missingIdealFields = [];
            
            // Fields that don't exist in your audience
            $missingFields = array_diff($availableFields, $existingTags);
            
            return response()->json([
                'merge_fields' => $mergeFields,
                'missing_required_fields' => $missingFields,
                'missing_ideal_fields' => $missingIdealFields,
                'field_mapping' => [
                    'FNAME' => 'First Name (Available ✅)',
                    'LNAME' => 'Last Name (Available ✅)', 
                    'EMAIL' => 'Email Address (Built-in ✅)',
                    'MMERGE10' => 'Street Address (Available ✅)',
                    'MMERGE11' => 'Address (Available ✅)',
                    'CITY' => 'City (Available ✅)',
                    'SHOWCITY' => 'Show City (Available ✅)',
                    'STATE' => 'State (Available ✅)',
                    'ZIPCODE' => 'Zip Code (Available ✅)',
                    'COUNTRY' => 'Country (Available ✅)',
                    'GENDER' => 'Gender (Available ✅)',
                    'MMERGE18' => 'Household Income (Available ✅)',
                    'MMERGE12' => 'Overseas Sports Frequency (Available ✅)',
                    'MMERGE13' => 'Equipment Spending (Available ✅)',
                    'MMERGE14' => 'Age (Available ✅)',
                    'PHONE' => 'Phone Number (Available ✅)',
                    'SMSPHONE' => 'SMS Phone Number (Available ✅ - SMS Marketing Ready!)'
                ],
                'data_to_import' => [
                    'first_name' => 'Nathan',
                    'last_name' => 'Maxwell', 
                    'email_address' => 'natedogts@gmail.com',
                    'street_address' => '131 Wairakei Ave',
                    'city' => 'Papamoa',
                    'state' => 'Bay of Plenty',
                    'zip_code' => '3118',
                    'country' => 'New Zealand',
                    'mobile_number' => '02 240 5267',
                    'age' => '22-44',
                    'gender' => 'Male'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getSubscribers(Request $request)
    {   
        $request->validate([
            'location_id' => 'required|exists:locations,id'
        ]);
        Log::info('getSubscribers called', ['data' => $request->all()]);
        try {
            $location = Location::findOrFail($request->location_id);
            Log::info('location found', ['location' => $location]);
            $signupForm = DB::table('sign_up_forms')
                ->where('event_id', $location->event_id)
                ->first();

            if (!$signupForm) {
                Log::info('no signup form found', ['location' => $location]);
                return response()->json(['error' => 'No signup form found for this event'], 404);
            }

            $subscribers = DB::table($signupForm->table_name)
                ->where('location_id', $location->id)
                ->get();

            Log::info('Found subscribers for location', [
                'location_id' => $location->id,
                'count' => $subscribers->count(),
                'table' => $signupForm->table_name
            ]);

            // Process all subscribers in a single batch
            if ($subscribers->count() > 0) {
                $this->autoMailchimpService->syncSubscribers($subscribers->all(), $location->id);
            }

            return response()->json([
                'total' => $subscribers->count(),
                'subscribers' => $subscribers
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching subscribers: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch subscribers'], 500);
        }
    }

    public function getSyncLogs(Request $request, MailchimpLogService $logService)
    {
        $locationId = $request->input('location_id');
        $location = Location::findOrFail($locationId);
        
        // Get the complete log content
        $content = $logService->getLogContent($locationId, $location->name);
        
        if (!$content) {
            return response()->json(['logs' => []]);
        }

        return response()->json([
            'logs' => [
                [
                    'date' => now()->format('Y-m-d H:i:s'),
                    'content' => $content
                ]
            ]
        ]);
    }

    public function importDataToMailchimp(Request $request)
    {   
        Log::info('importDataToMailchimp called', ['data' => $request->all()]);
        set_time_limit(60); // Set to 1 minute since we're processing smaller chunks

        $request->validate([
            'subscribers' => 'required|array',
            'list_id' => 'required|string',
            'tags' => 'required|array'
        ]);

        try {
            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            $tags = $request->tags;
            Log::info('Using tags for import:', ['tags' => $tags]);

            foreach ($request->subscribers as $subscriber) {
                try {
                    if (empty($subscriber['email_address'])) {
                        $results['failed']++;
                        $results['errors'][] = "Skipped subscriber: Missing email address";
                        continue;
                    }

                    Log::info('Mailchimp add start', [
                        'email' => $subscriber['email_address'],
                        'tags' => $tags
                    ]);
                    
                    $this->mailchimpService->addSubscriberToList(
                        $request->list_id,
                        [
                            'email_address' => $subscriber['email_address'],
                            'first_name' => $subscriber['first_name'] ?? '',
                            'last_name' => $subscriber['last_name'] ?? '',
                            'mobile_number' => $subscriber['mobile_number'] ?? '',
                            'street_address' => $subscriber['street_address'] ?? '',
                            'street_address_2' => $subscriber['street_address_2'] ?? '',
                            'city' => $subscriber['city'] ?? '',
                            'state' => $subscriber['state'] ?? '',
                            'zip_code' => $subscriber['zip_code'] ?? '',
                            'country' => $subscriber['country'] ?? '',
                            'gender' => $subscriber['gender'] ?? '',
                            'age' => $subscriber['age'] ?? '',
                        ],
                        $tags
                    );
                    Log::info('Mailchimp add end', [
                        'email' => $subscriber['email_address'],
                        'tags' => $tags
                    ]);
                    $results['success']++;

                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Failed to import {$subscriber['email_address']}: " . substr($e->getMessage(), 0, 200);
                    Log::error('Failed to import subscriber', [
                        'email' => $subscriber['email_address'],
                        'error' => $e->getMessage(),
                        'tags' => $tags
                    ]);
                }
            }

            return response()->json([
                'message' => "Chunk processed. Success: {$results['success']}, Failed: {$results['failed']}",
                'details' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Mailchimp import error: ' . $e->getMessage());
            return response()->json([
                'error' => 'An error occurred during import.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function saveImportLog(Request $request, MailchimpLogService $logService)
    {
        $logData = $request->validate([
            'location_id' => 'required|integer',
            'location_name' => 'required|string',
            'list_id' => 'required|string',
            'tags' => 'required|string',
            'totalSubscribers' => 'required|integer',
            'successCount' => 'required|integer',
            'failureCount' => 'required|integer',
            'errors' => 'array',
            'importedSubscribers' => 'array'
        ]);

        $filename = $logService->logImport(
            $logData['location_id'],
            $logData['location_name'],
            $logData
        );

        return response()->json(['status' => 'success', 'filename' => $filename]);
    }

    public function downloadImportLog(Request $request, MailchimpLogService $logService)
    {
        $locationId = $request->input('location_id');
        $locationName = $request->input('location_name');

        $content = $logService->getLogContent($locationId, $locationName);
        
        if (!$content) {
            return response()->json(['error' => 'Log file not found'], 404);
        }

        $headers = [
            'Content-type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="mailchimp-import-' . strtolower(preg_replace('/[^a-z0-9]/i', '-', $locationName)) . '.log"',
        ];

        return response($content, 200, $headers);
    }

    public function downloadMailchimpLogs(Request $request, MailchimpLogService $logService)
    {
        $eventId = $request->input('event_id');
        if (!$eventId) {
            return response()->json(['error' => 'Event ID is required'], 400);
        }

        // Get logs content
        $content = $logService->getLogContent($eventId);
        
        // Get stats
        $stats = $logService->getLogStats($eventId);
        
        // Add stats to the top of the log file
        $statsContent = "=== Import Statistics ===\n";
        $statsContent .= "Total Imports: {$stats->total_imports}\n";
        $statsContent .= "Successful Imports: {$stats->successful_imports}\n";
        $statsContent .= "Failed Imports: {$stats->failed_imports}\n";
        $statsContent .= str_repeat('=', 50) . "\n\n";
        
        $fullContent = $statsContent . $content;
        
        $headers = [
            'Content-type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="mailchimp-import-history.log"',
        ];

        return response($fullContent, 200, $headers);
    }

    public function deleteAllLocations($eventId)
    {
        try {
            $locations = Location::where('event_id', $eventId)->get();
            if ($locations->isEmpty()) {
                return back()->with('error', 'No locations found for this event');
            }

            Location::where('event_id', $eventId)->delete();
            return back()->with('success', 'All locations deleted successfully');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete locations: ' . $e->getMessage());
        }
    }

    // NEW METHOD: Manual import with enhanced field mapping and tracking
    public function manualImportToMailchimp(Request $request, MailchimpLogService $logService, SpreadsheetLogService $spreadsheetService)
    {   
        Log::info('manualImportToMailchimp called', ['data' => $request->all()]);
        set_time_limit(60);

        $request->validate([
            'subscribers' => 'required|array',
            'list_id' => 'required|string',
            'tags' => 'required|array',
            'location_id' => 'sometimes|exists:locations,id'
        ]);

        try {
            $results = [
                'success' => 0,
                'failed' => 0,
                'new' => 0,
                'updated' => 0,
                'errors' => [],
                'errorDetails' => [],
                'rejectedFields' => [],
                'rejectedFieldsCount' => 0
            ];

            $tags = $request->tags;
            $successfulSubscribers = [];
            $newSubscribers = [];
            $updatedSubscribers = [];
            Log::info('Manual import using tags:', ['tags' => $tags]);

            foreach ($request->subscribers as $subscriber) {
                try {
                    if (empty($subscriber['email_address'])) {
                        $results['failed']++;
                        $results['errors'][] = "Skipped subscriber: Missing email address";
                        continue;
                    }

                    Log::info('Manual import - Mailchimp add start', [
                        'email' => $subscriber['email_address'],
                        'tags' => $tags,
                        'available_fields' => array_keys($subscriber)
                    ]);
                    
                    // Use the new manual import method with smart field mapping
                    $result = $this->mailchimpService->manualImportSubscriber(
                        $request->list_id,
                        $subscriber,
                        $tags
                    );
                    
                    Log::info('Manual import - Mailchimp add end', [
                        'email' => $subscriber['email_address'],
                        'tags' => $tags,
                        'import_type' => $result['import_type'] ?? 'unknown'
                    ]);
                    
                    $results['success']++;
                    $successfulSubscribers[] = $subscriber;
                    
                    // Track new vs updated
                    if (isset($result['import_type'])) {
                        if ($result['import_type'] === 'new') {
                            $results['new']++;
                            $newSubscribers[] = $subscriber;
                        } else if ($result['import_type'] === 'updated') {
                            $results['updated']++;
                            $updatedSubscribers[] = $subscriber;
                        }
                    }
                    
                    // Track rejected fields
                    if (isset($result['rejected_fields']) && !empty($result['rejected_fields'])) {
                        $subscriberRejectedFields = [
                            'email' => $subscriber['email_address'],
                            'name' => trim(($subscriber['first_name'] ?? '') . ' ' . ($subscriber['last_name'] ?? '')),
                            'rejected_fields' => $result['rejected_fields']
                        ];
                        $results['rejectedFields'][] = $subscriberRejectedFields;
                        $results['rejectedFieldsCount'] += count($result['rejected_fields']);
                    }

                } catch (\Exception $e) {
                    $results['failed']++;
                    $errorMessage = "Failed to import {$subscriber['email_address']}: " . substr($e->getMessage(), 0, 200);
                    $results['errors'][] = $errorMessage;
                    $results['errorDetails'][] = [
                        'email' => $subscriber['email_address'],
                        'error' => $e->getMessage(),
                        'subscriber_data' => $subscriber
                    ];
                    Log::error('Manual import - Failed to import subscriber', [
                        'email' => $subscriber['email_address'],
                        'error' => $e->getMessage(),
                        'tags' => $tags,
                        'subscriber_data' => $subscriber
                    ]);
                }
            }

            // Log the import session if location_id is provided
            if ($request->has('location_id')) {
                try {
                    $logStats = [
                        'success' => $results['success'] > 0, // Boolean for compatibility
                        'totalSubscribers' => count($request->subscribers),
                        'successCount' => $results['success'],
                        'failureCount' => $results['failed'],
                        'updateCount' => $results['updated'],
                        'newCount' => $results['new'],
                        'errors' => $results['errors'],
                        'errorDetails' => $results['errorDetails'],
                        'rejectedFields' => $results['rejectedFields'],
                        'rejectedFieldsCount' => $results['rejectedFieldsCount'],
                        'tags' => $tags
                    ];
                    
                    $logService->logImport($request->location_id, 'Manual Import', $logStats);
                    
                    // Generate copy-paste data for spreadsheet
                    try {
                        $copyPasteData = $spreadsheetService->generateFormattedText(
                            $request->location_id, 
                            $logStats, 
                            $tags
                        );
                        
                        // Add copy-paste data to response
                        $results['copy_paste_data'] = $copyPasteData;
                        
                        Log::info('Generated copy-paste data for spreadsheet', [
                            'location_id' => $request->location_id
                        ]);
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to generate copy-paste data', [
                            'error' => $e->getMessage(),
                            'location_id' => $request->location_id
                        ]);
                        // Don't fail the import if copy-paste generation fails
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to log manual import session', [
                        'error' => $e->getMessage(),
                        'location_id' => $request->location_id
                    ]);
                }
            }

            return response()->json([
                'message' => "Manual import processed. Success: {$results['success']}, Failed: {$results['failed']}, New: {$results['new']}, Updated: {$results['updated']}",
                'details' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Manual Mailchimp import error: ' . $e->getMessage());
            return response()->json([
                'error' => 'An error occurred during manual import.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
