<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Models\Location;
use App\Models\SignUpForm;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{   
    public function index() {
        $events = Events::get();
    
        return Inertia::render('Dashboard', [
            'events' => $events
        ]);
    }
    
    public function filter(Request $request, $event, $location = null) {
        $events = Events::all();
        $locations = Location::where('event_id', $event)->get();
        $signUpForm = SignUpForm::where('event_id', $event)->first();

        // ✅ Ensure table name exists before querying
        if ($signUpForm) {
            $tableName = $signUpForm->table_name;

            // ✅ Get total attendees count for selected location
            $attendeesSelectedLocation = DB::table($tableName)
                ->where('location_id', $request->location)
                ->count();

            $allDataAttendees = DB::table($tableName)->count();
            $eventCount = Events::count();

            // ✅ Get attendee count per location for the bar chart
            $attendeesPerLocation = DB::table($tableName)
                ->select('location_id', DB::raw('COUNT(*) as count'))
                ->groupBy('location_id')
                ->get();

            // ✅ Convert to an associative array for easier mapping
            $attendeesPerLocationArray = $attendeesPerLocation->pluck('count', 'location_id')->toArray();

            // ✅ Include all locations, even if they have no attendees
            $attendeesChartData = $locations->map(function ($location) use ($attendeesPerLocationArray) {
                return [
                    'location' => $location->name,
                    'count' => $attendeesPerLocationArray[$location->id] ?? 0 // Default to 0 if no attendees
                ];
            });
        } else {
            $attendeesChartData = [];
        }

        return Inertia::render('Dashboard', [
            'events' => $events,
            'locations' => $locations ?? [],
            'selectedEventId' => (int) $event, 
            'eventCount' => $eventCount ?? 0,
            'attendeesSelectedLocation' => $attendeesSelectedLocation ?? 0,
            'allDataAttendees' => $allDataAttendees ?? 0,
            'selectedLocationId' => $request->location,
            'attendeesChartData' => $attendeesChartData // ✅ Pass data to frontend
        ]);
    }
}
