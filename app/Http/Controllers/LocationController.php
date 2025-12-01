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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Services\MailchimpLogService;
use App\Services\SpreadsheetLogService;
use App\Models\TicketAttendee;
use App\Models\Films;
use App\Models\SignUpForm;
use App\Models\MailchimpLog;
use Carbon\Carbon;

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

        // Check which locations have been imported to Mailchimp
        $locationIds = $locations->pluck('id');
        $importedLocationIds = DB::table('mailchimp_logs')
            ->whereIn('location_id', $locationIds)
            ->where('status', 'Success')
            ->distinct()
            ->pluck('location_id');

        // Check which locations have Eventbrite data imported (check for TIX in SOURCE tag)
        // Get all successful logs and filter in PHP for better compatibility
        $allLogs = DB::table('mailchimp_logs')
            ->whereIn('location_id', $locationIds)
            ->where('status', 'Success')
            ->select('location_id', 'tags')
            ->get();

        $eventbriteImportedLocationIds = $allLogs
            ->filter(function($log) {
                $tags = json_decode($log->tags, true);
                if (!is_array($tags)) {
                    return false;
                }
                // Check if any tag contains "TIX" which indicates Eventbrite import
                foreach ($tags as $tag) {
                    if (is_string($tag) && strpos($tag, 'TIX') !== false) {
                        return true;
                    }
                }
                return false;
            })
            ->pluck('location_id')
            ->unique();

        // Add import status to each location
        $locationsWithImportStatus = $locations->map(function ($location) use ($importedLocationIds, $eventbriteImportedLocationIds) {
            $location->imported_to_mailchimp = $importedLocationIds->contains($location->id);
            $location->imported_eventbrite = $eventbriteImportedLocationIds->contains($location->id);
            return $location;
        });

        return Inertia::render('LocationPage', [
            'event' => $event,
            'locations' => $locationsWithImportStatus
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

        // Check if there are multiple locations with the same password (3-5 locations)
        $existingLocations = Location::where('event_id', $request->event_id)->get();
        
        // Group locations by password and count them
        $passwordCounts = $existingLocations->groupBy('password')->map(function ($group) {
            return $group->count();
        });
        
        // Find the most common password that appears 3 or more times
        $commonPassword = null;
        $maxCount = 0;
        foreach ($passwordCounts as $password => $count) {
            if ($count >= 3 && $count > $maxCount) {
                $commonPassword = $password;
                $maxCount = $count;
            }
        }
        
        // Use the common password if found, otherwise generate a random one
        $password = $commonPassword ?: Str::random(10);

        $location = Location::create([
            'name' => $request->name,
            'event_id' => $request->event_id,
            'date' => $request->date,
            'time' => $request->time,
            'password' => $password
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

    /**
     * Get Mailchimp import report for a specific location
     */
    public function getMailchimpLocationReport($locationId)
    {
        try {
            $location = Location::findOrFail($locationId);

            $logs = MailchimpLog::where('location_id', $locationId)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($logs->isEmpty()) {
                return response()->json([
                    'location' => $location,
                    'summary' => [
                        'total_imports' => 0,
                        'successful_imports' => 0,
                        'failed_imports' => 0,
                        'unique_emails_imported' => 0,
                        'last_import_at' => null,
                        'tags' => []
                    ],
                    'errors' => []
                ]);
            }

            $totalImports = $logs->count();
            $successfulImports = $logs->where('status', 'Success')->count();
            $failedImports = $logs->where('status', 'Failed')->count();
            $uniqueEmailsImported = $logs->where('status', 'Success')
                ->pluck('email_address')
                ->filter()
                ->unique()
                ->count();

            // Derive high-level summary similar to per-location report
            $totalCollectedData = $totalImports;
            $newFromImport = $uniqueEmailsImported; // unique successful emails
            $updatedData = max(0, $successfulImports - $uniqueEmailsImported); // additional successful rows beyond unique emails
            $rejectedData = $failedImports;

            $lastImportAt = $logs->max('created_at');
            $lastImportAtFormatted = $lastImportAt
                ? $lastImportAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
                : null;

            // Collect tags from the most recent successful log (if any)
            $latestWithTags = $logs->first(function ($log) {
                return !empty($log->tags);
            });
            $tags = $latestWithTags && is_array($latestWithTags->tags) ? $latestWithTags->tags : [];

            // Collect sample error messages
            $errors = $logs->where('status', 'Failed')
                ->take(50)
                ->map(function ($log) {
                    return [
                        'email' => $log->email_address,
                        'error' => $log->error_message,
                        'date' => $log->created_at->toDateTimeString()
                    ];
                })
                ->values();

            return response()->json([
                'location' => $location,
                'summary' => [
                    'total_imports' => $totalImports,
                    'successful_imports' => $successfulImports,
                    'failed_imports' => $failedImports,
                    'unique_emails_imported' => $uniqueEmailsImported,
                    'last_import_at' => $lastImportAtFormatted,
                    'tags' => $tags
                ],
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting Mailchimp location report', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get Mailchimp report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Mailchimp import summary for a specific event (all locations)
     */
    public function getMailchimpEventReport($eventId)
    {
        try {
            $event = Events::findOrFail($eventId);

            $logs = MailchimpLog::query()
                ->whereHas('location', function ($q) use ($eventId) {
                    $q->where('event_id', $eventId);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            if ($logs->isEmpty()) {
                return response()->json([
                    'event' => $event,
                    'summary' => [
                        'total_imports' => 0,
                        'successful_imports' => 0,
                        'failed_imports' => 0,
                        'unique_emails_imported' => 0,
                        'last_import_at' => null
                    ]
                ]);
            }

            $totalImports = $logs->count();
            $successfulImports = $logs->where('status', 'Success')->count();
            $failedImports = $logs->where('status', 'Failed')->count();
            $uniqueEmailsImported = $logs->where('status', 'Success')
                ->pluck('email_address')
                ->filter()
                ->unique()
                ->count();

            // High-level summary numbers
            $totalCollectedData = $totalImports;
            $newFromImport = $uniqueEmailsImported; // unique successful emails
            $updatedData = max(0, $successfulImports - $uniqueEmailsImported); // successful - new
            $rejectedData = $failedImports;

            $lastImportAt = $logs->max('created_at');
            $lastImportAtFormatted = $lastImportAt
                ? $lastImportAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
                : null;

            return response()->json([
                'event' => $event,
                'summary' => [
                    'total_imports' => $totalImports,
                    'successful_imports' => $successfulImports,
                    'failed_imports' => $failedImports,
                    'unique_emails_imported' => $uniqueEmailsImported,
                    'last_import_at' => $lastImportAtFormatted,
                    // High-level summary fields used by Films.vue
                    'total_collected_data' => $totalCollectedData,
                    'new_from_import' => $newFromImport,
                    'updated_data' => $updatedData,
                    'rejected_data' => $rejectedData,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting Mailchimp event report', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get Mailchimp event report: ' . $e->getMessage()
            ], 500);
        }
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

    /**
     * Generate spreadsheet data with cumulative totals
     */
    public function generateSpreadsheetData(Request $request, SpreadsheetLogService $spreadsheetService)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'import_data' => 'required|array',
            // Tags are optional when generating a summary later; default to empty array if not provided
            'tags' => 'array'
        ]);

        try {
            $copyPasteData = $spreadsheetService->generateFormattedText(
                $request->location_id,
                $request->import_data,
                $request->input('tags', [])
            );

            return response()->json([
                'copy_paste_data' => $copyPasteData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate spreadsheet data: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate spreadsheet data',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch attendees from Eventbrite API
     */
    public function fetchEventbriteAttendees(Request $request)
    {
        $request->validate([
            'event_id' => 'required|string',
            'location_id' => 'required|exists:locations,id'
        ]);

        $eventId = $request->event_id;
        $apiToken = env('EVENTBRITE_API_TOKEN');

        if (!$apiToken) {
            return response()->json([
                'error' => 'Eventbrite API token is not configured. Please set EVENTBRITE_API_TOKEN in your .env file.'
            ], 500);
        }

        try {
            $attendees = [];
            $page = 1;
            $hasMore = true;
            $seenEmails = []; // Track unique emails

            // Eventbrite API endpoint for attendees
            $baseUrl = "https://www.eventbriteapi.com/v3/events/{$eventId}/attendees/";

            while ($hasMore) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Accept' => 'application/json'
                ])->get($baseUrl, [
                    'page' => $page,
                    'status' => 'attending', // Only get confirmed attendees
                    'expand' => 'order,profile'
                ]);

                if (!$response->successful()) {
                    $errorData = $response->json();
                    $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? 'Failed to fetch attendees from Eventbrite';
                    
                    Log::error('Eventbrite API Error', [
                        'status' => $response->status(),
                        'error' => $errorMessage,
                        'event_id' => $eventId
                    ]);

                    return response()->json([
                        'error' => $errorMessage
                    ], $response->status());
                }

                $data = $response->json();
                $attendeesData = $data['attendees'] ?? [];

                foreach ($attendeesData as $attendee) {
                    $profile = $attendee['profile'] ?? [];
                    $email = strtolower(trim($profile['email'] ?? ''));

                    // Skip if no email or duplicate email
                    if (empty($email) || isset($seenEmails[$email])) {
                        continue;
                    }

                    // Mark email as seen
                    $seenEmails[$email] = true;

                    // Extract attendee information
                    $attendeeData = [
                        'email' => $email,
                        'first_name' => $profile['first_name'] ?? '',
                        'last_name' => $profile['last_name'] ?? '',
                        'phone' => $profile['cell_phone'] ?? $profile['home_phone'] ?? '',
                        'city' => $profile['city'] ?? '',
                        'state' => $profile['region'] ?? '',
                        'country' => $profile['country'] ?? '',
                    ];

                    $attendees[] = $attendeeData;
                }

                // Check if there are more pages
                $pagination = $data['pagination'] ?? [];
                $hasMore = ($pagination['has_more_items'] ?? false) && $page < 100; // Safety limit
                $page++;
            }

            // Get location and event info
            $location = Location::findOrFail($request->location_id);
            $event = Events::findOrFail($location->event_id);

            // Delete existing ticket attendees for this location (refresh data)
            TicketAttendee::where('location_id', $request->location_id)->delete();

            // Save attendees to database
            $savedCount = 0;
            foreach ($attendees as $attendee) {
                TicketAttendee::create([
                    'location_id' => $request->location_id,
                    'event_id' => $location->event_id,
                    'email' => $attendee['email'],
                    'first_name' => $attendee['first_name'],
                    'last_name' => $attendee['last_name'],
                    'phone' => $attendee['phone'],
                    'city' => $attendee['city'],
                    'state' => $attendee['state'],
                    'country' => $attendee['country'],
                    'eventbrite_event_id' => $eventId
                ]);
                $savedCount++;
            }

            Log::info('Eventbrite attendees fetched and saved', [
                'event_id' => $eventId,
                'location_id' => $request->location_id,
                'total_attendees' => count($attendees),
                'unique_emails' => count($seenEmails),
                'saved_count' => $savedCount
            ]);

            return response()->json([
                'attendees' => $attendees,
                'total' => count($attendees),
                'event_id' => $eventId,
                'saved' => $savedCount
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching Eventbrite attendees', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch attendees: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import Eventbrite attendees to Mailchimp
     */
    public function importEventbriteToMailchimp(Request $request, MailchimpLogService $logService)
    {
        // Log incoming request for debugging
        Log::info('Eventbrite import request received', [
            'has_subscribers' => $request->has('subscribers'),
            'subscribers_count' => $request->has('subscribers') ? count($request->subscribers) : 0,
            'location_id' => $request->location_id,
            'event_id' => $request->event_id,
            'list_id' => $request->list_id,
            'mailchimp_account' => $request->mailchimp_account,
            'has_tags' => $request->has('tags'),
            'tags' => $request->tags,
            'tags_type' => gettype($request->tags),
            'tags_is_array' => is_array($request->tags),
        ]);

        try {
            $request->validate([
                'subscribers' => 'required|array',
                'location_id' => 'required|integer|exists:locations,id',
                'event_id' => 'required|integer|exists:events,id',
                'list_id' => 'required|string',
                'mailchimp_account' => 'required|string|in:anz,usa',
                'tags' => 'required|array'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Eventbrite import validation failed', [
                'errors' => $e->errors(),
                'request_data' => [
                    'has_subscribers' => $request->has('subscribers'),
                    'subscribers_count' => $request->has('subscribers') ? count($request->subscribers) : 0,
                    'location_id' => $request->location_id,
                    'location_id_type' => gettype($request->location_id),
                    'event_id' => $request->event_id,
                    'event_id_type' => gettype($request->event_id),
                    'list_id' => $request->list_id,
                    'mailchimp_account' => $request->mailchimp_account,
                    'has_tags' => $request->has('tags'),
                    'tags' => $request->tags,
                    'tags_type' => gettype($request->tags),
                    'tags_is_array' => is_array($request->tags),
                ]
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        try {
            $location = Location::with('event')->findOrFail($request->location_id);
            $autoMailchimpService = app(AutoMailchimpService::class);
            $settings = $autoMailchimpService->getSettings($request->event_id);

            $listId = $request->list_id;
            $mailchimpAccount = $request->mailchimp_account;

            // Use tags from request (already includes default tags from frontend)
            $tags = $request->tags;

            // Create MailchimpService instance with selected account
            $mailchimpService = new MailchimpService($mailchimpAccount);

            $results = [
                'success' => 0,
                'failed' => 0,
                'new' => 0,
                'updated' => 0,
                'errors' => []
            ];

            $totalSubscribers = count($request->subscribers);
            $chunkSize = min(10, $totalSubscribers);
            $totalChunks = ceil($totalSubscribers / $chunkSize);
            $importedSubscribers = [];

            // Process in chunks
            for ($i = 0; $i < $totalChunks; $i++) {
                $start = $i * $chunkSize;
                $chunk = array_slice($request->subscribers, $start, $chunkSize);

                foreach ($chunk as $subscriber) {
                    try {
                        if (empty($subscriber['email_address'])) {
                            $results['failed']++;
                            $results['errors'][] = "Skipped subscriber: Missing email address";
                            continue;
                        }

                        // Import only: first name, last name, email, phone
                        // Use manualImportSubscriber to get new/updated counts
                        $result = $mailchimpService->manualImportSubscriber(
                            $listId,
                            [
                                'email_address' => $subscriber['email_address'],
                                'first_name' => $subscriber['first_name'] ?? '',
                                'last_name' => $subscriber['last_name'] ?? '',
                                'mobile_number' => $subscriber['mobile_number'] ?? '',
                            ],
                            $tags
                        );

                        $results['success']++;
                        $importedSubscribers[] = $subscriber;
                        
                        // Track new vs updated
                        if (isset($result['import_type'])) {
                            if ($result['import_type'] === 'new') {
                                $results['new']++;
                            } else if ($result['import_type'] === 'updated') {
                                $results['updated']++;
                            }
                        }

                        // Log each successful import
                        $logService->logImport($location->id, $location->name, [
                            'success' => true,
                            'email' => $subscriber['email_address'],
                            'tags' => $tags,
                            'source' => 'eventbrite'
                        ]);

                    } catch (\Exception $e) {
                        $results['failed']++;
                        $errorMessage = "Failed to import {$subscriber['email_address']}: " . substr($e->getMessage(), 0, 200);
                        $results['errors'][] = $errorMessage;

                        // Log failed import
                        $logService->logImport($location->id, $location->name, [
                            'success' => false,
                            'email' => $subscriber['email_address'] ?? 'unknown',
                            'error' => $e->getMessage(),
                            'tags' => $tags,
                            'source' => 'eventbrite'
                        ]);

                        Log::error('Eventbrite Mailchimp import error', [
                            'email' => $subscriber['email_address'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            Log::info('Eventbrite attendees imported to Mailchimp', [
                'location_id' => $location->id,
                'total' => $totalSubscribers,
                'success' => $results['success'],
                'failed' => $results['failed']
            ]);

            return response()->json([
                'message' => "Import completed. Success: {$results['success']}, Failed: {$results['failed']}, New: {$results['new']}, Updated: {$results['updated']}",
                'details' => [
                    'success' => $results['success'],
                    'failed' => $results['failed'],
                    'new' => $results['new'],
                    'updated' => $results['updated'],
                    'total' => $totalSubscribers,
                    'errors' => array_slice($results['errors'], 0, 10) // Limit errors in response
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error importing Eventbrite to Mailchimp', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to import to Mailchimp: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get locations for sheets modal (formatted for display)
     */
    public function getSheetsData($eventId)
    {
        try {
            $locations = Location::where('event_id', $eventId)
                ->orderBy('id', 'asc')
                ->get();

            $sheetsData = $locations->map(function ($location) {
                // Parse the name field: "Location State (if any) - Cinema"
                $name = $location->name ?? '';
                $locationName = '';
                $state = '';
                $cinema = '';

                // Check if name contains " - " (separator for cinema)
                if (strpos($name, ' - ') !== false) {
                    $parts = explode(' - ', $name, 2);
                    $locationPart = trim($parts[0]);
                    $cinema = trim($parts[1] ?? '');
                    
                    // Check if location part contains state (2-3 letter abbreviation at the end)
                    // Pattern: "Location ST" where ST is 2-3 uppercase letters
                    if (preg_match('/^(.+?)\s+([A-Z]{2,3})$/', $locationPart, $matches)) {
                        $locationName = trim($matches[1]);
                        $state = trim($matches[2]);
                    } else {
                        $locationName = $locationPart;
                    }
                } else {
                    // No cinema, check for state
                    if (preg_match('/^(.+?)\s+([A-Z]{2,3})$/', $name, $matches)) {
                        $locationName = trim($matches[1]);
                        $state = trim($matches[2]);
                    } else {
                        $locationName = $name;
                    }
                }

                return [
                    'id' => $location->id,
                    'Location' => $locationName,
                    'Cinema' => $cinema,
                    'State' => $state,
                    'Country' => $location->country ?? '',
                    'Date' => $location->date ?? '',
                    'Time' => $location->time ?? '',
                    'Category' => $location->category ?? ''
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $sheetsData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load locations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse time string to 24-hour format (HH:MM) like "20:00", "19:00", "18:30"
     * Handles formats like:
     * - "7:00PM" or "7:00 PM" → "19:00"
     * - "7pm" or "7PM" → "19:00"
     * - "7:00pm" or "7:00 pm" → "19:00"
     * - "7:00AM" or "7:00 AM" → "07:00"
     * - "7am" or "7AM" → "07:00"
     * - "19:00" (already in 24-hour format) → "19:00"
     * - "7:00" (assumes PM if no AM/PM specified and hour < 12)
     */
    private function parseTime($timeString)
    {
        if (empty($timeString)) {
            return null;
        }

        $timeString = trim($timeString);
        
        // Check if already in 24-hour format "HH:MM" or "H:MM"
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeString, $matches)) {
            $hour24 = (int)$matches[1];
            $minutes = (int)$matches[2];
            
            // Validate
            if ($hour24 < 0 || $hour24 > 23 || $minutes < 0 || $minutes > 59) {
                Log::warning('Invalid 24-hour time format', ['time' => $timeString]);
                return null;
            }
            
            // Return in HH:MM format
            return sprintf('%02d:%02d', $hour24, $minutes);
        }
        
        // Handle formats like "7pm", "7PM", "7:00pm", "7:00 pm", "7:00PM", "7:00 PM"
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', $timeString, $matches)) {
            $hour = (int)$matches[1];
            $minutes = isset($matches[2]) ? (int)$matches[2] : 0;
            $ampm = strtoupper($matches[3]);
            
            // Validate hour
            if ($hour < 1 || $hour > 12) {
                Log::warning('Invalid hour in time string', ['time' => $timeString, 'hour' => $hour]);
                return null;
            }
            
            // Validate minutes
            if ($minutes < 0 || $minutes > 59) {
                Log::warning('Invalid minutes in time string', ['time' => $timeString, 'minutes' => $minutes]);
                return null;
            }
            
            // Convert to 24-hour format
            $hour24 = $hour;
            if ($ampm === 'PM' && $hour != 12) {
                $hour24 = $hour + 12;
            } elseif ($ampm === 'AM' && $hour == 12) {
                $hour24 = 0;
            }
            
            // Return in HH:MM format
            return sprintf('%02d:%02d', $hour24, $minutes);
        }
        
        // If no format matches, try to parse with Carbon as fallback
        try {
            // Try to parse as time
            $time = Carbon::createFromTimeString($timeString);
            // Return in 24-hour format HH:MM
            return $time->format('H:i');
        } catch (\Exception $e) {
            Log::warning('Unable to parse time string', [
                'time_string' => $timeString,
                'error' => $e->getMessage()
            ]);
            
            // Return original if we can't parse it
            return $timeString;
        }
    }

    /**
     * Parse date string to YYYY-MM-DD format
     * Handles formats like:
     * - "Friday, January 23, 2026"
     * - "Sunday, 31 May 2026"
     * - "Saturday, January 24, 2026"
     * - "Wednesday, 27 May 2026"
     * - "January 24, 2026"
     * - "27 May 2026"
     * - "2026-01-24" (already in correct format)
     */
    private function parseDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        $dateString = trim($dateString);
        
        // Check if already in YYYY-MM-DD format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return $dateString;
        }
        
        // Remove day name if present (e.g., "Friday, " or "Sunday, ")
        $cleanedDate = preg_replace('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*/i', '', $dateString);
        
        // Try manual parsing first for specific formats
        
        // Format 1: "31 May 2026" or "27 May 2026" (UK/Australian - day month year)
        if (preg_match('/^(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})$/i', $cleanedDate, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $monthName = ucfirst(strtolower($matches[2]));
            $year = $matches[3];
            
            // Convert month name to number
            $monthNum = date('m', strtotime($monthName . ' 1'));
            if ($monthNum === false) {
                Log::error('Failed to convert month name', ['month' => $monthName]);
                return null;
            }
            
            return "$year-$monthNum-$day";
        }
        
        // Format 2: "January 23, 2026" or "January 24, 2026" (US - month day, year)
        if (preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2}),?\s+(\d{4})$/i', $cleanedDate, $matches)) {
            $monthName = ucfirst(strtolower($matches[1]));
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            
            // Convert month name to number
            $monthNum = date('m', strtotime($monthName . ' 1'));
            if ($monthNum === false) {
                Log::error('Failed to convert month name', ['month' => $monthName]);
                return null;
            }
            
            return "$year-$monthNum-$day";
        }
        
        // Try Carbon as fallback for other formats
        try {
            $date = Carbon::parse($cleanedDate);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            // If Carbon can't parse it, log and return null
            Log::error('Unable to parse date string', [
                'original_date' => $dateString,
                'cleaned_date' => $cleanedDate,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Save locations from sheets modal
     */
    public function saveSheetsData(Request $request)
    {
        try {
            $request->validate([
                'event_id' => 'required|exists:events,id',
                'data' => 'required|array',
                'data.*.Location' => 'required|string',
                'data.*.Cinema' => 'nullable|string',
                'data.*.State' => 'nullable|string',
                'data.*.Country' => 'nullable|string',
                'data.*.Date' => 'nullable|string',
                'data.*.Time' => 'nullable|string',
                'data.*.Category' => 'nullable|string',
            ]);

            $eventId = $request->event_id;
            $rows = $request->data;
            $created = 0;
            $updated = 0;

            // Get existing locations for password logic
            $existingLocations = Location::where('event_id', $eventId)->get();
            $passwordCounts = $existingLocations->groupBy('password')->map(function ($group) {
                return $group->count();
            });
            $commonPassword = null;
            $maxCount = 0;
            foreach ($passwordCounts as $password => $count) {
                if ($count >= 3 && $count > $maxCount) {
                    $commonPassword = $password;
                    $maxCount = $count;
                }
            }

            foreach ($rows as $row) {
                // Skip empty rows (all fields empty)
                if (empty($row['Location']) && empty($row['Cinema']) && empty($row['State'])) {
                    continue;
                }

                // Build the name: "Location State (if any) - Cinema"
                $locationName = trim($row['Location'] ?? '');
                $state = trim($row['State'] ?? '');
                $cinema = trim($row['Cinema'] ?? '');

                $name = $locationName;
                if (!empty($state)) {
                    $name .= ' ' . strtoupper($state);
                }
                if (!empty($cinema)) {
                    $name .= ' - ' . $cinema;
                }

                // Parse and convert date to YYYY-MM-DD format
                $parsedDate = $this->parseDate($row['Date'] ?? null);
                
                // Parse and convert time to standardized format
                $parsedTime = $this->parseTime($row['Time'] ?? null);
                
                // Check if this is an update (has id) or create (no id)
                if (!empty($row['id'])) {
                    // Update existing location
                    $location = Location::find($row['id']);
                    if ($location && $location->event_id == $eventId) {
                        $location->update([
                            'name' => $name,
                            'date' => $parsedDate,
                            'time' => $parsedTime,
                            'country' => $row['Country'] ?? null,
                            'category' => $row['Category'] ?? null,
                        ]);
                        $updated++;
                    }
                } else {
                    // Create new location
                    $password = $commonPassword ?: Str::random(10);
                    Location::create([
                        'name' => $name,
                        'event_id' => $eventId,
                        'date' => $parsedDate,
                        'time' => $parsedTime,
                        'country' => $row['Country'] ?? null,
                        'category' => $row['Category'] ?? null,
                        'password' => $password
                    ]);
                    $created++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully saved. Created: {$created}, Updated: {$updated}",
                'created' => $created,
                'updated' => $updated
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save sheets data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to save locations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket report for a specific location
     * Compares ticket emails with sign-up form emails to find duplicates
     */
    public function getLocationTicketReport($locationId)
    {
        try {
            $location = Location::with('event')->findOrFail($locationId);
            $event = $location->event;
            
            // Get ticket attendees (from Eventbrite)
            $ticketAttendees = TicketAttendee::where('location_id', $locationId)->get();
            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique()->values();
            
            // Get sign-up form attendees (from system)
            $signUpForm = SignUpForm::where('event_id', $event->id)->first();
            $signUpEmails = collect();
            
            if ($signUpForm && $signUpForm->table_name) {
                $tableName = $signUpForm->table_name;
                // Check if table exists
                if (Schema::hasTable($tableName)) {
                    $signUpAttendees = DB::table($tableName)
                        ->where('location_id', $locationId)
                        ->where('event_id', $event->id)
                        ->get();
                    
                    $signUpEmails = $signUpAttendees->pluck('email_address')
                        ->map(function ($email) {
                            return strtolower(trim($email));
                        })
                        ->filter()
                        ->unique()
                        ->values();
                }
            }
            
            // Find duplicates (emails that appear in both ticket and sign-up data)
            $duplicateEmails = $ticketEmails->intersect($signUpEmails)->values();
            
            // Emails only in tickets
            $ticketOnlyEmails = $ticketEmails->diff($signUpEmails)->values();
            
            // Emails only in sign-up forms
            $signUpOnlyEmails = $signUpEmails->diff($ticketEmails)->values();
            
            // Get detailed duplicate information
            $duplicateDetails = $duplicateEmails->map(function ($email) use ($ticketAttendees, $signUpForm, $locationId, $event) {
                // Find ticket data (case-insensitive match)
                $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                    return strtolower(trim($attendee->email)) === strtolower(trim($email));
                });
                
                $signUpData = null;
                
                if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                    $signUpData = DB::table($signUpForm->table_name)
                        ->where('location_id', $locationId)
                        ->where('event_id', $event->id)
                        ->whereRaw('LOWER(TRIM(email_address)) = ?', [strtolower(trim($email))])
                        ->first();
                }
                
                return [
                    'email' => $email,
                    'ticket_data' => $ticketData ? [
                        'first_name' => $ticketData->first_name,
                        'last_name' => $ticketData->last_name,
                        'phone' => $ticketData->phone,
                        'source' => 'Eventbrite Ticket'
                    ] : null,
                    'signup_data' => $signUpData ? [
                        'first_name' => $signUpData->first_name ?? null,
                        'last_name' => $signUpData->last_name ?? null,
                        'phone' => $signUpData->mobile_number ?? null,
                        'source' => 'Sign-Up Form'
                    ] : null
                ];
            });
            
            return response()->json([
                'location' => $location,
                'summary' => [
                    'ticket_emails_count' => $ticketEmails->count(),
                    'signup_emails_count' => $signUpEmails->count(),
                    'duplicate_emails_count' => $duplicateEmails->count(),
                    'ticket_only_count' => $ticketOnlyEmails->count(),
                    'signup_only_count' => $signUpOnlyEmails->count(),
                    'total_unique_emails' => $ticketEmails->merge($signUpEmails)->unique()->count()
                ],
                'duplicate_emails' => $duplicateDetails,
                'ticket_only_emails' => $ticketOnlyEmails,
                'signup_only_emails' => $signUpOnlyEmails
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting location ticket report', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to get ticket report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket report for all locations of a film
     * Compares ticket emails with sign-up form emails across all locations
     */
    public function getFilmTicketReport($filmId)
    {
        try {
            $film = Films::findOrFail($filmId);
            
            // Get all events for this film
            $events = Events::where('film_id', $filmId)->with('signUpForm')->get();
            $eventIds = $events->pluck('id');
            
            // Get all locations for these events
            $locations = Location::whereIn('event_id', $eventIds)->get();
            $locationIds = $locations->pluck('id');
            
            // Get all ticket attendees for these locations
            $ticketAttendees = TicketAttendee::whereIn('location_id', $locationIds)
                ->with(['location', 'event'])
                ->get();
            
            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique()->values();
            
            // Get all sign-up form emails across all events
            $allSignUpEmails = collect();
            $signUpAttendeesByLocation = [];
            
            foreach ($events as $event) {
                $signUpForm = $event->signUpForm;
                if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                    $tableName = $signUpForm->table_name;
                    $eventLocations = $locations->where('event_id', $event->id);
                    
                    foreach ($eventLocations as $location) {
                        $signUpAttendees = DB::table($tableName)
                            ->where('location_id', $location->id)
                            ->where('event_id', $event->id)
                            ->get();
                        
                        $locationSignUpEmails = $signUpAttendees->pluck('email_address')
                            ->map(function ($email) {
                                return strtolower(trim($email));
                            })
                            ->filter()
                            ->unique()
                            ->values();
                        
                        $allSignUpEmails = $allSignUpEmails->merge($locationSignUpEmails);
                        $signUpAttendeesByLocation[$location->id] = $signUpAttendees;
                    }
                }
            }
            
            $signUpEmails = $allSignUpEmails->unique()->values();
            
            // Find duplicates (emails in both ticket and sign-up data)
            $duplicateEmails = $ticketEmails->intersect($signUpEmails)->values();
            
            // Emails only in tickets
            $ticketOnlyEmails = $ticketEmails->diff($signUpEmails)->values();
            
            // Emails only in sign-up forms
            $signUpOnlyEmails = $signUpEmails->diff($ticketEmails)->values();
            
            // Get detailed duplicate information
            $duplicateDetails = $duplicateEmails->map(function ($email) use ($ticketAttendees, $events, $signUpAttendeesByLocation, $locations) {
                // Find ticket data (case-insensitive match)
                $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                    return strtolower(trim($attendee->email)) === strtolower(trim($email));
                });
                
                $signUpDataList = [];
                
                // Find all sign-up data for this email across all locations
                foreach ($events as $event) {
                    $signUpForm = $event->signUpForm;
                    if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                        $eventLocations = $locations->where('event_id', $event->id);
                        
                        foreach ($eventLocations as $location) {
                            if (isset($signUpAttendeesByLocation[$location->id])) {
                                $signUpData = $signUpAttendeesByLocation[$location->id]
                                    ->first(function ($attendee) use ($email) {
                                        return strtolower(trim($attendee->email_address ?? '')) === strtolower(trim($email));
                                    });
                                
                                if ($signUpData) {
                                    $signUpDataList[] = [
                                        'first_name' => $signUpData->first_name ?? null,
                                        'last_name' => $signUpData->last_name ?? null,
                                        'phone' => $signUpData->mobile_number ?? null,
                                        'location_name' => $location->name,
                                        'event_name' => $event->event_name,
                                        'source' => 'Sign-Up Form'
                                    ];
                                }
                            }
                        }
                    }
                }
                
                return [
                    'email' => $email,
                    'ticket_data' => $ticketData ? [
                        'first_name' => $ticketData->first_name,
                        'last_name' => $ticketData->last_name,
                        'phone' => $ticketData->phone,
                        'location_name' => $ticketData->location->name ?? 'N/A',
                        'event_name' => $ticketData->event->event_name ?? 'N/A',
                        'source' => 'Eventbrite Ticket'
                    ] : null,
                    'signup_data' => $signUpDataList
                ];
            });
            
            // Per location statistics
            $locationStats = $locations->map(function ($location) use ($ticketAttendees, $events, $signUpAttendeesByLocation) {
                $locationTicketAttendees = $ticketAttendees->where('location_id', $location->id);
                $locationTicketEmails = $locationTicketAttendees->pluck('email')
                    ->map(function ($email) {
                        return strtolower(trim($email));
                    })
                    ->filter()
                    ->unique()
                    ->values();
                
                $locationSignUpEmails = collect();
                $event = $events->where('id', $location->event_id)->first();
                
                if ($event && $event->signUpForm && $event->signUpForm->table_name && Schema::hasTable($event->signUpForm->table_name)) {
                    if (isset($signUpAttendeesByLocation[$location->id])) {
                        $locationSignUpEmails = $signUpAttendeesByLocation[$location->id]
                            ->pluck('email_address')
                            ->map(function ($email) {
                                return strtolower(trim($email));
                            })
                            ->filter()
                            ->unique()
                            ->values();
                    }
                }
                
                $locationDuplicates = $locationTicketEmails->intersect($locationSignUpEmails)->count();
                
                return [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'event_name' => $location->event->event_name ?? 'N/A',
                    'ticket_emails_count' => $locationTicketEmails->count(),
                    'signup_emails_count' => $locationSignUpEmails->count(),
                    'duplicate_emails_count' => $locationDuplicates,
                    'ticket_only_count' => $locationTicketEmails->diff($locationSignUpEmails)->count(),
                    'signup_only_count' => $locationSignUpEmails->diff($locationTicketEmails)->count()
                ];
            });
            
            return response()->json([
                'film' => $film,
                'summary' => [
                    'ticket_emails_count' => $ticketEmails->count(),
                    'signup_emails_count' => $signUpEmails->count(),
                    'duplicate_emails_count' => $duplicateEmails->count(),
                    'ticket_only_count' => $ticketOnlyEmails->count(),
                    'signup_only_count' => $signUpOnlyEmails->count(),
                    'total_unique_emails' => $ticketEmails->merge($signUpEmails)->unique()->count(),
                    'total_locations' => $locations->count(),
                    'total_events' => $events->count()
                ],
                'duplicate_emails' => $duplicateDetails,
                'ticket_only_emails' => $ticketOnlyEmails,
                'signup_only_emails' => $signUpOnlyEmails,
                'location_stats' => $locationStats,
                'events' => $events
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting film ticket report', [
                'film_id' => $filmId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to get film ticket report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket report for a single event (all locations of that event)
     * Compares ticket emails with sign-up form emails
     */
    public function getEventTicketReport($eventId)
    {
        try {
            $event = Events::with('signUpForm')->findOrFail($eventId);

            // Get all locations for this event
            $locations = Location::where('event_id', $eventId)->get();
            $locationIds = $locations->pluck('id');

            // Get all ticket attendees for these locations
            $ticketAttendees = TicketAttendee::whereIn('location_id', $locationIds)
                ->with(['location', 'event'])
                ->get();

            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique()->values();

            // Get all sign-up form emails for this event
            $signUpEmails = collect();
            $signUpAttendeesByLocation = [];

            $signUpForm = $event->signUpForm;
            if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                $tableName = $signUpForm->table_name;

                foreach ($locations as $location) {
                    $signUpAttendees = DB::table($tableName)
                        ->where('location_id', $location->id)
                        ->where('event_id', $event->id)
                        ->get();

                    $locationSignUpEmails = $signUpAttendees->pluck('email_address')
                        ->map(function ($email) {
                            return strtolower(trim($email));
                        })
                        ->filter()
                        ->unique()
                        ->values();

                    $signUpEmails = $signUpEmails->merge($locationSignUpEmails);
                    $signUpAttendeesByLocation[$location->id] = $signUpAttendees;
                }
            }

            $signUpEmails = $signUpEmails->unique()->values();

            // Find duplicates (emails in both ticket and sign-up data)
            $duplicateEmails = $ticketEmails->intersect($signUpEmails)->values();

            // Emails only in tickets
            $ticketOnlyEmails = $ticketEmails->diff($signUpEmails)->values();

            // Emails only in sign-up forms
            $signUpOnlyEmails = $signUpEmails->diff($ticketEmails)->values();

            // Get detailed duplicate information
            $duplicateDetails = $duplicateEmails->map(function ($email) use ($ticketAttendees, $event, $signUpAttendeesByLocation, $locations) {
                // Find ticket data (case-insensitive match)
                $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                    return strtolower(trim($attendee->email)) === strtolower(trim($email));
                });

                $signUpDataList = [];

                if ($event->signUpForm && $event->signUpForm->table_name && Schema::hasTable($event->signUpForm->table_name)) {
                    foreach ($locations as $location) {
                        if (isset($signUpAttendeesByLocation[$location->id])) {
                            $signUpData = $signUpAttendeesByLocation[$location->id]
                                ->first(function ($attendee) use ($email) {
                                    return strtolower(trim($attendee->email_address ?? '')) === strtolower(trim($email));
                                });

                            if ($signUpData) {
                                $signUpDataList[] = [
                                    'first_name' => $signUpData->first_name ?? null,
                                    'last_name' => $signUpData->last_name ?? null,
                                    'phone' => $signUpData->mobile_number ?? null,
                                    'location_name' => $location->name,
                                    'event_name' => $event->event_name,
                                    'source' => 'Sign-Up Form'
                                ];
                            }
                        }
                    }
                }

                return [
                    'email' => $email,
                    'ticket_data' => $ticketData ? [
                        'first_name' => $ticketData->first_name,
                        'last_name' => $ticketData->last_name,
                        'phone' => $ticketData->phone,
                        'location_name' => $ticketData->location->name ?? 'N/A',
                        'event_name' => $ticketData->event->event_name ?? 'N/A',
                        'source' => 'Eventbrite Ticket'
                    ] : null,
                    'signup_data' => $signUpDataList
                ];
            });

            // Per location statistics
            $locationStats = $locations->map(function ($location) use ($ticketAttendees, $event, $signUpAttendeesByLocation) {
                $locationTicketAttendees = $ticketAttendees->where('location_id', $location->id);
                $locationTicketEmails = $locationTicketAttendees->pluck('email')
                    ->map(function ($email) {
                        return strtolower(trim($email));
                    })
                    ->filter()
                    ->unique()
                    ->values();

                $locationSignUpEmails = collect();

                if ($event->signUpForm && $event->signUpForm->table_name && Schema::hasTable($event->signUpForm->table_name)) {
                    if (isset($signUpAttendeesByLocation[$location->id])) {
                        $locationSignUpEmails = $signUpAttendeesByLocation[$location->id]
                            ->pluck('email_address')
                            ->map(function ($email) {
                                return strtolower(trim($email));
                            })
                            ->filter()
                            ->unique()
                            ->values();
                    }
                }

                $locationDuplicates = $locationTicketEmails->intersect($locationSignUpEmails)->count();

                return [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'event_name' => $event->event_name,
                    'ticket_emails_count' => $locationTicketEmails->count(),
                    'signup_emails_count' => $locationSignUpEmails->count(),
                    'duplicate_emails_count' => $locationDuplicates,
                    'ticket_only_count' => $locationTicketEmails->diff($locationSignUpEmails)->count(),
                    'signup_only_count' => $locationSignUpEmails->diff($locationTicketEmails)->count()
                ];
            });

            return response()->json([
                'event' => $event,
                'summary' => [
                    'ticket_emails_count' => $ticketEmails->count(),
                    'signup_emails_count' => $signUpEmails->count(),
                    'duplicate_emails_count' => $duplicateEmails->count(),
                    'ticket_only_count' => $ticketOnlyEmails->count(),
                    'signup_only_count' => $signUpOnlyEmails->count(),
                    'total_unique_emails' => $ticketEmails->merge($signUpEmails)->unique()->count(),
                    'total_locations' => $locations->count(),
                ],
                'duplicate_emails' => $duplicateDetails,
                'ticket_only_emails' => $ticketOnlyEmails,
                'signup_only_emails' => $signUpOnlyEmails,
                'location_stats' => $locationStats,
                'locations' => $locations
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting event ticket report', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to get event ticket report: ' . $e->getMessage()
            ], 500);
        }
    }
}
