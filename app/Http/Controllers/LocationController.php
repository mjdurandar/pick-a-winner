<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;    
use App\Models\Events;
use App\Models\Location;
use App\Services\MailchimpService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    protected $mailchimpService;

    public function __construct(MailchimpService $mailchimpService)
    {
        $this->mailchimpService = $mailchimpService;
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

        return Inertia::render('PickaWinnerPage', [
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

    public function importDataToMailChimp(Request $request)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'list_id' => 'required|string',
            'tags' => 'nullable|array'
        ]);

        try {
            // Get the location
            $location = Location::findOrFail($request->location_id);
            
            // Get the signup form for the event
            $signupForm = DB::table('sign_up_forms')
                ->where('event_id', $location->event_id)
                ->first();

            if (!$signupForm) {
                return response()->json(['error' => 'No signup form found for this event'], 404);
            }

            // Get all subscribers for this location
            $subscribers = DB::table($signupForm->table_name)
                ->where('location_id', $location->id)
                ->get();

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            // Process tags to ensure they are strings
            $tags = array_map('strval', $request->tags ?? []);

            foreach ($subscribers as $subscriber) {
                try {
                    $this->mailchimpService->addSubscriberToList(
                        $request->list_id,
                        [
                            'email_address' => $subscriber->email_address,
                            'first_name' => $subscriber->first_name,
                            'last_name' => $subscriber->last_name,
                            'mobile_number' => $subscriber->mobile_number,
                            'street_address' => $subscriber->street_address,
                            'street_address_2' => $subscriber->street_address_2,
                            'city' => $subscriber->city,
                            'state' => $subscriber->state,
                            'zip_code' => $subscriber->zip_code,
                            'country' => $subscriber->country,
                            'gender' => $subscriber->gender,
                            'age' => $subscriber->age,
                        ],
                        $tags
                    );
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Failed to import {$subscriber->email_address}: {$e->getMessage()}";
                }
            }

            return response()->json([
                'message' => "Import completed. Successfully imported {$results['success']} subscribers. Failed: {$results['failed']}",
                'details' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
