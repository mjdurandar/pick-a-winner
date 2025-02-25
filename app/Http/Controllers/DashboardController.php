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

            // ✅ Map location names instead of IDs
            $attendeesChartData = $attendeesPerLocation->map(function ($item) use ($locations) {
                $location = $locations->firstWhere('id', $item->location_id);
                return [
                    'location' => $location ? $location->name : 'Unknown',
                    'count' => $item->count
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
