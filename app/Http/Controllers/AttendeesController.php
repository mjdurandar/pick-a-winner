<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\SignUpForm;
use App\Models\Location;
use App\Models\Prize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AttendeesController extends Controller
{
    public function index($eventId)
    {   
        // ✅ Fetch the event object
        $event = Events::findOrFail($eventId);
        // ✅ Get the signup form for the event
        $signupForm = SignUpForm::where('event_id', $eventId)->first();
        if (!$signupForm) {
            return redirect()->route('signup.index', $eventId);
        }
        $tableName = $signupForm->table_name;

        // ✅ Fetch attendees from ALL locations – explicitly select signup table columns so join doesn't overwrite id
        $signupColumns = Schema::getColumnListing($tableName);
        $selectSignupColumns = array_map(fn ($col) => "{$tableName}.{$col}", $signupColumns);
        $selectClause = array_merge($selectSignupColumns, [DB::raw('locations.name as location_name')]);

        $attendees = DB::table($tableName)
            ->leftJoin('locations', "{$tableName}.location_id", '=', 'locations.id')
            ->where("{$tableName}.event_id", $eventId)
            ->select($selectClause)
            ->orderBy("{$tableName}.location_id")
            ->orderBy("{$tableName}.id")
            ->get();

        // ✅ Fetch all prizes (winners) for this event with location names for export on Database page
        $locations = Location::where('event_id', $eventId)->get();
        $locationById = $locations->keyBy('id');
        $prizes = Prize::where('event_id', $eventId)->orderBy('location_id')->orderBy('id')->get()
            ->map(function ($prize) use ($locationById) {
                $prize->location_name = $prize->location_id
                    ? ($locationById->get($prize->location_id)->name ?? 'Unknown')
                    : 'National Tour Wide';
                return $prize;
            });
        
        return Inertia::render('Attendees', [
            'event' => $event,  // ✅ Pass the full event object instead of just ID
            'attendees' => $attendees,
            'form' => $signupForm, // Pass the form data to get access to questions
            'prizes' => $prizes
        ]);
    }

    public function locationAttendees($eventId, $locationId)
    {
        // ✅ Fetch the event and location objects
        $event = Events::findOrFail($eventId);
        $location = Location::findOrFail($locationId);
        
        // ✅ Get the signup form for the event
        $signupForm = SignUpForm::where('event_id', $eventId)->first();
        if (!$signupForm) {
            return redirect()->route('signup.index', $eventId);
        }
        $tableName = $signupForm->table_name;

        // ✅ Fetch attendees for this specific location
        $attendees = DB::table($tableName)
            ->where("$tableName.event_id", $eventId)
            ->where("$tableName.location_id", $locationId)
            ->get();

        // ✅ Fetch prizes/winners for this location
        $prizes = Prize::where('event_id', $eventId)
            ->where('location_id', $locationId)
            ->get();
        
        return Inertia::render('LocationAttendees', [
            'event' => $event,
            'location' => $location,
            'attendees' => $attendees,
            'prizes' => $prizes,
            'form' => $signupForm
        ]);
    }

    public function destroy($attendee, $event)
    {
        // ✅ Get the signup form for the event
        $signupForm = SignUpForm::where('event_id', $event)->first();
    
        if (!$signupForm) {
            return redirect()->back()->with('error', 'Sign-up form not found for this event.');
        }
    
        $tableName = $signupForm->table_name;
    
        // ✅ Check if attendee exists in the dynamic table before deleting
        $exists = DB::table($tableName)->where('id', $attendee)->exists();
    
        if (!$exists) {
            return redirect()->route('attendees.index', ['eventId' => $event])
                             ->with('error', 'Attendee not found.');
        }
    
        // ✅ Delete the attendee from the dynamic table
        DB::table($tableName)
            ->where('id', $attendee)
            ->delete();
    
        // ✅ Redirect back to attendees list for the same event
        return redirect()->route('attendees.index', ['eventId' => $event])
                         ->with('success', 'Attendee deleted successfully.');
    }

    public function destroyFromLocation($attendee, $event, $location)
    {
        // ✅ Get the signup form for the event
        $signupForm = SignUpForm::where('event_id', $event)->first();
    
        if (!$signupForm) {
            return redirect()->back()->with('error', 'Sign-up form not found for this event.');
        }
    
        $tableName = $signupForm->table_name;
    
        // ✅ Check if attendee exists in the dynamic table before deleting
        $exists = DB::table($tableName)->where('id', $attendee)->exists();
    
        if (!$exists) {
            return redirect()->route('attendees.location', ['eventId' => $event, 'locationId' => $location])
                             ->with('error', 'Attendee not found.');
        }
    
        // ✅ Delete the attendee from the dynamic table
        DB::table($tableName)
            ->where('id', $attendee)
            ->delete();
    
        // ✅ Redirect back to location attendees page
        return redirect()->route('attendees.location', ['eventId' => $event, 'locationId' => $location])
                         ->with('success', 'Attendee deleted successfully.');
    }
    
    
}
