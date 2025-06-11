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

    public function importDataToMailChimp(Request $request)
    {   
        Log::info('importDataToMailChimp called', ['data' => $request->all()]);
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

            // Use the tags directly from the request, don't modify them
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
}
