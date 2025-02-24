<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\SignUpForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // ✅ Fetch attendees and join with locations
        $attendees = DB::table($tableName)
            ->leftJoin('locations', "$tableName.location_id", '=', 'locations.id') // ✅ LEFT JOIN to get location name
            ->where("$tableName.event_id", $eventId)
            ->select("$tableName.*", 'locations.name as location_name') // ✅ Fetch location name
            ->get();
        
        return Inertia::render('Attendees', [
            'event' => $event,  // ✅ Pass the full event object instead of just ID
            'attendees' => $attendees
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
    
    
}
