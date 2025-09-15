<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Models\Location;
use App\Models\SignUpForm;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class WeeklyReportController extends Controller
{
    public function index()
    {
        // Default to current week (Monday to Sunday)
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        
        return $this->generateReport($startOfWeek, $endOfWeek);
    }
    
    public function generate(Request $request)
    {
        // Handle GET requests by redirecting to main report page
        if ($request->isMethod('get')) {
            return redirect()->route('weekly-report');
        }
        
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);
        
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();
        
        return $this->generateReport($startDate, $endDate);
    }
    
    private function generateReport($startDate, $endDate)
    {
        // Get all events (regardless of date) for the summary
        $allEvents = Events::with(['locations', 'signUpForm'])->get();
        
        // Get events within the date range for the detailed report
        $events = Events::whereBetween('created_at', [$startDate, $endDate])
            ->orWhereHas('locations', function($query) use ($startDate, $endDate) {
                $query->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            })
            ->with(['locations', 'signUpForm'])
            ->get();
        
        $reportData = [];
        $totalSignups = 0;
        $totalEvents = $events->count();
        $totalLocations = 0;
        
        foreach ($events as $event) {
            $eventData = [
                'event_id' => $event->id,
                'event_name' => $event->event_name,
                'event_date' => $event->event_date,
                'created_at' => $event->created_at,
                'locations' => [],
                'total_signups' => 0,
                'has_signup_form' => $event->signUpForm ? true : false
            ];
            
            // Get locations for this event within the date range
            $eventLocations = $event->locations->filter(function($location) use ($startDate, $endDate) {
                // Skip locations with TBA dates or invalid dates
                if ($location->date === 'TBA' || $location->date === null || $location->date === '') {
                    return false;
                }
                
                try {
                    $locationDate = Carbon::parse($location->date);
                    return $locationDate->between($startDate, $endDate);
                } catch (\Exception $e) {
                    // Skip locations with invalid date formats
                    return false;
                }
            });
            
            foreach ($eventLocations as $location) {
                $locationSignups = 0;
                
                // Get signup data if signup form exists
                if ($event->signUpForm && $event->signUpForm->table_name) {
                    $tableName = $event->signUpForm->table_name;
                    $locationSignups = DB::table($tableName)
                        ->where('location_id', $location->id)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->count();
                }
                
                // Format date and time safely
                $formattedDate = 'TBA';
                $formattedTime = 'TBA';
                
                if ($location->date && $location->date !== 'TBA') {
                    try {
                        $formattedDate = Carbon::parse($location->date)->format('M d, Y');
                    } catch (\Exception $e) {
                        $formattedDate = $location->date;
                    }
                }
                
                if ($location->time && $location->time !== 'TBA') {
                    try {
                        $formattedTime = Carbon::parse($location->time)->format('g:i A');
                    } catch (\Exception $e) {
                        $formattedTime = $location->time;
                    }
                }
                
                $locationData = [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'date' => $location->date,
                    'time' => $location->time,
                    'signups' => $locationSignups,
                    'formatted_date' => $formattedDate,
                    'formatted_time' => $formattedTime
                ];
                
                $eventData['locations'][] = $locationData;
                $eventData['total_signups'] += $locationSignups;
                $totalSignups += $locationSignups;
            }
            
            $totalLocations += count($eventData['locations']);
            $reportData[] = $eventData;
        }
        
        // Sort events by creation date (newest first)
        usort($reportData, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Calculate date range info
        $dateRange = [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'start_formatted' => $startDate->format('M d, Y'),
            'end_formatted' => $endDate->format('M d, Y'),
            'is_current_week' => $this->isCurrentWeek($startDate, $endDate)
        ];
        
        // Get summary statistics for filtered events
        $summary = [
            'total_events' => $totalEvents,
            'total_locations' => $totalLocations,
            'total_signups' => $totalSignups,
            'average_signups_per_event' => $totalEvents > 0 ? round($totalSignups / $totalEvents, 2) : 0,
            'average_signups_per_location' => $totalLocations > 0 ? round($totalSignups / $totalLocations, 2) : 0
        ];
        
        // Generate all events summary (regardless of date filter)
        $allEventsSummary = [];
        $allEventsTotalSignups = 0;
        $allEventsTotalLocations = 0;
        
        foreach ($allEvents as $event) {
            $eventTotalSignups = 0;
            
            // Get locations for this event directly from the database
            $eventLocations = Location::where('event_id', $event->id)->get();
            
            // Get total signups for this event (all time, not filtered by date)
            if ($event->signUpForm && $event->signUpForm->table_name) {
                $tableName = $event->signUpForm->table_name;
                $eventTotalSignups = DB::table($tableName)
                    ->whereIn('location_id', $eventLocations->pluck('id'))
                    ->count();
            }
            
            // Determine event status based on location dates
            $eventStatus = 'No Locations';
            if ($eventLocations->count() > 0) {
                $today = Carbon::now()->startOfDay();
                
                // Get all location dates (excluding TBA)
                $locationDates = $eventLocations->filter(function($location) {
                    return $location->date && $location->date !== 'TBA';
                })->map(function($location) {
                    try {
                        return Carbon::parse($location->date);
                    } catch (\Exception $e) {
                        return null;
                    }
                })->filter();
                
                if ($locationDates->count() > 0) {
                    $earliestDate = $locationDates->min();
                    $latestDate = $locationDates->max();
                    
                    if ($today->lt($earliestDate)) {
                        $eventStatus = 'Upcoming';
                    } elseif ($today->gte($earliestDate) && $today->lte($latestDate)) {
                        $eventStatus = 'Ongoing';
                    } else {
                        $eventStatus = 'Completed';
                    }
                } else {
                    $eventStatus = 'TBA';
                }
            }
            
            $allEventsSummary[] = [
                'event_id' => $event->id,
                'event_name' => $event->event_name,
                'total_locations' => $eventLocations->count(),
                'total_signups' => $eventTotalSignups,
                'average_per_location' => $eventLocations->count() > 0 ? round($eventTotalSignups / $eventLocations->count(), 2) : 0,
                'status' => $eventStatus,
                'has_signup_form' => $event->signUpForm ? true : false
            ];
            
            $allEventsTotalSignups += $eventTotalSignups;
            $allEventsTotalLocations += $eventLocations->count();
        }
        
        return Inertia::render('WeeklyReport', [
            'reportData' => $reportData,
            'dateRange' => $dateRange,
            'summary' => $summary,
            'allEventsSummary' => $allEventsSummary,
            'allEventsTotals' => [
                'total_events' => $allEvents->count(),
                'total_locations' => $allEventsTotalLocations,
                'total_signups' => $allEventsTotalSignups,
                'average_per_event' => $allEvents->count() > 0 ? round($allEventsTotalSignups / $allEvents->count(), 2) : 0,
                'average_per_location' => $allEventsTotalLocations > 0 ? round($allEventsTotalSignups / $allEventsTotalLocations, 2) : 0
            ]
        ]);
    }
    
    private function isCurrentWeek($startDate, $endDate)
    {
        $currentWeekStart = Carbon::now()->startOfWeek();
        $currentWeekEnd = Carbon::now()->endOfWeek();
        
        return $startDate->format('Y-m-d') === $currentWeekStart->format('Y-m-d') && 
               $endDate->format('Y-m-d') === $currentWeekEnd->format('Y-m-d');
    }
    
    public function export(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);
        
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();
        
        // Generate the same data as the report
        $events = Events::whereBetween('created_at', [$startDate, $endDate])
            ->orWhereHas('locations', function($query) use ($startDate, $endDate) {
                $query->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            })
            ->with(['locations', 'signUpForm'])
            ->get();
        
        $csvData = [];
        $csvData[] = ['Event Name', 'Event Date', 'Location Name', 'Location Date', 'Location Time', 'Signups', 'Created At'];
        
        foreach ($events as $event) {
            $eventLocations = $event->locations->filter(function($location) use ($startDate, $endDate) {
                // Skip locations with TBA dates
                if ($location->date === 'TBA' || $location->date === null || $location->date === '') {
                    return false;
                }
                
                try {
                    $locationDate = Carbon::parse($location->date);
                    return $locationDate->between($startDate, $endDate);
                } catch (\Exception $e) {
                    return false;
                }
            });
            
            if ($eventLocations->count() === 0) {
                // Event with no locations in date range
                $csvData[] = [
                    $event->event_name,
                    $event->event_date ? Carbon::parse($event->event_date)->format('Y-m-d') : 'N/A',
                    'N/A',
                    'N/A',
                    'N/A',
                    0,
                    $event->created_at->format('Y-m-d H:i:s')
                ];
            } else {
                foreach ($eventLocations as $location) {
                    $locationSignups = 0;
                    
                    if ($event->signUpForm && $event->signUpForm->table_name) {
                        $tableName = $event->signUpForm->table_name;
                        $locationSignups = DB::table($tableName)
                            ->where('location_id', $location->id)
                            ->whereBetween('created_at', [$startDate, $endDate])
                            ->count();
                    }
                    
                    // Format location date and time safely for CSV
                    $locationDateFormatted = 'TBA';
                    $locationTimeFormatted = 'TBA';
                    
                    if ($location->date && $location->date !== 'TBA') {
                        try {
                            $locationDateFormatted = Carbon::parse($location->date)->format('Y-m-d');
                        } catch (\Exception $e) {
                            $locationDateFormatted = $location->date;
                        }
                    }
                    
                    if ($location->time && $location->time !== 'TBA') {
                        try {
                            $locationTimeFormatted = Carbon::parse($location->time)->format('H:i');
                        } catch (\Exception $e) {
                            $locationTimeFormatted = $location->time;
                        }
                    }
                    
                    $csvData[] = [
                        $event->event_name,
                        $event->event_date ? Carbon::parse($event->event_date)->format('Y-m-d') : 'N/A',
                        $location->name,
                        $locationDateFormatted,
                        $locationTimeFormatted,
                        $locationSignups,
                        $event->created_at->format('Y-m-d H:i:s')
                    ];
                }
            }
        }
        
        $filename = 'weekly_report_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.csv';
        
        $callback = function() use ($csvData) {
            $file = fopen('php://output', 'w');
            foreach ($csvData as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };
        
        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
    
    public function exportPdf(Request $request)
    {
        try {
            $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date'
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Export Validation Error: ' . $e->getMessage());
            return response()->json(['error' => 'Validation failed: ' . $e->getMessage()], 400);
        }
        
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();
        
        // Generate the same data as the report
        $events = Events::whereBetween('created_at', [$startDate, $endDate])
            ->orWhereHas('locations', function($query) use ($startDate, $endDate) {
                $query->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            })
            ->with(['locations', 'signUpForm'])
            ->get();
        
        $reportData = [];
        $totalSignups = 0;
        $totalEvents = $events->count();
        $totalLocations = 0;
        
        foreach ($events as $event) {
            $eventData = [
                'event_id' => $event->id,
                'event_name' => $event->event_name,
                'event_date' => $event->event_date,
                'created_at' => $event->created_at,
                'locations' => [],
                'total_signups' => 0,
                'has_signup_form' => $event->signUpForm ? true : false
            ];
            
            // Get locations for this event within the date range
            $eventLocations = $event->locations->filter(function($location) use ($startDate, $endDate) {
                if ($location->date === 'TBA' || $location->date === null || $location->date === '') {
                    return false;
                }
                
                try {
                    $locationDate = Carbon::parse($location->date);
                    return $locationDate->between($startDate, $endDate);
                } catch (\Exception $e) {
                    return false;
                }
            });
            
            foreach ($eventLocations as $location) {
                $locationSignups = 0;
                
                if ($event->signUpForm && $event->signUpForm->table_name) {
                    $tableName = $event->signUpForm->table_name;
                    $locationSignups = DB::table($tableName)
                        ->where('location_id', $location->id)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->count();
                }
                
                // Format date and time safely
                $formattedDate = 'TBA';
                $formattedTime = 'TBA';
                
                if ($location->date && $location->date !== 'TBA') {
                    try {
                        $formattedDate = Carbon::parse($location->date)->format('M d, Y');
                    } catch (\Exception $e) {
                        $formattedDate = $location->date;
                    }
                }
                
                if ($location->time && $location->time !== 'TBA') {
                    try {
                        $formattedTime = Carbon::parse($location->time)->format('g:i A');
                    } catch (\Exception $e) {
                        $formattedTime = $location->time;
                    }
                }
                
                $locationData = [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'date' => $location->date,
                    'time' => $location->time,
                    'signups' => $locationSignups,
                    'formatted_date' => $formattedDate,
                    'formatted_time' => $formattedTime
                ];
                
                $eventData['locations'][] = $locationData;
                $eventData['total_signups'] += $locationSignups;
                $totalSignups += $locationSignups;
            }
            
            $totalLocations += count($eventData['locations']);
            $reportData[] = $eventData;
        }
        
        // Get all events summary
        $allEvents = Events::with(['locations', 'signUpForm'])->get();
        $allEventsSummary = [];
        $allEventsTotalSignups = 0;
        $allEventsTotalLocations = 0;
        
        foreach ($allEvents as $event) {
            $eventTotalSignups = 0;
            $eventLocations = Location::where('event_id', $event->id)->get();
            
            if ($event->signUpForm && $event->signUpForm->table_name) {
                $tableName = $event->signUpForm->table_name;
                $eventTotalSignups = DB::table($tableName)
                    ->whereIn('location_id', $eventLocations->pluck('id'))
                    ->count();
            }
            
            // Determine event status
            $eventStatus = 'No Locations';
            if ($eventLocations->count() > 0) {
                $today = Carbon::now()->startOfDay();
                
                $locationDates = $eventLocations->filter(function($location) {
                    return $location->date && $location->date !== 'TBA';
                })->map(function($location) {
                    try {
                        return Carbon::parse($location->date);
                    } catch (\Exception $e) {
                        return null;
                    }
                })->filter();
                
                if ($locationDates->count() > 0) {
                    $earliestDate = $locationDates->min();
                    $latestDate = $locationDates->max();
                    
                    if ($today->lt($earliestDate)) {
                        $eventStatus = 'Upcoming';
                    } elseif ($today->gte($earliestDate) && $today->lte($latestDate)) {
                        $eventStatus = 'Ongoing';
                    } else {
                        $eventStatus = 'Completed';
                    }
                } else {
                    $eventStatus = 'TBA';
                }
            }
            
            $allEventsSummary[] = [
                'event_id' => $event->id,
                'event_name' => $event->event_name,
                'total_locations' => $eventLocations->count(),
                'total_signups' => $eventTotalSignups,
                'average_per_location' => $eventLocations->count() > 0 ? round($eventTotalSignups / $eventLocations->count(), 2) : 0,
                'status' => $eventStatus,
                'has_signup_form' => $event->signUpForm ? true : false
            ];
            
            $allEventsTotalSignups += $eventTotalSignups;
            $allEventsTotalLocations += $eventLocations->count();
        }
        
        $dateRange = [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'start_formatted' => $startDate->format('M d, Y'),
            'end_formatted' => $endDate->format('M d, Y')
        ];
        
        $summary = [
            'total_events' => $totalEvents,
            'total_locations' => $totalLocations,
            'total_signups' => $totalSignups,
            'average_signups_per_event' => $totalEvents > 0 ? round($totalSignups / $totalEvents, 2) : 0,
            'average_signups_per_location' => $totalLocations > 0 ? round($totalSignups / $totalLocations, 2) : 0
        ];
        
        $allEventsTotals = [
            'total_events' => $allEvents->count(),
            'total_locations' => $allEventsTotalLocations,
            'total_signups' => $allEventsTotalSignups,
            'average_per_event' => $allEvents->count() > 0 ? round($allEventsTotalSignups / $allEvents->count(), 2) : 0,
            'average_per_location' => $allEventsTotalLocations > 0 ? round($allEventsTotalSignups / $allEventsTotalLocations, 2) : 0
        ];
        
        try {
            $pdf = Pdf::loadView('reports.weekly-pdf', compact('reportData', 'dateRange', 'summary', 'allEventsSummary', 'allEventsTotals'));
            $pdf->setPaper('A4', 'portrait');
            
            $filename = 'weekly_report_' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.pdf';
            
            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('PDF Generation Error: ' . $e->getMessage());
            return response()->json(['error' => 'PDF generation failed: ' . $e->getMessage()], 500);
        }
    }
}
