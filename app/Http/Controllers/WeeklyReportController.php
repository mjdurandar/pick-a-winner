<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Models\Location;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * Build demographics table data (headers + rows) for copyable table or CSV export.
     * Returns ['headers' => [...], 'rows' => [[...], ...]].
     */
    private function buildDemographicsTableData(Request $request): array
    {
        $eventIds = $request->input('event_ids', []);
        if (!is_array($eventIds)) {
            $eventIds = array_filter([$eventIds]);
        }
        $eventIds = array_map('intval', array_filter($eventIds));

        $query = Events::with(['signUpForm', 'locations'])
            ->whereHas('signUpForm', fn ($q) => $q->whereNotNull('table_name'));

        if (count($eventIds) > 0) {
            $query->whereIn('id', $eventIds);
        }

        $events = $query->get();

        $startDate = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : null;
        $endDate = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : null;

        if ($startDate && $endDate && count($eventIds) === 0) {
            $events = $events->filter(function ($event) use ($startDate, $endDate) {
                $hasLocationInRange = $event->locations->contains(function ($loc) use ($startDate, $endDate) {
                    if (!$loc->date || $loc->date === 'TBA') {
                        return false;
                    }
                    try {
                        return Carbon::parse($loc->date)->between($startDate, $endDate);
                    } catch (\Exception $e) {
                        return false;
                    }
                });
                return $hasLocationInRange || Carbon::parse($event->created_at)->between($startDate, $endDate);
            });
        }

        $today = Carbon::today();
        $filterBreakdown = function (array $breakdown): array {
            return array_values(array_filter($breakdown, function ($q) {
                return $this->isDemographicsQuestionOnly($q['question_text'] ?? '', $q['column_name'] ?? '');
            }));
        };

        $questionColumns = [];
        $eventDataList = [];

        foreach ($events as $event) {
            $data = $this->buildEventBreakdownData($event);
            if (!$data) {
                continue;
            }
            $breakdown = $filterBreakdown($data['breakdown']);
            $status = $this->eventStatusForDemographics($event, $today);

            $eventDataList[] = [
                'event_name' => $event->event_name ?? '—',
                'status' => $status,
                'total_signups' => $data['total_signups'],
                'breakdown' => $breakdown,
            ];

            foreach ($breakdown as $q) {
                $questionText = $q['question_text'] ?? $q['column_name'] ?? null;
                if (!$questionText) {
                    continue;
                }
                $isSkiSpendQuestion = $this->isSkiEquipmentSpendQuestion($questionText, $q['column_name'] ?? '');
                foreach ($q['responses'] ?? [] as $r) {
                    $val = $r['value'] ?? '';
                    if ($val === '') {
                        continue;
                    }
                    if ($isSkiSpendQuestion && $this->isIncomeBracketValue($val)) {
                        continue;
                    }
                    if (!isset($questionColumns[$questionText])) {
                        $questionColumns[$questionText] = [];
                    }
                    if (!in_array($val, $questionColumns[$questionText], true)) {
                        $questionColumns[$questionText][] = $val;
                    }
                }
            }
        }

        $questionOrder = $this->orderDemographicsQuestionColumns(array_keys($questionColumns));
        $demographicHeaders = [];
        foreach ($questionOrder as $qText) {
            $values = $questionColumns[$qText] ?? [];
            sort($values);
            foreach ($values as $v) {
                $demographicHeaders[] = $v;
            }
        }

        $headers = array_merge(['Event', 'STATUS', 'Total signups'], $demographicHeaders);
        $rows = [];

        foreach ($eventDataList as $item) {
            $countByQuestionAndValue = [];
            foreach ($item['breakdown'] as $q) {
                $questionText = $q['question_text'] ?? $q['column_name'] ?? null;
                if (!$questionText) {
                    continue;
                }
                foreach ($q['responses'] ?? [] as $r) {
                    $val = $r['value'] ?? '';
                    $count = (int) ($r['count'] ?? 0);
                    if ($val !== '') {
                        $countByQuestionAndValue[$questionText][$val] = $count;
                    }
                }
            }

            $row = [$item['event_name'], $item['status'], (string) $item['total_signups']];
            foreach ($questionOrder as $qText) {
                $values = $questionColumns[$qText] ?? [];
                sort($values);
                foreach ($values as $v) {
                    $count = $countByQuestionAndValue[$qText][$v] ?? 0;
                    $row[] = $count > 0 ? (string) $count : 'N/A';
                }
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Get demographics table as JSON for copyable table (headers + rows). Same data as export; no file download.
     */
    public function getDemographicsTableData(Request $request)
    {
        $data = $this->buildDemographicsTableData($request);
        return response()->json($data);
    }

    /**
     * Export demographics spreadsheet: one row per event with Event, STATUS (current/past), Total signups,
     * then one column per demographic response. STATUS: "current" = ongoing shows; "past" = all shows finished.
     */
    public function exportDemographicsSpreadsheet(Request $request)
    {
        $data = $this->buildDemographicsTableData($request);
        $headers = $data['headers'];
        $rows = $data['rows'];

        $eventIds = $request->input('event_ids', []);
        if (!is_array($eventIds)) {
            $eventIds = array_filter([$eventIds]);
        }
        $startDate = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : null;
        $endDate = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : null;

        $filename = 'demographics_report_'
            . (count($eventIds) > 0 ? count($eventIds) . '_events_' : ($startDate && $endDate ? $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '_' : 'all_events_'))
            . date('Y-m-d') . '.csv';

        $callback = function () use ($headers, $rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, "\xEF\xBB\xBF");
            fputcsv($file, $headers);
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Whether this question is the "ski equipment spend" type (so we can exclude income-bracket values from it).
     */
    private function isSkiEquipmentSpendQuestion(string $questionText, string $columnName): bool
    {
        $t = mb_strtolower($questionText);
        $c = mb_strtolower($columnName);
        if (str_contains($t, 'spend') && (str_contains($t, 'ski') || str_contains($t, 'equipment'))) {
            return true;
        }
        if (str_contains($t, 'ski equipment') || str_contains($c, 'ski_spend') || (str_contains($c, 'spend') && str_contains($c, 'ski'))) {
            return true;
        }
        return false;
    }

    /**
     * Whether a response value looks like an income bracket (so we don't put it under "spend on ski equipment").
     */
    private function isIncomeBracketValue(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        if (preg_match('/\$?\s*66[,.]?000|\$?\s*99[,.]?000|\$?\s*100[,.]?000|\$?\s*150[,.]?000/i', $v)) {
            return true;
        }
        if (preg_match('/^[<>]\s*\$?\s*66/i', $v) || preg_match('/^>\s*\$?\s*150/i', $v)) {
            return true;
        }
        return false;
    }

    /**
     * Whether this question is one of the four demographics we export: Gender, Age, Combined Household Income, Ski Equipment Spending.
     */
    private function isDemographicsQuestionOnly(string $questionText, string $columnName): bool
    {
        $t = mb_strtolower($questionText);
        $c = mb_strtolower($columnName);
        if (str_contains($t, 'gender') || str_contains($c, 'gender')) {
            return true;
        }
        if (str_contains($t, 'age') || str_contains($c, 'age')) {
            return true;
        }
        if (str_contains($t, 'household income') || str_contains($t, 'combined income') || str_contains($c, 'household_income') || str_contains($c, 'combined_household')) {
            return true;
        }
        if (str_contains($t, 'spend') && (str_contains($t, 'ski') || str_contains($t, 'equipment'))) {
            return true;
        }
        if (str_contains($t, 'ski equipment') || str_contains($c, 'ski_spend') || (str_contains($c, 'spend') && str_contains($c, 'ski'))) {
            return true;
        }
        return false;
    }

    /**
     * Event status for demographics export: "current" if any show date is today or in the future; "past" if all shows are finished.
     */
    private function eventStatusForDemographics(Events $event, Carbon $today): string
    {
        $locations = $event->locations ?? collect();
        $hasOngoing = false;
        foreach ($locations as $loc) {
            $date = $loc->date ?? null;
            if ($date === null || $date === '' || $date === 'TBA') {
                continue;
            }
            try {
                $d = Carbon::parse($date)->startOfDay();
                if ($d->gte($today)) {
                    $hasOngoing = true;
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return $hasOngoing ? 'current' : 'past';
    }

    /**
     * Order question columns: Age, Gender, Where did you hear... first, then rest alphabetically.
     */
    private function orderDemographicsQuestionColumns(array $keys): array
    {
        $age = $gender = $hear = null;
        $rest = [];
        foreach ($keys as $k) {
            $lower = mb_strtolower($k);
            if (str_contains($lower, 'age') && $age === null) {
                $age = $k;
            } elseif (str_contains($lower, 'gender') && $gender === null) {
                $gender = $k;
            } elseif ((str_contains($lower, 'hear') || str_contains($lower, 'how did you') || str_contains($lower, 'where did you')) && $hear === null) {
                $hear = $k;
            } else {
                $rest[] = $k;
            }
        }
        sort($rest);
        return array_filter(array_merge([$age, $gender, $hear], $rest));
    }

    /**
     * Build breakdown data for one event (total signups + per-question responses). Returns null if no signup form.
     */
    private function buildEventBreakdownData(Events $event): ?array
    {
        if (!$event->signUpForm || !$event->signUpForm->table_name) {
            return null;
        }
        $tableName = $event->signUpForm->table_name;
        $questions = json_decode($event->signUpForm->questions, true) ?? [];

        $totalSignups = DB::table($tableName)->where('event_id', $event->id)->count();
        $breakdown = [];

        $detectPhoneCountry = function ($phoneNumber) {
            if (empty($phoneNumber)) {
                return 'Other';
            }
            $cleaned = preg_replace('/\D+/', '', $phoneNumber);
            if (empty($cleaned)) {
                return 'Other';
            }
            if (preg_match('/^(\+61|61)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+61|61)/', '', $cleaned);
                if (strlen($withoutCountry) >= 9 && strlen($withoutCountry) <= 10) {
                    return 'Australian';
                }
            }
            if (preg_match('/^(\+64|64)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+64|64)/', '', $cleaned);
                if (strlen($withoutCountry) >= 8 && strlen($withoutCountry) <= 9) {
                    return 'New Zealand';
                }
            }
            if (preg_match('/^(04|02|03|07|08)/', $cleaned) && strlen($cleaned) === 10) {
                return 'Australian';
            }
            if (preg_match('/^(02|03|04|06|07|09)/', $cleaned) && strlen($cleaned) >= 8 && strlen($cleaned) <= 9) {
                return 'New Zealand';
            }
            return 'Other';
        };

        foreach ($questions as $question) {
            $columnName = $question['column_name'] ?? null;
            if (!$columnName) {
                continue;
            }
            if ($question['type'] === 'email' || $columnName === 'email_address' ||
                $columnName === 'first_name' || $columnName === 'last_name' ||
                stripos($columnName, 'date_of_birth') !== false ||
                stripos($columnName, 'dateofbirth') !== false ||
                stripos($columnName, 'dob') !== false ||
                (strtolower($question['text'] ?? '') === 'date of birth') ||
                $question['type'] === 'text') {
                continue;
            }

            $questionBreakdown = [
                'question_text' => $question['text'] ?? $columnName,
                'column_name' => $columnName,
                'responses' => [],
            ];

            if ($columnName === 'mobile_number') {
                $allPhones = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)
                    ->where($columnName, '!=', '')
                    ->pluck($columnName);
                $phoneBreakdown = ['Australian' => 0, 'New Zealand' => 0, 'Other' => 0];
                foreach ($allPhones as $phone) {
                    $country = $detectPhoneCountry($phone);
                    $phoneBreakdown[$country]++;
                }
                foreach ($phoneBreakdown as $country => $count) {
                    if ($count > 0) {
                        $questionBreakdown['responses'][] = ['value' => $country, 'count' => $count];
                    }
                }
            } else {
                $responses = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)
                    ->where($columnName, '!=', '')
                    ->select($columnName, DB::raw('count(*) as count'))
                    ->groupBy($columnName)
                    ->orderByDesc('count')
                    ->get();
                $questionOptions = isset($question['options']) && is_array($question['options']) ? $question['options'] : [];
                $hasOtherOption = isset($question['hasOtherOption']) && $question['hasOtherOption'];

                if ($question['type'] === 'dropdown' && isset($question['allowMultiple']) && $question['allowMultiple']) {
                    $individualCounts = [];
                    $otherCount = 0;
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        $decoded = json_decode($value, true);
                        if (is_array($decoded) && count($decoded) > 0) {
                            foreach ($decoded as $item) {
                                $item = trim($item);
                                if (!empty($item)) {
                                    if (!empty($questionOptions) && !in_array($item, $questionOptions) && $item !== 'Other') {
                                        $otherCount += $count;
                                    } else {
                                        $individualCounts[$item] = ($individualCounts[$item] ?? 0) + $count;
                                    }
                                }
                            }
                        } elseif (!empty($value)) {
                            if (!empty($questionOptions)) {
                                $matchedOptions = [];
                                $remainingValue = $value;
                                $sortedOptions = $questionOptions;
                                usort($sortedOptions, fn ($a, $b) => strlen($b) - strlen($a));
                                foreach ($sortedOptions as $option) {
                                    if (strpos($remainingValue, $option) !== false) {
                                        $matchedOptions[] = $option;
                                        $remainingValue = str_replace($option, '', $remainingValue);
                                    }
                                }
                                foreach ($matchedOptions as $option) {
                                    $individualCounts[$option] = ($individualCounts[$option] ?? 0) + $count;
                                }
                                if (empty($matchedOptions)) {
                                    $otherCount += $count;
                                }
                            } else {
                                if ($hasOtherOption && $value !== 'Other' && !in_array($value, $questionOptions)) {
                                    $otherCount += $count;
                                } else {
                                    $individualCounts[$value] = ($individualCounts[$value] ?? 0) + $count;
                                }
                            }
                        }
                    }
                    if ($otherCount > 0) {
                        $individualCounts['Other'] = $otherCount;
                    }
                    arsort($individualCounts);
                    foreach ($individualCounts as $item => $count) {
                        $questionBreakdown['responses'][] = ['value' => $item, 'count' => $count];
                    }
                } else {
                    $otherCount = 0;
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        if ($hasOtherOption && !empty($questionOptions) && !in_array($value, $questionOptions) && $value !== 'Other') {
                            $otherCount += $count;
                        } else {
                            $questionBreakdown['responses'][] = ['value' => $value, 'count' => $count];
                        }
                    }
                    if ($otherCount > 0) {
                        $questionBreakdown['responses'][] = ['value' => 'Other', 'count' => $otherCount];
                    }
                }
            }

            usort($questionBreakdown['responses'], fn ($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));
            $breakdown[] = $questionBreakdown;
        }

        return ['total_signups' => $totalSignups, 'breakdown' => $breakdown];
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
    
    public function getEventBreakdown(Request $request)
    {
        $request->validate([
            'event_id' => 'required|exists:events,id'
        ]);
        
        $event = Events::with(['signUpForm', 'locations'])->findOrFail($request->event_id);
        
        if (!$event->signUpForm || !$event->signUpForm->table_name) {
            return response()->json([
                'error' => 'This event does not have a signup form'
            ], 404);
        }
        
        $tableName = $event->signUpForm->table_name;
        $questions = json_decode($event->signUpForm->questions, true) ?? [];
        
        // Get all signups for this event
        $allSignups = DB::table($tableName)
            ->where('event_id', $event->id)
            ->get();
        
        $totalSignups = $allSignups->count();
        $breakdown = [];
        
        // Helper function to detect phone number country
        $detectPhoneCountry = function($phoneNumber) {
            if (empty($phoneNumber)) return 'Other';
            
            $cleaned = preg_replace('/\D+/', '', $phoneNumber);
            
            if (empty($cleaned)) return 'Other';
            
            // Check for international format first (most reliable)
            // Australian: +61 or 61 followed by 9 digits
            if (preg_match('/^(\+61|61)/', $phoneNumber)) {
                // Remove country code to check remaining digits
                $withoutCountry = preg_replace('/^(\+61|61)/', '', $cleaned);
                if (strlen($withoutCountry) >= 9 && strlen($withoutCountry) <= 10) {
                    return 'Australian';
                }
            }
            
            // New Zealand: +64 or 64 followed by 8-9 digits
            if (preg_match('/^(\+64|64)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+64|64)/', '', $cleaned);
                if (strlen($withoutCountry) >= 8 && strlen($withoutCountry) <= 9) {
                    return 'New Zealand';
                }
            }
            
            // Australian domestic format: starts with 04 (mobile) or 02/03/07/08 (landline)
            // Must be 10 digits total
            if (preg_match('/^(04|02|03|07|08)/', $cleaned) && strlen($cleaned) === 10) {
                return 'Australian';
            }
            
            // New Zealand domestic format: starts with 02, 03, 04, 06, 07, 09
            // Must be 8-9 digits total
            if (preg_match('/^(02|03|04|06|07|09)/', $cleaned) && strlen($cleaned) >= 8 && strlen($cleaned) <= 9) {
                return 'New Zealand';
            }
            
            return 'Other';
        };
        
        // Process each question
        foreach ($questions as $question) {
            $columnName = $question['column_name'] ?? null;
            if (!$columnName) continue;
            
            // Skip email address questions
            if ($question['type'] === 'email' || $columnName === 'email_address') {
                continue;
            }
            
            // Skip first name and last name
            if ($columnName === 'first_name' || $columnName === 'last_name') {
                continue;
            }
            
            // Skip Date of Birth questions
            if (stripos($columnName, 'date_of_birth') !== false || 
                stripos($columnName, 'dateofbirth') !== false ||
                stripos($columnName, 'dob') !== false ||
                (strtolower($question['text'] ?? '') === 'date of birth')) {
                continue;
            }
            
            // Skip text input fields (but keep dropdown, number, date, textarea)
            if ($question['type'] === 'text') {
                continue;
            }
            
            $questionBreakdown = [
                'question_text' => $question['text'] ?? $columnName,
                'question_type' => $question['type'] ?? 'text',
                'column_name' => $columnName,
                'total_responses' => 0,
                'responses' => [],
                'chart_data' => null // For special chart handling
            ];
            
            // Special handling for mobile_number
            if ($columnName === 'mobile_number') {
                $allPhones = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)
                    ->where($columnName, '!=', '')
                    ->pluck($columnName);
                
                $phoneBreakdown = [
                    'Australian' => 0,
                    'New Zealand' => 0,
                    'Other' => 0
                ];
                
                foreach ($allPhones as $phone) {
                    $country = $detectPhoneCountry($phone);
                    $phoneBreakdown[$country]++;
                }
                
                $questionBreakdown['total_responses'] = array_sum($phoneBreakdown);
                $questionBreakdown['chart_data'] = [
                    'type' => 'phone',
                    'data' => $phoneBreakdown
                ];
                
                // Convert to responses format for consistency
                foreach ($phoneBreakdown as $country => $count) {
                    if ($count > 0) {
                        $questionBreakdown['responses'][] = [
                            'value' => $country,
                            'count' => $count,
                            'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                        ];
                    }
                }
            } else {
                // Get all unique values and their counts
                $responses = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)
                    ->where($columnName, '!=', '')
                    ->select($columnName, DB::raw('count(*) as count'))
                    ->groupBy($columnName)
                    ->orderByDesc('count')
                    ->get();
                
                $questionBreakdown['total_responses'] = $responses->sum('count');
                
                // Get the question options and check if it has "Other" option
                $questionOptions = isset($question['options']) && is_array($question['options']) ? $question['options'] : [];
                $hasOtherOption = isset($question['hasOtherOption']) && $question['hasOtherOption'];
                
                // Format responses based on question type
                // For multi-select dropdowns, count individual selections instead of combinations
                if ($question['type'] === 'dropdown' && isset($question['allowMultiple']) && $question['allowMultiple']) {
                    // Count individual selections across all combinations
                    $individualCounts = [];
                    $otherCount = 0; // Aggregate count for "Other" responses
                    
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        
                        // If stored as JSON array, decode it
                        $decoded = json_decode($value, true);
                        if (is_array($decoded) && count($decoded) > 0) {
                            // Count each individual selection from the array
                            foreach ($decoded as $item) {
                                $item = trim($item);
                                if (!empty($item)) {
                                    // Check if this item is a predefined option or "Other"
                                    if (!empty($questionOptions) && !in_array($item, $questionOptions) && $item !== 'Other') {
                                        // This is a custom "Other" response - aggregate it
                                        $otherCount += $count;
                                    } else {
                                        // It's a predefined option
                                        if (!isset($individualCounts[$item])) {
                                            $individualCounts[$item] = 0;
                                        }
                                        $individualCounts[$item] += $count;
                                    }
                                }
                            }
                        } elseif (!empty($value)) {
                            // Handle string representation
                            // First, try to match against question options (most reliable)
                            if (!empty($questionOptions)) {
                                $matchedOptions = [];
                                $remainingValue = $value;
                                
                                // Sort options by length (longest first) to match "Snow Sports (Skiing, Snowboarding, Snowshoeing)" 
                                // before matching just "Snow Sports"
                                $sortedOptions = $questionOptions;
                                usort($sortedOptions, function($a, $b) {
                                    return strlen($b) - strlen($a);
                                });
                                
                                foreach ($sortedOptions as $option) {
                                    // Check if this option appears in the value
                                    if (strpos($remainingValue, $option) !== false) {
                                        $matchedOptions[] = $option;
                                        // Remove matched option from remaining value to avoid double matching
                                        $remainingValue = str_replace($option, '', $remainingValue);
                                    }
                                }
                                
                                // Count matched options
                                foreach ($matchedOptions as $option) {
                                    if (!isset($individualCounts[$option])) {
                                        $individualCounts[$option] = 0;
                                    }
                                    $individualCounts[$option] += $count;
                                }
                                
                                // If no options matched, it's an "Other" response
                                if (empty($matchedOptions)) {
                                    $otherCount += $count;
                                }
                            } else {
                                // Fallback: if no question options available, treat as single value
                                // But if hasOtherOption is true, check if it's not "Other" exactly
                                if ($hasOtherOption && $value !== 'Other' && !in_array($value, $questionOptions)) {
                                    $otherCount += $count;
                                } else {
                                    if (!isset($individualCounts[$value])) {
                                        $individualCounts[$value] = 0;
                                    }
                                    $individualCounts[$value] += $count;
                                }
                            }
                        }
                    }
                    
                    // Add "Other" count if there are any "Other" responses
                    if ($otherCount > 0) {
                        $individualCounts['Other'] = $otherCount;
                    }
                    
                    // Convert individual counts to response format, sorted by count descending
                    $sortedCounts = $individualCounts;
                    arsort($sortedCounts);
                    
                    foreach ($sortedCounts as $item => $count) {
                        $questionBreakdown['responses'][] = [
                            'value' => $item,
                            'count' => $count,
                            'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                        ];
                    }
                } else {
                    // For single-select or other types
                    $otherCount = 0;
                    
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        
                        // Check if this is an "Other" response (not in predefined options)
                        if ($hasOtherOption && !empty($questionOptions) && !in_array($value, $questionOptions) && $value !== 'Other') {
                            // This is a custom "Other" response - aggregate it
                            $otherCount += $count;
                        } else {
                            // It's a predefined option or "Other" exactly
                            $questionBreakdown['responses'][] = [
                                'value' => $value,
                                'count' => $count,
                                'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                            ];
                        }
                    }
                    
                    // Add aggregated "Other" count if there are any "Other" responses
                    if ($otherCount > 0) {
                        $questionBreakdown['responses'][] = [
                            'value' => 'Other',
                            'count' => $otherCount,
                            'percentage' => $totalSignups > 0 ? round(($otherCount / $totalSignups) * 100, 2) : 0
                        ];
                    }
                }
                
                // Add chart data for all dropdown questions
                if ($question['type'] === 'dropdown' && count($questionBreakdown['responses']) > 0) {
                    $chartData = [];
                    foreach ($questionBreakdown['responses'] as $resp) {
                        // Only include responses with valid data
                        if (!empty($resp['value']) && $resp['count'] > 0) {
                            $chartData[$resp['value']] = $resp['count'];
                        }
                    }
                    
                    // Only add chart if we have data
                    if (!empty($chartData)) {
                        // Use pie chart for country, bar chart for everything else
                        $chartType = ($columnName === 'country') ? 'pie' : 'bar';
                        $questionBreakdown['chart_data'] = [
                            'type' => $chartType,
                            'data' => $chartData
                        ];
                    }
                }
            }
            
            // Sort responses by count descending
            usort($questionBreakdown['responses'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            $breakdown[] = $questionBreakdown;
        }
        
        // Get location breakdown
        $locationBreakdown = [];
        foreach ($event->locations as $location) {
            $locationSignups = DB::table($tableName)
                ->where('event_id', $event->id)
                ->where('location_id', $location->id)
                ->count();
            
            $locationBreakdown[] = [
                'location_id' => $location->id,
                'location_name' => $location->name,
                'signups' => $locationSignups,
                'percentage' => $totalSignups > 0 ? round(($locationSignups / $totalSignups) * 100, 2) : 0
            ];
        }
        
        // Sort locations by signups descending
        usort($locationBreakdown, function($a, $b) {
            return $b['signups'] - $a['signups'];
        });
        
        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->event_name,
                'total_signups' => $totalSignups
            ],
            'breakdown' => $breakdown,
            'location_breakdown' => $locationBreakdown
        ]);
    }
    
    public function exportEventBreakdownPdf(Request $request)
    {
        try {
            $request->validate([
                'event_id' => 'required|exists:events,id'
            ]);
        } catch (\Exception $e) {
            Log::error('Event Breakdown PDF Export Validation Error: ' . $e->getMessage());
            return response()->json(['error' => 'Validation failed: ' . $e->getMessage()], 400);
        }
        
        // Reuse the same logic as getEventBreakdown to get the data
        $event = Events::with(['signUpForm', 'locations'])->findOrFail($request->event_id);
        
        if (!$event->signUpForm || !$event->signUpForm->table_name) {
            return response()->json([
                'error' => 'This event does not have a signup form'
            ], 404);
        }
        
        $tableName = $event->signUpForm->table_name;
        $questions = json_decode($event->signUpForm->questions, true) ?? [];
        
        // Get all signups for this event
        $allSignups = DB::table($tableName)
            ->where('event_id', $event->id)
            ->get();
        
        $totalSignups = $allSignups->count();
        $breakdown = [];
        
        // Helper function to detect phone number country (same as getEventBreakdown)
        $detectPhoneCountry = function($phoneNumber) {
            if (empty($phoneNumber)) return 'Other';
            $cleaned = preg_replace('/\D+/', '', $phoneNumber);
            if (empty($cleaned)) return 'Other';
            if (preg_match('/^(\+61|61)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+61|61)/', '', $cleaned);
                if (strlen($withoutCountry) >= 9 && strlen($withoutCountry) <= 10) {
                    return 'Australian';
                }
            }
            if (preg_match('/^(\+64|64)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+64|64)/', '', $cleaned);
                if (strlen($withoutCountry) >= 8 && strlen($withoutCountry) <= 9) {
                    return 'New Zealand';
                }
            }
            if (preg_match('/^(04|02|03|07|08)/', $cleaned) && strlen($cleaned) === 10) {
                return 'Australian';
            }
            if (preg_match('/^(02|03|04|06|07|09)/', $cleaned) && strlen($cleaned) >= 8 && strlen($cleaned) <= 9) {
                return 'New Zealand';
            }
            return 'Other';
        };
        
        // Process each question (same logic as getEventBreakdown)
        foreach ($questions as $question) {
            $columnName = $question['column_name'] ?? null;
            if (!$columnName) continue;
            
            // Skip email, first name, last name, date of birth, text fields
            if ($question['type'] === 'email' || $columnName === 'email_address' ||
                $columnName === 'first_name' || $columnName === 'last_name' ||
                stripos($columnName, 'date_of_birth') !== false || 
                stripos($columnName, 'dateofbirth') !== false ||
                stripos($columnName, 'dob') !== false ||
                (strtolower($question['text'] ?? '') === 'date of birth') ||
                $question['type'] === 'text') {
                continue;
            }
            
            $questionBreakdown = [
                'question_text' => $question['text'] ?? $columnName,
                'question_type' => $question['type'] ?? 'text',
                'column_name' => $columnName,
                'total_responses' => 0,
                'responses' => []
            ];
            
            // Handle mobile_number
            if ($columnName === 'mobile_number') {
                $allPhones = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)
                    ->where($columnName, '!=', '')
                    ->pluck($columnName);
                
                $phoneBreakdown = ['Australian' => 0, 'New Zealand' => 0, 'Other' => 0];
                foreach ($allPhones as $phone) {
                    $country = $detectPhoneCountry($phone);
                    $phoneBreakdown[$country]++;
                }
                
                $questionBreakdown['total_responses'] = array_sum($phoneBreakdown);
                foreach ($phoneBreakdown as $country => $count) {
                    if ($count > 0) {
                        $questionBreakdown['responses'][] = [
                            'value' => $country,
                            'count' => $count,
                            'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                        ];
                    }
                }
            } else {
                // Get question options
                $questionOptions = isset($question['options']) && is_array($question['options']) ? $question['options'] : [];
                $hasOtherOption = isset($question['hasOtherOption']) && $question['hasOtherOption'];
                
                // Get responses
                $responses = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)
                    ->where($columnName, '!=', '')
                    ->select($columnName, DB::raw('count(*) as count'))
                    ->groupBy($columnName)
                    ->orderByDesc('count')
                    ->get();
                
                $questionBreakdown['total_responses'] = $responses->sum('count');
                
                // Handle multi-select dropdowns
                if ($question['type'] === 'dropdown' && isset($question['allowMultiple']) && $question['allowMultiple']) {
                    $individualCounts = [];
                    $otherCount = 0;
                    
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        
                        $decoded = json_decode($value, true);
                        if (is_array($decoded) && count($decoded) > 0) {
                            foreach ($decoded as $item) {
                                $item = trim($item);
                                if (!empty($item)) {
                                    if (!empty($questionOptions) && !in_array($item, $questionOptions) && $item !== 'Other') {
                                        $otherCount += $count;
                                    } else {
                                        if (!isset($individualCounts[$item])) {
                                            $individualCounts[$item] = 0;
                                        }
                                        $individualCounts[$item] += $count;
                                    }
                                }
                            }
                        } elseif (!empty($value)) {
                            if (!empty($questionOptions)) {
                                $matchedOptions = [];
                                $remainingValue = $value;
                                $sortedOptions = $questionOptions;
                                usort($sortedOptions, function($a, $b) {
                                    return strlen($b) - strlen($a);
                                });
                                
                                foreach ($sortedOptions as $option) {
                                    if (strpos($remainingValue, $option) !== false) {
                                        $matchedOptions[] = $option;
                                        $remainingValue = str_replace($option, '', $remainingValue);
                                    }
                                }
                                
                                foreach ($matchedOptions as $option) {
                                    if (!isset($individualCounts[$option])) {
                                        $individualCounts[$option] = 0;
                                    }
                                    $individualCounts[$option] += $count;
                                }
                                
                                if (empty($matchedOptions)) {
                                    $otherCount += $count;
                                }
                            } else {
                                if ($hasOtherOption && $value !== 'Other' && !in_array($value, $questionOptions)) {
                                    $otherCount += $count;
                                } else {
                                    if (!isset($individualCounts[$value])) {
                                        $individualCounts[$value] = 0;
                                    }
                                    $individualCounts[$value] += $count;
                                }
                            }
                        }
                    }
                    
                    if ($otherCount > 0) {
                        $individualCounts['Other'] = $otherCount;
                    }
                    
                    $sortedCounts = $individualCounts;
                    arsort($sortedCounts);
                    
                    foreach ($sortedCounts as $item => $count) {
                        $questionBreakdown['responses'][] = [
                            'value' => $item,
                            'count' => $count,
                            'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                        ];
                    }
                } else {
                    // Single-select
                    $otherCount = 0;
                    
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        
                        if ($hasOtherOption && !empty($questionOptions) && !in_array($value, $questionOptions) && $value !== 'Other') {
                            $otherCount += $count;
                        } else {
                            $questionBreakdown['responses'][] = [
                                'value' => $value,
                                'count' => $count,
                                'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                            ];
                        }
                    }
                    
                    if ($otherCount > 0) {
                        $questionBreakdown['responses'][] = [
                            'value' => 'Other',
                            'count' => $otherCount,
                            'percentage' => $totalSignups > 0 ? round(($otherCount / $totalSignups) * 100, 2) : 0
                        ];
                    }
                }
                
                // Add chart data for dropdowns
                if ($question['type'] === 'dropdown' && count($questionBreakdown['responses']) > 0) {
                    $chartData = [];
                    foreach ($questionBreakdown['responses'] as $resp) {
                        if (!empty($resp['value']) && $resp['count'] > 0) {
                            $chartData[$resp['value']] = $resp['count'];
                        }
                    }
                    
                    if (!empty($chartData)) {
                        $chartType = ($columnName === 'country') ? 'pie' : 'bar';
                        $questionBreakdown['chart_data'] = [
                            'type' => $chartType,
                            'data' => $chartData
                        ];
                    }
                }
            }
            
            usort($questionBreakdown['responses'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            $breakdown[] = $questionBreakdown;
        }
        
        // Get location breakdown
        $locationBreakdown = [];
        foreach ($event->locations as $location) {
            $locationSignups = DB::table($tableName)
                ->where('event_id', $event->id)
                ->where('location_id', $location->id)
                ->count();
            
            $locationBreakdown[] = [
                'location_id' => $location->id,
                'location_name' => $location->name,
                'signups' => $locationSignups,
                'percentage' => $totalSignups > 0 ? round(($locationSignups / $totalSignups) * 100, 2) : 0
            ];
        }
        
        usort($locationBreakdown, function($a, $b) {
            return $b['signups'] - $a['signups'];
        });
        
        try {
            $pdf = Pdf::loadView('reports.event-breakdown-pdf', compact('event', 'totalSignups', 'breakdown', 'locationBreakdown'));
            $pdf->setPaper('A4', 'portrait');
            
            $filename = 'event_breakdown_' . Str::slug($event->event_name) . '_' . date('Y-m-d') . '.pdf';
            
            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('Event Breakdown PDF Generation Error: ' . $e->getMessage());
            return response()->json(['error' => 'PDF generation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Export End of Film Tour report as PDF for the selected event.
     * Includes signups, tickets, demographics (by country/state), location breakdown, and question breakdown.
     */
    public function exportEndOfFilmTourPdf(Request $request)
    {
        try {
            $request->validate([
                'event_id' => 'required|exists:events,id',
                'last_film_signups' => 'nullable|integer|min:0',
                'last_film_year' => 'nullable|integer|min:1990|max:2100',
            ]);
        } catch (\Exception $e) {
            Log::error('End of Film Tour PDF Export Validation Error: ' . $e->getMessage());
            return response()->json(['error' => 'Validation failed: ' . $e->getMessage()], 400);
        }

        $event = Events::with(['signUpForm', 'locations', 'film'])->findOrFail($request->event_id);
        $lastFilmSignups = $request->filled('last_film_signups') ? (int) $request->last_film_signups : null;
        $lastFilmYear = $request->filled('last_film_year') ? (int) $request->last_film_year : null;

        if (!$event->signUpForm || !$event->signUpForm->table_name) {
            return response()->json([
                'error' => 'This event does not have a signup form'
            ], 404);
        }

        $tableName = $event->signUpForm->table_name;
        $questions = json_decode($event->signUpForm->questions, true) ?? [];
        $allSignups = DB::table($tableName)->where('event_id', $event->id)->get();
        $totalSignups = $allSignups->count();
        $breakdown = [];
        $detectPhoneCountry = function ($phoneNumber) {
            if (empty($phoneNumber)) return 'Other';
            $cleaned = preg_replace('/\D+/', '', $phoneNumber);
            if (empty($cleaned)) return 'Other';
            if (preg_match('/^(\+61|61)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+61|61)/', '', $cleaned);
                if (strlen($withoutCountry) >= 9 && strlen($withoutCountry) <= 10) return 'Australian';
            }
            if (preg_match('/^(\+64|64)/', $phoneNumber)) {
                $withoutCountry = preg_replace('/^(\+64|64)/', '', $cleaned);
                if (strlen($withoutCountry) >= 8 && strlen($withoutCountry) <= 9) return 'New Zealand';
            }
            if (preg_match('/^(04|02|03|07|08)/', $cleaned) && strlen($cleaned) === 10) return 'Australian';
            if (preg_match('/^(02|03|04|06|07|09)/', $cleaned) && strlen($cleaned) >= 8 && strlen($cleaned) <= 9) return 'New Zealand';
            return 'Other';
        };

        foreach ($questions as $question) {
            $columnName = $question['column_name'] ?? null;
            if (!$columnName) continue;
            if ($question['type'] === 'email' || $columnName === 'email_address' ||
                $columnName === 'first_name' || $columnName === 'last_name' ||
                stripos($columnName, 'date_of_birth') !== false ||
                stripos($columnName, 'dateofbirth') !== false ||
                stripos($columnName, 'dob') !== false ||
                (strtolower($question['text'] ?? '') === 'date of birth') ||
                $question['type'] === 'text') {
                continue;
            }
            $questionBreakdown = [
                'question_text' => $question['text'] ?? $columnName,
                'question_type' => $question['type'] ?? 'text',
                'column_name' => $columnName,
                'total_responses' => 0,
                'responses' => []
            ];
            if ($columnName === 'mobile_number') {
                $allPhones = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)->where($columnName, '!=', '')
                    ->pluck($columnName);
                $phoneBreakdown = ['Australian' => 0, 'New Zealand' => 0, 'Other' => 0];
                foreach ($allPhones as $phone) {
                    $country = $detectPhoneCountry($phone);
                    $phoneBreakdown[$country]++;
                }
                $questionBreakdown['total_responses'] = array_sum($phoneBreakdown);
                foreach ($phoneBreakdown as $country => $count) {
                    if ($count > 0) {
                        $questionBreakdown['responses'][] = [
                            'value' => $country,
                            'count' => $count,
                            'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                        ];
                    }
                }
            } else {
                $questionOptions = isset($question['options']) && is_array($question['options']) ? $question['options'] : [];
                $hasOtherOption = isset($question['hasOtherOption']) && $question['hasOtherOption'];
                $responses = DB::table($tableName)
                    ->where('event_id', $event->id)
                    ->whereNotNull($columnName)->where($columnName, '!=', '')
                    ->select($columnName, DB::raw('count(*) as count'))
                    ->groupBy($columnName)->orderByDesc('count')->get();
                $questionBreakdown['total_responses'] = $responses->sum('count');
                if ($question['type'] === 'dropdown' && isset($question['allowMultiple']) && $question['allowMultiple']) {
                    $individualCounts = [];
                    $otherCount = 0;
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        $decoded = json_decode($value, true);
                        if (is_array($decoded) && count($decoded) > 0) {
                            foreach ($decoded as $item) {
                                $item = trim($item);
                                if (!empty($item)) {
                                    if (!empty($questionOptions) && !in_array($item, $questionOptions) && $item !== 'Other') {
                                        $otherCount += $count;
                                    } else {
                                        $individualCounts[$item] = ($individualCounts[$item] ?? 0) + $count;
                                    }
                                }
                            }
                        } elseif (!empty($value)) {
                            if (!empty($questionOptions)) {
                                $matchedOptions = [];
                                $remainingValue = $value;
                                $sortedOptions = $questionOptions;
                                usort($sortedOptions, fn ($a, $b) => strlen($b) - strlen($a));
                                foreach ($sortedOptions as $option) {
                                    if (strpos($remainingValue, $option) !== false) {
                                        $matchedOptions[] = $option;
                                        $remainingValue = str_replace($option, '', $remainingValue);
                                    }
                                }
                                foreach ($matchedOptions as $option) {
                                    $individualCounts[$option] = ($individualCounts[$option] ?? 0) + $count;
                                }
                                if (empty($matchedOptions)) $otherCount += $count;
                            } else {
                                if ($hasOtherOption && $value !== 'Other' && !in_array($value, $questionOptions)) {
                                    $otherCount += $count;
                                } else {
                                    $individualCounts[$value] = ($individualCounts[$value] ?? 0) + $count;
                                }
                            }
                        }
                    }
                    if ($otherCount > 0) $individualCounts['Other'] = $otherCount;
                    arsort($individualCounts);
                    foreach ($individualCounts as $item => $count) {
                        $questionBreakdown['responses'][] = [
                            'value' => $item,
                            'count' => $count,
                            'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                        ];
                    }
                } else {
                    $otherCount = 0;
                    foreach ($responses as $response) {
                        $value = $response->{$columnName};
                        $count = $response->count;
                        if ($hasOtherOption && !empty($questionOptions) && !in_array($value, $questionOptions) && $value !== 'Other') {
                            $otherCount += $count;
                        } else {
                            $questionBreakdown['responses'][] = [
                                'value' => $value,
                                'count' => $count,
                                'percentage' => $totalSignups > 0 ? round(($count / $totalSignups) * 100, 2) : 0
                            ];
                        }
                    }
                    if ($otherCount > 0) {
                        $questionBreakdown['responses'][] = [
                            'value' => 'Other',
                            'count' => $otherCount,
                            'percentage' => $totalSignups > 0 ? round(($otherCount / $totalSignups) * 100, 2) : 0
                        ];
                    }
                }
            }
            usort($questionBreakdown['responses'], fn ($a, $b) => $b['count'] - $a['count']);
            $breakdown[] = $questionBreakdown;
        }

        $locationIds = $event->locations->pluck('id');
        $locationBreakdown = [];
        foreach ($event->locations as $location) {
            $locationSignups = DB::table($tableName)
                ->where('event_id', $event->id)
                ->where('location_id', $location->id)
                ->count();
            $locationBreakdown[] = [
                'location_id' => $location->id,
                'location_name' => $location->name,
                'signups' => $locationSignups,
                'percentage' => $totalSignups > 0 ? round(($locationSignups / $totalSignups) * 100, 2) : 0
            ];
        }
        usort($locationBreakdown, fn ($a, $b) => $b['signups'] - $a['signups']);

        // Ticket attendees (counts only; no demographics from tickets)
        $ticketAttendees = TicketAttendee::whereIn('location_id', $locationIds)
            ->with(['location'])
            ->get();
        $totalTickets = $ticketAttendees->unique(fn ($a) => strtolower(trim($a->email ?? '')))->count();

        foreach ($locationBreakdown as &$row) {
            $row['tickets'] = $ticketAttendees->where('location_id', $row['location_id'])->unique(fn ($a) => strtolower(trim($a->email ?? '')))->count();
        }
        unset($row);

        $film = $event->film;

        try {
            $pdf = Pdf::loadView('reports.end-of-film-tour-pdf', compact('event', 'film', 'totalSignups', 'totalTickets', 'breakdown', 'locationBreakdown', 'lastFilmSignups', 'lastFilmYear'));
            $pdf->setPaper('A4', 'portrait');
            $filename = 'end_of_film_tour_' . Str::slug($event->event_name) . '_' . date('Y-m-d') . '.pdf';
            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('End of Film Tour PDF Generation Error: ' . $e->getMessage());
            return response()->json(['error' => 'PDF generation failed: ' . $e->getMessage()], 500);
        }
    }
}
