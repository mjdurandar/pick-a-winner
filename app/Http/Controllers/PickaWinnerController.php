<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use Inertia\Inertia;
use App\Models\Location;
use App\Models\Prize;
use App\Models\SignUpForm;
use App\Models\ExportLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class PickaWinnerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // Pick A Winner Index Page
    public function index() {
        return Inertia::render('PickaWinner', [
            'events' => Events::where('is_enabled', true)->latest()->get(),
        ]);
    }

    // Pick A Winner Page select Location
    public function pickawinner($eventId) {
        $event = Events::findOrFail($eventId);
        $locations = Location::where('event_id', $eventId)->get();
    
        return Inertia::render('LocationPage', [
            'event' => $event,
            'locations' => $locations
        ]);
    }

    // Selected Location for Pick a Winner
    public function pickawinnerlocationpage($locationId, $eventId) {
        // Check if location and event are verified in session
        $verifiedLocationId = Session::get('verified_location_id');
        $verifiedEventId = Session::get('verified_event_id');

        if ($verifiedLocationId != $locationId || $verifiedEventId != $eventId) {
            // If not verified, redirect to pickawinner page with error
            return redirect()->route('pickawinner.index');
        }

        $signUpForm = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $tableName = $signUpForm->table_name;
    
        $location = Location::where('id', $locationId)->firstOrFail();
        $event = Events::findOrFail($eventId);
        $prize = Prize::where('event_id', $eventId)->where('location_id', $locationId)->get();

        // Get the dynamic table name from the event
        // Query the event's signup form table for attendees from this location
        $attendees = DB::table($tableName)
        ->leftJoin('locations', "$tableName.location_id", '=', 'locations.id')
        ->select("$tableName.*", 'locations.name as location_name') // Select all columns to allow filtering by any question
        ->where("$tableName.event_id", $eventId)
        ->where("$tableName.location_id", $location->id)
        ->get();

        return Inertia::render('PickaWinnerLocationPage', [
            'location' => $location,
            'event' => $event,
            'attendees' => $attendees,
            'prizes' => $prize,
            'form' => $signUpForm, // Pass the signup form with questions for dynamic filtering
        ]);
    }

    /**
     * Public, uuid-gated host instruction guide for an event.
     *
     * Renders a shareable page explaining how a host accesses Pick a Winner,
     * unlocks a draw, and assigns a winner — and lists the per-location
     * passwords generated for this event. The event_uuid acts as the secret,
     * so this needs no login and can be shared directly with a host.
     */
    public function hostGuide($event_uuid, Request $request)
    {
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();

        // Non-sensitive event fields — the instructions password is never sent to
        // the client, whether locked or unlocked.
        $eventData = [
            'event_name' => $event->event_name,
            'event_banner' => $event->event_banner,
            'event_country' => $event->event_country,
            'event_coordinator' => $event->event_coordinator,
            'event_coordinator_email' => $event->event_coordinator_email,
            'event_uuid' => $event->event_uuid,
        ];

        // The guide is private: require the per-event password unless it has been
        // verified in this session (or no password is set, for older events).
        $unlocked = empty($event->instructions_password)
            || $request->session()->get('guide_verified_'.$event->id) === true;

        if (! $unlocked) {
            return Inertia::render('HostGuide', [
                'event' => $eventData,
                'drawPassword' => null,
                'locked' => true,
            ]);
        }

        // A single general draw password for all locations (locations share the
        // same password); show the first non-empty one.
        $drawPassword = Location::where('event_id', $event->id)
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->orderBy('date')
            ->orderBy('time')
            ->value('password');

        return Inertia::render('HostGuide', [
            'event' => $eventData,
            'drawPassword' => $drawPassword,
            'locked' => false,
        ]);
    }

    /**
     * Verify the password for a host instruction guide and unlock it for this session.
     */
    public function verifyHostGuide(Request $request, $event_uuid)
    {
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();

        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        if (empty($event->instructions_password)
            || strtoupper(trim($validated['password'])) !== strtoupper($event->instructions_password)) {
            return back()->withErrors([
                'password' => 'Incorrect password. Please try again.',
            ]);
        }

        $request->session()->put('guide_verified_'.$event->id, true);

        return redirect()->route('pickawinner.hostguide', ['event_uuid' => $event_uuid]);
    }

    public function allLocation($eventId) {
        $event = Events::findOrFail($eventId);
        $signUpForm = SignUpForm::where('event_id', $eventId)->first();
        if (!$signUpForm) {
            return redirect()->route('signup.index', ['eventId' => $event]);
        }
        $tableName = $signUpForm->table_name;
        $attendees = DB::table($tableName)
        ->leftJoin('locations', "$tableName.location_id", '=', 'locations.id') // Left Join locations
        ->select("$tableName.*", 'locations.name as location_name') // Select all event columns + location name
        ->get();
     
        // ✅ Check if prizes already exist for this event & location
        $existingPrizesCount = Prize::where('event_id', $eventId)
            ->whereNull('location_id') 
            ->count();
    
        // ✅ Only create prizes if none exist (Executes ONCE)
        if ($existingPrizesCount === 0) {
            for ($i = 1; $i <= 5; $i++) {
                Prize::create([
                    'event_id' => $eventId,
                    'location_id' => null,
                    'prize_name' => "Prize $i",
                    'winner' => "No Winner Yet",
                ]);
            }
        }

        $prizes = Prize::where('event_id', $eventId)
               ->whereNull('location_id') // ✅ Ensure location_id is NULL
               ->get();

        return Inertia::render('PickaWinnerAllLocation', [
            'event' => $event,
            'attendees' => $attendees,
            'prizes' => $prizes,
            'form' => $signUpForm, // ✅ Pass the signup form with questions
        ]);
    }

    /**
     * Get locations for the Pick a Winner dropdown.
     * When event.show_all_locations is true: return all locations.
     * When false: show each location from 2 weeks before its date until 2 weeks after its date.
     */
    public function getLocations(Events $event)
    {
        // Hide locations once a week has passed since their date
        $from = now()->subDays(7)->format('Y-m-d');

        $query = Location::where('event_id', $event->id)
            ->select(['id', 'name', 'date', 'time'])
            ->where('date', '>=', $from);

        if (! $event->show_all_locations) {
            $to = now()->addDays(14)->format('Y-m-d');
            $query->where('date', '<=', $to);
        }

        return response()->json($query->get());
    }

    public function verify(Request $request)
    {   
        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'location_id' => 'required_unless:is_all_locations,true|exists:locations,id',
            'password' => 'required|string',
            'is_all_locations' => 'boolean'
        ]);

        if ($validated['is_all_locations'] ?? false) {
            $event = Events::findOrFail($validated['event_id']);

            // For all locations, password should match event's stored password
            if (strtoupper($validated['password']) !== strtoupper($event->password)) {
                ExportLog::recordDrawAccess($request, 'failed', 'event', (string) $event->id, [
                    'event_id' => $event->id,
                    'event_name' => $event->name ?? null,
                    'is_all_locations' => true,
                    'reason' => 'wrong_password',
                ]);

                return back()->withErrors([
                    'password' => 'Invalid password. Please enter the correct event password.'
                ]);
            }

            // Mark this event as unlocked for the all-locations draw so the
            // host can manage prizes without authenticating.
            Session::put('verified_all_locations_event_id', $event->id);

            ExportLog::recordDrawAccess($request, 'success', 'event', (string) $event->id, [
                'event_id' => $event->id,
                'event_name' => $event->name ?? null,
                'is_all_locations' => true,
            ]);

            return redirect()->route('pickawinner.alllocation', [
                'event' => $validated['event_id']
            ]);
        }

        $location = Location::where('id', $validated['location_id'])
            ->where('event_id', $validated['event_id'])
            ->first();

        if (!$location) {
            ExportLog::recordDrawAccess($request, 'failed', 'event', (string) $validated['event_id'], [
                'event_id' => $validated['event_id'],
                'location_id' => $validated['location_id'] ?? null,
                'is_all_locations' => false,
                'reason' => 'invalid_location',
            ]);

            return back()->withErrors([
                'password' => 'Invalid location selected.'
            ]);
        }

        // Check if password matches the stored password
        if (strtoupper($validated['password']) !== strtoupper($location->password)) {
            ExportLog::recordDrawAccess($request, 'failed', 'location', (string) $location->id, [
                'event_id' => $validated['event_id'],
                'location_id' => $location->id,
                'location_name' => $location->name ?? null,
                'is_all_locations' => false,
                'reason' => 'wrong_password',
            ]);

            return back()->withErrors([
                'password' => 'Invalid password. Please try again.'
            ]);
        }

        // Store location access in session
        Session::put('verified_location_id', $location->id);
        Session::put('verified_event_id', $validated['event_id']);

        ExportLog::recordDrawAccess($request, 'success', 'location', (string) $location->id, [
            'event_id' => $validated['event_id'],
            'location_id' => $location->id,
            'location_name' => $location->name ?? null,
            'is_all_locations' => false,
        ]);

        if (!SignupForm::where('event_id', $validated['event_id'])->exists()) {
            return back()->withErrors([
                'password' => 'Signup form not created yet. Please contact the admin.'
            ]);
        }

        return redirect()->route('pickawinner.locationpage', [
            'location' => $validated['location_id'],
            'event' => $validated['event_id']
        ]);
    }
}
