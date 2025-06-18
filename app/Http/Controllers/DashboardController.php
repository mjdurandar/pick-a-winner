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
        $locations = Location::where('event_id', $event)
            ->orderBy('date')
            ->orderBy('time')
            ->get();
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
                ->rightJoin('locations', 'locations.id', '=', $tableName . '.location_id')
                ->select(
                    'locations.id as location_id',
                    'locations.name as location_name',
                    'locations.date',
                    'locations.time',
                    DB::raw('COUNT(' . $tableName . '.id) as count')
                )
                ->where('locations.event_id', $event)
                ->groupBy('locations.id', 'locations.name', 'locations.date', 'locations.time')
                ->orderBy('locations.date')
                ->orderBy('locations.time')
                ->get();

            // ✅ Format the chart data with dates
            $attendeesChartData = $attendeesPerLocation->map(function ($item) {
                $date = date('F d, Y', strtotime($item->date));
                $time = date('g:iA', strtotime($item->time));
                return [
                    'location' => $item->location_name,
                    'date' => $date,
                    'time' => $time,
                    'count' => $item->count,
                    'full_label' => "{$date} - {$item->location_name}"
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
