<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use Inertia\Inertia;
use App\Models\Location;
use App\Models\Prize;
use App\Models\SignUpForm;
use Illuminate\Support\Facades\DB;

class PickaWinnerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // Pick A Winner Index Page
    public function index() {
        return Inertia::render('PickaWinner', [
            'events' => Events::latest()->get(),
        ]);
    }

    // Pick A Winner Page select Location
    public function pickawinner($eventId) {
        $event = Events::findOrFail($eventId);
        $locations = Location::where('event_id', $eventId)->get();
    
        return Inertia::render('PickaWinnerPage', [
            'event' => $event,
            'locations' => $locations
        ]);
    }

    // Selected Location for Pick a Winner
    public function pickawinnerlocationpage($locationId, $eventId) {
        $signUpForm = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $tableName = $signUpForm->table_name;
    
        // ✅ Check if prizes already exist for this event & location
        $existingPrizesCount = Prize::where('event_id', $eventId)
            ->where('location_id', $locationId)
            ->count();
    
        // ✅ Only create prizes if none exist (Executes ONCE)
        if ($existingPrizesCount === 0) {
            for ($i = 1; $i <= 5; $i++) {
                Prize::create([
                    'event_id' => $eventId,
                    'location_id' => $locationId,
                    'prize_name' => "Prize $i",
                    'winner' => "No Winner Yet",
                ]);
            }
        }
    
        $location = Location::where('id', $locationId)->firstOrFail();
        $event = Events::findOrFail($eventId);
        $prize = Prize::where('event_id', $eventId)->where('location_id', $locationId)->get();
        // ✅ Get the dynamic table name from the event

        // ✅ Query the event’s signup form table for attendees from this location
        $attendees = DB::table($tableName)
            ->where('events_location', $location->name) // ✅ Filter by selected location
            ->select('email_address', 'first_name', 'last_name', 'mobile_number') // ✅ Adjust based on actual column names
            ->get();
    
        return Inertia::render('PickaWinnerLocationPage', [
            'location' => $location,
            'event' => $event,
            'attendees' => $attendees, // ✅ Send attendee data to Vue
            'prizes' => $prize,
        ]);
    }
    
}
