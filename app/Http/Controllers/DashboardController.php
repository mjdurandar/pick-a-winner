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
        $eventCount = Events::count();
        
        // Get combined data for all events
        $allEventsData = $this->getAllEventsData();
        
        // Get today's, tomorrow's, and this week's events
        $todayEvents = $this->getTodayEvents();
        $tomorrowEvents = $this->getTomorrowEvents();
        $thisWeekEvents = $this->getThisWeekEvents();
    
        return Inertia::render('Dashboard', [
            'events' => $events,
            'eventCount' => $eventCount,
            'selectedEventId' => 'all',
            'attendeesChartData' => $allEventsData['attendeesChartData'],
            'allDataAttendees' => $allEventsData['allDataAttendees'],
            'todayEvents' => $todayEvents,
            'tomorrowEvents' => $tomorrowEvents,
            'thisWeekEvents' => $thisWeekEvents
        ]);
    }
    
    public function filter(Request $request, $event, $location = null) {
        $events = Events::all();
        
        // Get today's, tomorrow's, and this week's events filtered by selected event
        $todayEvents = $this->getTodayEvents($event);
        $tomorrowEvents = $this->getTomorrowEvents($event);
        $thisWeekEvents = $this->getThisWeekEvents($event);
        
        // Handle "All Events" case
        if ($event === 'all') {
            $allEventsData = $this->getAllEventsData();
            
            return Inertia::render('Dashboard', [
                'events' => $events,
                'locations' => [],
                'selectedEventId' => 'all',
                'eventCount' => Events::count(),
                'attendeesSelectedLocation' => 0,
                'allDataAttendees' => $allEventsData['allDataAttendees'],
                'selectedLocationId' => null,
                'attendeesChartData' => $allEventsData['attendeesChartData'],
                'todayEvents' => $todayEvents,
                'tomorrowEvents' => $tomorrowEvents,
                'thisWeekEvents' => $thisWeekEvents
            ]);
        }
        
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
            'selectedEventId' => $event === 'all' ? 'all' : (int) $event, 
            'eventCount' => $eventCount ?? 0,
            'attendeesSelectedLocation' => $attendeesSelectedLocation ?? 0,
            'allDataAttendees' => $allDataAttendees ?? 0,
            'selectedLocationId' => $request->location,
            'attendeesChartData' => $attendeesChartData, // ✅ Pass data to frontend
            'todayEvents' => $todayEvents,
            'tomorrowEvents' => $tomorrowEvents,
            'thisWeekEvents' => $thisWeekEvents
        ]);
    }
    
    private function getAllEventsData() {
        $allEventsData = [
            'attendeesChartData' => [],
            'allDataAttendees' => 0
        ];
        
        // Get all events (including those without signup forms)
        $events = Events::with('signUpForm')->get();
        
        $combinedChartData = [];
        $totalAttendees = 0;
        
        foreach ($events as $event) {
            $eventAttendees = 0;
            $locationCount = 0;
            
            // Get location count for this event
            $locationCount = DB::table('locations')
                ->where('event_id', $event->id)
                ->count();
            
            // Get total attendees for this event if signup form exists
            if ($event->signUpForm && $event->signUpForm->table_name) {
                $tableName = $event->signUpForm->table_name;
                $eventAttendees = DB::table($tableName)->count();
                $totalAttendees += $eventAttendees;
            }
            
            // Add event data to chart (even if 0 attendees)
            $combinedChartData[] = [
                'location' => $event->event_name, // Use event name as the bar label
                'date' => $event->event_date ? date('F d, Y', strtotime($event->event_date)) : 'N/A',
                'time' => 'N/A', // Events don't have specific times
                'count' => $eventAttendees,
                'event_name' => $event->event_name,
                'location_count' => $locationCount,
                'full_label' => $event->event_name,
                'has_signup_form' => $event->signUpForm ? true : false
            ];
        }
        
        $allEventsData['attendeesChartData'] = $combinedChartData;
        $allEventsData['allDataAttendees'] = $totalAttendees;
        
        return $allEventsData;
    }
    
    private function getTodayEvents($eventId = null) {
        $today = now()->format('Y-m-d');
        
        // Get locations for today
        $query = Location::with('event')
            ->whereDate('date', $today)
            ->orderBy('time');
            
        // Filter by event if specified
        if ($eventId && $eventId !== 'all') {
            $query->where('event_id', $eventId);
        }
        
        $todayLocations = $query->get();
        
        $todayData = [];
        $totalAttendees = 0;
        
        foreach ($todayLocations as $location) {
            $attendees = 0;
            
            // Get attendees count if signup form exists
            if ($location->event->signUpForm && $location->event->signUpForm->table_name) {
                $tableName = $location->event->signUpForm->table_name;
                $attendees = DB::table($tableName)
                    ->where('location_id', $location->id)
                    ->count();
                $totalAttendees += $attendees;
            }
            
            $todayData[] = [
                'location_id' => $location->id,
                'event_id' => $location->event_id,
                'location_name' => $location->name,
                'event_name' => $location->event->event_name,
                'time' => date('g:i A', strtotime($location->time)),
                'attendees' => $attendees
            ];
        }
        
        return [
            'locations' => $todayData,
            'total_attendees' => $totalAttendees,
            'date' => now()->format('F d, Y')
        ];
    }
    
    private function getTomorrowEvents($eventId = null) {
        $tomorrow = now()->addDay()->format('Y-m-d');
        
        // Get locations for tomorrow
        $query = Location::with('event')
            ->whereDate('date', $tomorrow)
            ->orderBy('time');
            
        // Filter by event if specified
        if ($eventId && $eventId !== 'all') {
            $query->where('event_id', $eventId);
        }
        
        $tomorrowLocations = $query->get();
        
        $tomorrowData = [];
        $totalAttendees = 0;
        
        foreach ($tomorrowLocations as $location) {
            $attendees = 0;
            
            // Get attendees count if signup form exists
            if ($location->event->signUpForm && $location->event->signUpForm->table_name) {
                $tableName = $location->event->signUpForm->table_name;
                $attendees = DB::table($tableName)
                    ->where('location_id', $location->id)
                    ->count();
                $totalAttendees += $attendees;
            }
            
            $tomorrowData[] = [
                'location_id' => $location->id,
                'event_id' => $location->event_id,
                'location_name' => $location->name,
                'event_name' => $location->event->event_name,
                'time' => date('g:i A', strtotime($location->time)),
                'attendees' => $attendees
            ];
        }
        
        return [
            'locations' => $tomorrowData,
            'total_attendees' => $totalAttendees,
            'date' => now()->addDay()->format('F d, Y')
        ];
    }
    
    private function getThisWeekEvents($eventId = null) {
        $startOfWeek = now()->startOfWeek()->format('Y-m-d');
        $endOfWeek = now()->endOfWeek()->format('Y-m-d');
        
        // Get locations for this week
        $query = Location::with('event')
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->orderBy('date')
            ->orderBy('time');
            
        // Filter by event if specified
        if ($eventId && $eventId !== 'all') {
            $query->where('event_id', $eventId);
        }
        
        $weekLocations = $query->get();
        
        $weekData = [];
        $totalAttendees = 0;
        
        foreach ($weekLocations as $location) {
            $attendees = 0;
            
            // Get attendees count if signup form exists
            if ($location->event->signUpForm && $location->event->signUpForm->table_name) {
                $tableName = $location->event->signUpForm->table_name;
                $attendees = DB::table($tableName)
                    ->where('location_id', $location->id)
                    ->count();
                $totalAttendees += $attendees;
            }
            
            $weekData[] = [
                'location_id' => $location->id,
                'event_id' => $location->event_id,
                'location_name' => $location->name,
                'event_name' => $location->event->event_name,
                'date' => date('M d', strtotime($location->date)),
                'time' => date('g:i A', strtotime($location->time)),
                'attendees' => $attendees
            ];
        }
        
        return [
            'locations' => $weekData,
            'total_attendees' => $totalAttendees,
            'week_range' => now()->startOfWeek()->format('M d') . ' - ' . now()->endOfWeek()->format('M d, Y')
        ];
    }
}
