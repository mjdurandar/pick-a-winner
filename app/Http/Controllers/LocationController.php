<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\Films;
use App\Models\Location;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use App\Services\AutoMailchimpService;
use App\Services\MailchimpService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LocationController extends Controller
{
    protected $mailchimpService;

    protected $autoMailchimpService;

    public function __construct(MailchimpService $mailchimpService, AutoMailchimpService $autoMailchimpService)
    {
        $this->mailchimpService = $mailchimpService;
        $this->autoMailchimpService = $autoMailchimpService;
    }

    public function index()
    {
        $events = Events::latest()->get();

        return Inertia::render('Locations', [
            'events' => $events,
        ]);
    }

    public function locationpage($eventId)
    {
        $event = Events::findOrFail($eventId);
        $locations = Location::where('event_id', $eventId)
            ->orderBy('date')
            ->orderBy('time')
            ->get();

        $locationIds = $locations->pluck('id');

        // Participant count per location (from sign-up / win form table)
        $participantsByLocation = [];
        $signUpForm = SignUpForm::where('event_id', $eventId)->first();
        if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
            foreach ($locationIds as $lid) {
                $participantsByLocation[$lid] = (int) DB::table($signUpForm->table_name)
                    ->where('location_id', $lid)
                    ->where('event_id', $eventId)
                    ->count();
            }
        } else {
            foreach ($locationIds as $lid) {
                $participantsByLocation[$lid] = 0;
            }
        }
        $totalParticipants = array_sum($participantsByLocation);

        // Add participants_count to each location
        $locationsWithParticipants = $locations->map(function ($location) use ($participantsByLocation) {
            $location->participants_count = $participantsByLocation[$location->id] ?? 0;

            return $location;
        });

        $faveSportOptions = [];
        if ($signUpForm) {
            $questions = is_string($signUpForm->questions)
                ? json_decode($signUpForm->questions, true)
                : ($signUpForm->questions ?? []);
            if (is_array($questions)) {
                foreach ($questions as $q) {
                    if (($q['column_name'] ?? null) === 'fave_sport') {
                        $opts = $q['options'] ?? [];
                        if (is_array($opts)) {
                            $faveSportOptions = array_values(array_filter(array_map(
                                fn ($o) => is_string($o) ? trim($o) : '',
                                $opts
                            ), fn ($o) => $o !== ''));
                        }
                        break;
                    }
                }
            }
        }

        return Inertia::render('LocationPage', [
            'event' => $event,
            'locations' => $locationsWithParticipants,
            'total_participants' => $totalParticipants,
            'fave_sport_options' => $faveSportOptions,
        ]);
    }

    public function updatePassword(Request $request, Location $location)
    {
        $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $location->update([
            'password' => $request->password,
        ]);

        return back()->with('success', 'Password updated successfully');
    }

    /**
     * Validation rule for a location's date. A screening is often booked before its
     * date is locked in, so the literal 'TBA' is as valid as a calendar date - the
     * column is a varchar precisely so it can hold one. Anything else must be a real
     * YYYY-MM-DD date, which is what the form's date input sends.
     */
    private function locationDateRule(): array
    {
        return ['required', 'string', function ($attribute, $value, $fail) {
            if ($value === 'TBA') {
                return;
            }

            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail('The date must be a valid date, or TBA if it is not set yet.');
            }
        }];
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'event_id' => 'required|exists:events,id',
            'date' => $this->locationDateRule(),
            'time' => 'required',
            'country' => 'required|string|in:Australia,New Zealand,Canada,USA',
            'category' => 'required|string|in:Theatrical,AE Tour Stop,Host a Show',
            'state' => 'nullable|string|max:3',
        ]);

        // Check if there are multiple locations with the same password (3-5 locations)
        $existingLocations = Location::where('event_id', $request->event_id)->get();

        // Group locations by password and count them
        $passwordCounts = $existingLocations->groupBy('password')->map(function ($group) {
            return $group->count();
        });

        // Find the most common password that appears 3 or more times
        $commonPassword = null;
        $maxCount = 0;
        foreach ($passwordCounts as $password => $count) {
            if ($count >= 3 && $count > $maxCount) {
                $commonPassword = $password;
                $maxCount = $count;
            }
        }

        // Use the common password if found, otherwise generate a random one
        $password = $commonPassword ?: Str::random(10);

        $location = Location::create([
            'name' => $request->name,
            'event_id' => $request->event_id,
            'date' => $request->date,
            'time' => $request->time,
            'country' => $request->country,
            'category' => $request->category,
            'state' => $request->state ? strtoupper(trim($request->state)) : null,
            'password' => $password,
        ]);

        return back()->with('success', 'Location created successfully');
    }

    public function update(Request $request, Location $location)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'date' => $this->locationDateRule(),
            'time' => 'required',
            'country' => 'required|string|in:Australia,New Zealand,Canada,USA',
            'category' => 'required|string|in:Theatrical,AE Tour Stop,Host a Show',
            'state' => 'nullable|string|max:3',
        ]);

        $location->update([
            'name' => $request->name,
            'date' => $request->date,
            'time' => $request->time,
            'country' => $request->country,
            'category' => $request->category,
            'state' => $request->state ? strtoupper(trim($request->state)) : null,
        ]);

        return back()->with('success', 'Location updated successfully');
    }

    public function destroy(Location $location)
    {
        $location->delete();

        return back()->with('success', 'Location deleted successfully');
    }

    /**
     * How much attendee data each of these locations would take with it. Only
     * locations that actually hold entries come back, each with the counts the
     * confirmation dialog shows, so an admin deleting a screening sees the size of
     * what they are about to lose before it happens.
     *
     * @param  array<int>  $locationIds
     * @return array<int, array{id: int, name: string, date: ?string, signups: int, tickets: int}>
     */
    protected function locationsWithAttendeeData($eventId, array $locationIds): array
    {
        if (empty($locationIds)) {
            return [];
        }

        $signUpForm = SignUpForm::where('event_id', $eventId)->first();
        $formTable = $signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)
            ? $signUpForm->table_name
            : null;

        $signupCounts = $formTable
            ? DB::table($formTable)
                ->select('location_id', DB::raw('COUNT(*) as total'))
                ->whereIn('location_id', $locationIds)
                ->groupBy('location_id')
                ->pluck('total', 'location_id')
            : collect();

        $ticketCounts = TicketAttendee::select('location_id', DB::raw('COUNT(*) as total'))
            ->whereIn('location_id', $locationIds)
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        return Location::whereIn('id', $locationIds)
            ->where('event_id', $eventId)
            ->orderBy('date')
            ->get()
            ->map(fn ($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'date' => $location->date,
                'signups' => (int) ($signupCounts[$location->id] ?? 0),
                'tickets' => (int) ($ticketCounts[$location->id] ?? 0),
            ])
            ->filter(fn ($row) => $row['signups'] > 0 || $row['tickets'] > 0)
            ->values()
            ->all();
    }

    public function updateAllPasswords(Request $request, $eventId)
    {
        $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $locations = Location::where('event_id', $eventId)->get();

        foreach ($locations as $location) {
            $location->update([
                'password' => $request->password,
            ]);
        }

        return back()->with('success', 'All location passwords updated successfully');
    }

    public function getMailchimpLists(Request $request)
    {
        $account = $request->input('account', 'anz');
        if (! in_array($account, ['anz', 'usa'])) {
            $account = 'anz';
        }
        try {
            $mailchimpService = new MailchimpService($account);
            $lists = $mailchimpService->getLists();

            return response()->json(['lists' => $lists]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getMailchimpMergeFields(Request $request)
    {
        try {
            $request->validate([
                'list_id' => 'required|string',
                'account' => 'nullable|string|in:anz,usa',
            ]);
            $account = $request->input('account', 'anz');
            $mailchimpService = new MailchimpService($account);
            $mergeFields = $mailchimpService->getListMergeFields($request->list_id);

            // Check for your actual available fields based on screenshots
            $availableFields = [
                'FNAME', 'LNAME', 'CITY', 'SHOWCITY', 'STATE', 'ZIPCODE', 'COUNTRY',
                'MMERGE11', 'GENDER', 'MMERGE18', 'MMERGE10', 'MMERGE12', 'MMERGE13', 'MMERGE14',
                'PHONE', // We no longer import the SMS phone number field
            ];
            $existingTags = array_column($mergeFields, 'tag');

            // No missing ideal fields anymore since you added phone fields
            $missingIdealFields = [];

            // Fields that don't exist in your audience
            $missingFields = array_diff($availableFields, $existingTags);

            // Build merge fields with validation metadata for mapping UI
            $mergeFieldsWithValidation = array_map(function ($f) {
                $validation = [
                    'type' => $f['type'] ?? 'text',
                    'required' => ! empty($f['required'] ?? false),
                ];
                if (in_array($f['type'] ?? '', ['dropdown', 'radio']) && ! empty($f['options']['choices'])) {
                    $choices = $f['options']['choices'];
                    $validation['choices'] = array_map(fn ($c) => is_string($c) ? $c : ($c['value'] ?? (string) $c), $choices);
                }
                if (! empty($f['help_text'])) {
                    $validation['help_text'] = $f['help_text'];
                }

                return [
                    'merge_id' => $f['merge_id'] ?? null,
                    'tag' => $f['tag'],
                    'name' => $f['name'] ?? $f['tag'],
                    'type' => $f['type'] ?? 'text',
                    'required' => ! empty($f['required'] ?? false),
                    'validation' => $validation,
                ];
            }, $mergeFields);

            return response()->json([
                'merge_fields' => $mergeFields,
                'merge_fields_with_validation' => $mergeFieldsWithValidation,
                'missing_required_fields' => $missingFields,
                'missing_ideal_fields' => $missingIdealFields,
                'field_mapping' => [
                    'FNAME' => 'First Name (Available ✅)',
                    'LNAME' => 'Last Name (Available ✅)',
                    'EMAIL' => 'Email Address (Built-in ✅)',
                    'MMERGE10' => 'Street Address (Available ✅)',
                    'MMERGE11' => 'Address (Available ✅)',
                    'CITY' => 'City (Available ✅)',
                    'SHOWCITY' => 'Show City (Available ✅)',
                    'STATE' => 'State (Available ✅)',
                    'ZIPCODE' => 'Zip Code (Available ✅)',
                    'COUNTRY' => 'Country (Available ✅)',
                    'GENDER' => 'Gender (Available ✅)',
                    'MMERGE18' => 'Household Income (Available ✅)',
                    'MMERGE12' => 'Overseas Sports Frequency (Available ✅)',
                    'MMERGE13' => 'Equipment Spending (Available ✅)',
                    'MMERGE14' => 'Age (Available ✅)',
                    'PHONE' => 'Phone Number (Available ✅)',
                ],
                'data_to_import' => [
                    'first_name' => 'Nathan',
                    'last_name' => 'Maxwell',
                    'email_address' => 'natedogts@gmail.com',
                    'street_address' => '131 Wairakei Ave',
                    'city' => 'Papamoa',
                    'state' => 'Bay of Plenty',
                    'zip_code' => '3118',
                    'country' => 'New Zealand',
                    'mobile_number' => '02 240 5267',
                    'age' => '22-44',
                    'gender' => 'Male',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getSubscribers(Request $request)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
        ]);
        Log::info('getSubscribers called', ['data' => $request->all()]);
        try {
            $location = Location::findOrFail($request->location_id);
            Log::info('location found', ['location' => $location]);
            $signupForm = DB::table('sign_up_forms')
                ->where('event_id', $location->event_id)
                ->first();

            if (! $signupForm) {
                Log::info('no signup form found', ['location' => $location]);

                return response()->json(['error' => 'No signup form found for this event'], 404);
            }

            $subscribers = DB::table($signupForm->table_name)
                ->where('location_id', $location->id)
                ->get();

            Log::info('Found subscribers for location', [
                'location_id' => $location->id,
                'count' => $subscribers->count(),
                'table' => $signupForm->table_name,
            ]);

            // Process all subscribers in a single batch
            if ($subscribers->count() > 0) {
                $this->autoMailchimpService->syncSubscribers($subscribers->all(), $location->id);
            }

            return response()->json([
                'total' => $subscribers->count(),
                'subscribers' => $subscribers,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching subscribers: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch subscribers'], 500);
        }
    }

    public function deleteAllLocations($eventId)
    {
        try {
            $locations = Location::where('event_id', $eventId)->get();
            if ($locations->isEmpty()) {
                return back()->with('error', 'No locations found for this event');
            }

            Location::where('event_id', $eventId)->delete();

            return back()->with('success', 'All locations deleted successfully');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete locations: '.$e->getMessage());
        }
    }

    /**
     * Get ticket attendees for a location in Mailchimp subscriber format.
     */
    public function getTicketAttendees($locationId)
    {
        $location = Location::findOrFail($locationId);
        $attendees = TicketAttendee::where('location_id', $locationId)->get();

        $subscribers = $attendees->map(function ($a) {
            return [
                'email_address' => $a->email,
                'first_name' => $a->first_name ?? '',
                'last_name' => $a->last_name ?? '',
                'mobile_number' => $a->phone ?? '',
                'city' => $a->city ?? '',
                'state' => $a->state ?? '',
                'country' => $a->country ?? '',
            ];
        })->values()->all();

        return response()->json([
            'total' => count($subscribers),
            'subscribers' => $subscribers,
        ]);
    }

    /**
     * Get locations for sheets modal (formatted for display)
     */
    public function getSheetsData($eventId)
    {
        try {
            $locations = Location::where('event_id', $eventId)
                ->orderBy('id', 'asc')
                ->get();

            $sheetsData = $locations->map(function ($location) {
                // Get state directly from database column
                $state = $location->state ?? '';

                // Parse the name field: "Location State (if any) - Cinema"
                $name = $location->name ?? '';
                $locationName = '';
                $cinema = '';

                // Parse location name and cinema from name field
                // Check if name contains " - " (separator for cinema)
                if (strpos($name, ' - ') !== false) {
                    $parts = explode(' - ', $name, 2);
                    $locationPart = trim($parts[0]);
                    $cinema = trim($parts[1] ?? '');

                    // Remove state from location part if it's there (for backward compatibility)
                    if (! empty($state)) {
                        $locationPart = preg_replace('/\s+'.preg_quote($state, '/').'$/i', '', $locationPart);
                    }
                    $locationName = trim($locationPart);
                } else {
                    // No cinema, remove state from name if it's there
                    if (! empty($state)) {
                        $locationName = preg_replace('/\s+'.preg_quote($state, '/').'$/i', '', $name);
                        $locationName = trim($locationName);
                    } else {
                        $locationName = $name;
                    }
                }

                // Format date: "Tuesday, 19 August 2025"
                $formattedDate = '';
                if (! empty($location->date) && $location->date !== 'TBA') {
                    try {
                        $date = Carbon::parse($location->date);
                        $formattedDate = $date->format('l, j F Y'); // e.g., "Tuesday, 19 August 2025"
                    } catch (\Exception $e) {
                        $formattedDate = $location->date; // Fallback to original if parsing fails
                    }
                } else {
                    $formattedDate = $location->date ?? '';
                }

                // Format time: "7:00 pm"
                $formattedTime = '';
                if (! empty($location->time) && $location->time !== 'TBA') {
                    try {
                        // Handle both HH:MM:SS and HH:MM formats
                        $timeParts = explode(':', $location->time);
                        $hours = (int) ($timeParts[0] ?? 0);
                        $minutes = (int) ($timeParts[1] ?? 0);

                        // Determine period and display hours
                        if ($hours == 0) {
                            // Midnight (00:00) -> 12:00 am
                            $displayHours = 12;
                            $period = 'am';
                        } elseif ($hours == 12) {
                            // Noon (12:00) -> 12:00 pm
                            $displayHours = 12;
                            $period = 'pm';
                        } elseif ($hours > 12) {
                            // Afternoon/evening (13-23) -> 1-11 pm
                            $displayHours = $hours - 12;
                            $period = 'pm';
                        } else {
                            // Morning (1-11) -> 1-11 am
                            $displayHours = $hours;
                            $period = 'am';
                        }

                        $formattedTime = sprintf('%d:%02d %s', $displayHours, $minutes, $period);
                    } catch (\Exception $e) {
                        $formattedTime = $location->time; // Fallback to original if parsing fails
                    }
                } else {
                    $formattedTime = $location->time ?? '';
                }

                return [
                    'id' => $location->id,
                    'Location' => $locationName,
                    'Cinema' => $cinema,
                    'State' => $state,
                    'Country' => $location->country ?? '',
                    'Date' => $formattedDate,
                    'Time' => $formattedTime,
                    'Category' => $location->category ?? '',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $sheetsData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load locations: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse time string to 24-hour format (HH:MM) like "20:00", "19:00", "18:30"
     * Handles formats like:
     * - "7:00PM" or "7:00 PM" → "19:00"
     * - "7pm" or "7PM" → "19:00"
     * - "7:00pm" or "7:00 pm" → "19:00"
     * - "7:00AM" or "7:00 AM" → "07:00"
     * - "7am" or "7AM" → "07:00"
     * - "19:00" (already in 24-hour format) → "19:00"
     * - "7:00" (assumes PM if no AM/PM specified and hour < 12)
     */
    private function parseTime($timeString)
    {
        if (empty($timeString)) {
            return null;
        }

        $timeString = trim($timeString);

        // Same as the date: 'TBA' is a real value, not a parse failure.
        if (strcasecmp($timeString, 'TBA') === 0) {
            return 'TBA';
        }

        // Check if already in 24-hour format "HH:MM" or "H:MM"
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeString, $matches)) {
            $hour24 = (int) $matches[1];
            $minutes = (int) $matches[2];

            // Validate
            if ($hour24 < 0 || $hour24 > 23 || $minutes < 0 || $minutes > 59) {
                Log::warning('Invalid 24-hour time format', ['time' => $timeString]);

                return null;
            }

            // Return in HH:MM format
            return sprintf('%02d:%02d', $hour24, $minutes);
        }

        // Handle formats like "7pm", "7PM", "7:00pm", "7:00 pm", "7:00PM", "7:00 PM"
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', $timeString, $matches)) {
            $hour = (int) $matches[1];
            $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
            $ampm = strtoupper($matches[3]);

            // Validate hour
            if ($hour < 1 || $hour > 12) {
                Log::warning('Invalid hour in time string', ['time' => $timeString, 'hour' => $hour]);

                return null;
            }

            // Validate minutes
            if ($minutes < 0 || $minutes > 59) {
                Log::warning('Invalid minutes in time string', ['time' => $timeString, 'minutes' => $minutes]);

                return null;
            }

            // Convert to 24-hour format
            $hour24 = $hour;
            if ($ampm === 'PM' && $hour != 12) {
                $hour24 = $hour + 12;
            } elseif ($ampm === 'AM' && $hour == 12) {
                $hour24 = 0;
            }

            // Return in HH:MM format
            return sprintf('%02d:%02d', $hour24, $minutes);
        }

        // If no format matches, try to parse with Carbon as fallback
        try {
            // Try to parse as time
            $time = Carbon::createFromTimeString($timeString);

            // Return in 24-hour format HH:MM
            return $time->format('H:i');
        } catch (\Exception $e) {
            Log::warning('Unable to parse time string', [
                'time_string' => $timeString,
                'error' => $e->getMessage(),
            ]);

            // Return original if we can't parse it
            return $timeString;
        }
    }

    /**
     * Parse date string to YYYY-MM-DD format
     * Handles formats like:
     * - "Friday, January 23, 2026"
     * - "Sunday, 31 May 2026"
     * - "Saturday, January 24, 2026"
     * - "Wednesday, 27 May 2026"
     * - "January 24, 2026"
     * - "27 May 2026"
     * - "2026-01-24" (already in correct format)
     */
    private function parseDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        $dateString = trim($dateString);

        // A sheet can say the date is not set yet - keep that as the same 'TBA'
        // marker the location form writes, rather than failing to parse it.
        if (strcasecmp($dateString, 'TBA') === 0) {
            return 'TBA';
        }

        // Check if already in YYYY-MM-DD format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return $dateString;
        }

        // Remove day name if present (e.g., "Friday, " or "Sunday, ")
        $cleanedDate = preg_replace('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*/i', '', $dateString);

        // Try manual parsing first for specific formats

        // Format 1: "31 May 2026" or "27 May 2026" (UK/Australian - day month year)
        if (preg_match('/^(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})$/i', $cleanedDate, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $monthName = ucfirst(strtolower($matches[2]));
            $year = $matches[3];

            // Convert month name to number
            $monthNum = date('m', strtotime($monthName.' 1'));
            if ($monthNum === false) {
                Log::error('Failed to convert month name', ['month' => $monthName]);

                return null;
            }

            return "$year-$monthNum-$day";
        }

        // Format 2: "January 23, 2026" or "January 24, 2026" (US - month day, year)
        if (preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2}),?\s+(\d{4})$/i', $cleanedDate, $matches)) {
            $monthName = ucfirst(strtolower($matches[1]));
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            // Convert month name to number
            $monthNum = date('m', strtotime($monthName.' 1'));
            if ($monthNum === false) {
                Log::error('Failed to convert month name', ['month' => $monthName]);

                return null;
            }

            return "$year-$monthNum-$day";
        }

        // Try Carbon as fallback for other formats
        try {
            $date = Carbon::parse($cleanedDate);

            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            // If Carbon can't parse it, log and return null
            Log::error('Unable to parse date string', [
                'original_date' => $dateString,
                'cleaned_date' => $cleanedDate,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Save locations from sheets modal
     */
    public function saveSheetsData(Request $request)
    {
        try {
            $request->validate([
                'event_id' => 'required|exists:events,id',
                'data' => 'required|array',
                'data.*.Location' => 'required|string',
                'data.*.Cinema' => 'nullable|string',
                'data.*.State' => 'nullable|string',
                'data.*.Country' => 'nullable|string',
                'data.*.Date' => 'nullable|string',
                'data.*.Time' => 'nullable|string',
                'data.*.Category' => 'nullable|string',
                'ids_to_delete' => 'nullable|array',
                'ids_to_delete.*' => 'integer|exists:locations,id',
                'confirm_deletes' => 'nullable|boolean',
            ]);

            $eventId = $request->event_id;
            $rows = $request->data;
            $created = 0;
            $updated = 0;
            $deleted = 0;

            $idsToDelete = array_values(array_filter(
                (array) $request->input('ids_to_delete', []),
                fn ($id) => Location::where('id', $id)->where('event_id', $eventId)->exists()
            ));

            // Removing a location takes its attendees with it — ticket_attendees
            // cascades on delete and the sign-up entries lose their location. That is
            // the intended outcome, but never a silent one: the first save comes back
            // asking, and nothing at all is written until the answer is yes. Checking
            // before any create or update keeps the save atomic, so a cancelled
            // confirmation leaves the sheet exactly as it was.
            if (! $request->boolean('confirm_deletes') && ! empty($idsToDelete)) {
                $withData = $this->locationsWithAttendeeData($eventId, $idsToDelete);

                if (! empty($withData)) {
                    return response()->json([
                        'success' => false,
                        'needs_confirmation' => true,
                        'locations_with_data' => $withData,
                    ]);
                }
            }

            // Get existing locations for password logic
            $existingLocations = Location::where('event_id', $eventId)->get();
            $passwordCounts = $existingLocations->groupBy('password')->map(function ($group) {
                return $group->count();
            });
            $commonPassword = null;
            $maxCount = 0;
            foreach ($passwordCounts as $password => $count) {
                if ($count >= 3 && $count > $maxCount) {
                    $commonPassword = $password;
                    $maxCount = $count;
                }
            }

            foreach ($rows as $row) {
                // Skip empty rows (all fields empty)
                if (empty($row['Location']) && empty($row['Cinema']) && empty($row['State'])) {
                    continue;
                }

                // Build the name: "Location State (if any) - Cinema"
                $locationName = trim($row['Location'] ?? '');
                $state = trim($row['State'] ?? '');
                $cinema = trim($row['Cinema'] ?? '');

                $name = $locationName;
                if (! empty($state)) {
                    $name .= ' '.strtoupper($state);
                }
                if (! empty($cinema)) {
                    $name .= ' - '.$cinema;
                }

                // Parse and convert date to YYYY-MM-DD format
                $parsedDate = $this->parseDate($row['Date'] ?? null);

                // Parse and convert time to standardized format
                $parsedTime = $this->parseTime($row['Time'] ?? null);

                // Check if this is an update (has id) or create (no id)
                if (! empty($row['id'])) {
                    // Update existing location
                    $location = Location::find($row['id']);
                    if ($location && $location->event_id == $eventId) {
                        $location->update([
                            'name' => $name,
                            'date' => $parsedDate,
                            'time' => $parsedTime,
                            'state' => ! empty($state) ? $state : null,
                            'country' => $row['Country'] ?? null,
                            'category' => $row['Category'] ?? null,
                        ]);
                        $updated++;
                    }
                } else {
                    // Create new location
                    $password = $commonPassword ?: Str::random(10);
                    Location::create([
                        'name' => $name,
                        'event_id' => $eventId,
                        'date' => $parsedDate,
                        'time' => $parsedTime,
                        'state' => ! empty($state) ? $state : null,
                        'country' => $row['Country'] ?? null,
                        'category' => $row['Category'] ?? null,
                        'password' => $password,
                    ]);
                    $created++;
                }
            }

            // Locations taken off the master sheet are deleted outright — the sheet is
            // the source of truth for what the sign-up form offers, so anything not on
            // it must not survive anywhere. Any attendee data this costs was named in
            // the confirmation above.
            $deletedNames = [];
            foreach ($idsToDelete as $locationId) {
                $location = Location::find($locationId);
                if ($location && $location->event_id == $eventId) {
                    $deletedNames[] = $location->name;
                    $location->delete();
                    $deleted++;
                }
            }

            if (! empty($deletedNames)) {
                Log::warning('Master sheet deleted locations', [
                    'event_id' => $eventId,
                    'locations' => $deletedNames,
                ]);
            }

            $message = "Successfully saved. Created: {$created}, Updated: {$updated}";
            if ($deleted > 0) {
                $message .= ", Deleted: {$deleted} (".implode(', ', $deletedNames).')';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save sheets data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to save locations: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ticket report for a specific location
     * Compares ticket emails with sign-up form emails to find duplicates
     */
    public function getLocationTicketReport($locationId)
    {
        try {
            $location = Location::with('event')->findOrFail($locationId);
            $event = $location->event;

            // Get ticket attendees (from Eventbrite)
            $ticketAttendees = TicketAttendee::where('location_id', $locationId)->get();
            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique()->values();

            // Get sign-up form attendees (from system)
            $signUpForm = SignUpForm::where('event_id', $event->id)->first();
            $signUpEmails = collect();

            if ($signUpForm && $signUpForm->table_name) {
                $tableName = $signUpForm->table_name;
                // Check if table exists
                if (Schema::hasTable($tableName)) {
                    $signUpAttendees = DB::table($tableName)
                        ->where('location_id', $locationId)
                        ->where('event_id', $event->id)
                        ->get();

                    $signUpEmails = $signUpAttendees->pluck('email_address')
                        ->map(function ($email) {
                            return strtolower(trim($email));
                        })
                        ->filter()
                        ->unique()
                        ->values();
                }
            }

            // Find duplicates (emails that appear in both ticket and sign-up data)
            $duplicateEmails = $ticketEmails->intersect($signUpEmails)->values();

            // Emails only in tickets
            $ticketOnlyEmails = $ticketEmails->diff($signUpEmails)->values();

            // Emails only in sign-up forms
            $signUpOnlyEmails = $signUpEmails->diff($ticketEmails)->values();

            // Get detailed duplicate information
            $duplicateDetails = $duplicateEmails->map(function ($email) use ($ticketAttendees, $signUpForm, $locationId, $event) {
                // Find ticket data (case-insensitive match)
                $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                    return strtolower(trim($attendee->email)) === strtolower(trim($email));
                });

                $signUpData = null;

                if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                    $signUpData = DB::table($signUpForm->table_name)
                        ->where('location_id', $locationId)
                        ->where('event_id', $event->id)
                        ->whereRaw('LOWER(TRIM(email_address)) = ?', [strtolower(trim($email))])
                        ->first();
                }

                return [
                    'email' => $email,
                    'ticket_data' => $ticketData ? [
                        'first_name' => $ticketData->first_name,
                        'last_name' => $ticketData->last_name,
                        'phone' => $ticketData->phone,
                        'source' => 'Eventbrite Ticket',
                    ] : null,
                    'signup_data' => $signUpData ? [
                        'first_name' => $signUpData->first_name ?? null,
                        'last_name' => $signUpData->last_name ?? null,
                        'phone' => $signUpData->mobile_number ?? null,
                        'source' => 'Sign-Up Form',
                    ] : null,
                ];
            });

            return response()->json([
                'location' => $location,
                'summary' => [
                    'ticket_emails_count' => $ticketEmails->count(),
                    'signup_emails_count' => $signUpEmails->count(),
                    'duplicate_emails_count' => $duplicateEmails->count(),
                    'ticket_only_count' => $ticketOnlyEmails->count(),
                    'signup_only_count' => $signUpOnlyEmails->count(),
                    'total_unique_emails' => $ticketEmails->merge($signUpEmails)->unique()->count(),
                ],
                'duplicate_emails' => $duplicateDetails,
                'ticket_only_emails' => $ticketOnlyEmails,
                'signup_only_emails' => $signUpOnlyEmails,
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting location ticket report', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to get ticket report: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ticket report for all locations of a film
     * Compares ticket emails with sign-up form emails across all locations
     */
    public function getFilmTicketReport($filmId)
    {
        try {
            $film = Films::findOrFail($filmId);

            // Get all events for this film
            $events = Events::where('film_id', $filmId)->with('signUpForm')->get();
            $eventIds = $events->pluck('id');

            // Get all locations for these events
            $locations = Location::whereIn('event_id', $eventIds)->get();
            $locationIds = $locations->pluck('id');

            // Get all ticket attendees for these locations
            $ticketAttendees = TicketAttendee::whereIn('location_id', $locationIds)
                ->with(['location', 'event'])
                ->get();

            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique()->values();

            // Get all sign-up form emails across all events
            $allSignUpEmails = collect();
            $signUpAttendeesByLocation = [];

            foreach ($events as $event) {
                $signUpForm = $event->signUpForm;
                if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                    $tableName = $signUpForm->table_name;
                    $eventLocations = $locations->where('event_id', $event->id);

                    foreach ($eventLocations as $location) {
                        $signUpAttendees = DB::table($tableName)
                            ->where('location_id', $location->id)
                            ->where('event_id', $event->id)
                            ->get();

                        $locationSignUpEmails = $signUpAttendees->pluck('email_address')
                            ->map(function ($email) {
                                return strtolower(trim($email));
                            })
                            ->filter()
                            ->unique()
                            ->values();

                        $allSignUpEmails = $allSignUpEmails->merge($locationSignUpEmails);
                        $signUpAttendeesByLocation[$location->id] = $signUpAttendees;
                    }
                }
            }

            $signUpEmails = $allSignUpEmails->unique()->values();

            // Find duplicates (emails in both ticket and sign-up data)
            $duplicateEmails = $ticketEmails->intersect($signUpEmails)->values();

            // Emails only in tickets
            $ticketOnlyEmails = $ticketEmails->diff($signUpEmails)->values();

            // Emails only in sign-up forms
            $signUpOnlyEmails = $signUpEmails->diff($ticketEmails)->values();

            // Get detailed duplicate information
            $duplicateDetails = $duplicateEmails->map(function ($email) use ($ticketAttendees, $events, $signUpAttendeesByLocation, $locations) {
                // Find ticket data (case-insensitive match)
                $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                    return strtolower(trim($attendee->email)) === strtolower(trim($email));
                });

                $signUpDataList = [];

                // Find all sign-up data for this email across all locations
                foreach ($events as $event) {
                    $signUpForm = $event->signUpForm;
                    if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                        $eventLocations = $locations->where('event_id', $event->id);

                        foreach ($eventLocations as $location) {
                            if (isset($signUpAttendeesByLocation[$location->id])) {
                                $signUpData = $signUpAttendeesByLocation[$location->id]
                                    ->first(function ($attendee) use ($email) {
                                        return strtolower(trim($attendee->email_address ?? '')) === strtolower(trim($email));
                                    });

                                if ($signUpData) {
                                    $signUpDataList[] = [
                                        'first_name' => $signUpData->first_name ?? null,
                                        'last_name' => $signUpData->last_name ?? null,
                                        'phone' => $signUpData->mobile_number ?? null,
                                        'location_name' => $location->name,
                                        'event_name' => $event->event_name,
                                        'source' => 'Sign-Up Form',
                                    ];
                                }
                            }
                        }
                    }
                }

                return [
                    'email' => $email,
                    'ticket_data' => $ticketData ? [
                        'first_name' => $ticketData->first_name,
                        'last_name' => $ticketData->last_name,
                        'phone' => $ticketData->phone,
                        'location_name' => $ticketData->location->name ?? 'N/A',
                        'event_name' => $ticketData->event->event_name ?? 'N/A',
                        'source' => 'Eventbrite Ticket',
                    ] : null,
                    'signup_data' => $signUpDataList,
                ];
            });

            // Per location statistics
            $locationStats = $locations->map(function ($location) use ($ticketAttendees, $events, $signUpAttendeesByLocation) {
                $locationTicketAttendees = $ticketAttendees->where('location_id', $location->id);
                $locationTicketEmails = $locationTicketAttendees->pluck('email')
                    ->map(function ($email) {
                        return strtolower(trim($email));
                    })
                    ->filter()
                    ->unique()
                    ->values();

                $locationSignUpEmails = collect();
                $event = $events->where('id', $location->event_id)->first();

                if ($event && $event->signUpForm && $event->signUpForm->table_name && Schema::hasTable($event->signUpForm->table_name)) {
                    if (isset($signUpAttendeesByLocation[$location->id])) {
                        $locationSignUpEmails = $signUpAttendeesByLocation[$location->id]
                            ->pluck('email_address')
                            ->map(function ($email) {
                                return strtolower(trim($email));
                            })
                            ->filter()
                            ->unique()
                            ->values();
                    }
                }

                $locationDuplicates = $locationTicketEmails->intersect($locationSignUpEmails)->count();

                return [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'event_name' => $location->event->event_name ?? 'N/A',
                    'ticket_emails_count' => $locationTicketEmails->count(),
                    'signup_emails_count' => $locationSignUpEmails->count(),
                    'duplicate_emails_count' => $locationDuplicates,
                    'ticket_only_count' => $locationTicketEmails->diff($locationSignUpEmails)->count(),
                    'signup_only_count' => $locationSignUpEmails->diff($locationTicketEmails)->count(),
                ];
            });

            // Demographics: by country (from ticket attendees; unique by email)
            $attendeesByEmail = $ticketAttendees->unique(function ($a) {
                return strtolower(trim($a->email ?? ''));
            });
            $byCountry = $attendeesByEmail->groupBy(function ($a) {
                $c = trim($a->country ?? '');

                return $c !== '' ? $c : 'N/A';
            })->map(function ($group) {
                return $group->count();
            })->sortDesc()->map(function ($count, $country) {
                return ['country' => $country, 'count' => $count];
            })->values();

            // By state (within country) for drill-down
            $byState = $attendeesByEmail->filter(fn ($a) => trim($a->country ?? '') !== '' && trim($a->state ?? '') !== '')
                ->groupBy(function ($a) {
                    return trim($a->country ?? '').'|'.trim($a->state ?? '');
                })->map(function ($group) {
                    $first = $group->first();

                    return [
                        'country' => trim($first->country ?? ''),
                        'state' => trim($first->state ?? ''),
                        'count' => $group->count(),
                    ];
                })->sortByDesc('count')->values();

            return response()->json([
                'film' => $film,
                'summary' => [
                    'ticket_emails_count' => $ticketEmails->count(),
                    'signup_emails_count' => $signUpEmails->count(),
                    'duplicate_emails_count' => $duplicateEmails->count(),
                    'ticket_only_count' => $ticketOnlyEmails->count(),
                    'signup_only_count' => $signUpOnlyEmails->count(),
                    'total_unique_emails' => $ticketEmails->merge($signUpEmails)->unique()->count(),
                    'total_locations' => $locations->count(),
                    'total_events' => $events->count(),
                ],
                'duplicate_emails' => $duplicateDetails,
                'ticket_only_emails' => $ticketOnlyEmails,
                'signup_only_emails' => $signUpOnlyEmails,
                'location_stats' => $locationStats,
                'events' => $events,
                'demographics' => [
                    'by_country' => $byCountry,
                    'by_state' => $byState,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting film ticket report', [
                'film_id' => $filmId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to get film ticket report: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ticket report for a single event (all locations of that event)
     * Compares ticket emails with sign-up form emails
     */
    public function getEventTicketReport($eventId)
    {
        try {
            $event = Events::with('signUpForm')->findOrFail($eventId);

            // Get all locations for this event
            $locations = Location::where('event_id', $eventId)->get();
            $locationIds = $locations->pluck('id');

            // Get all ticket attendees for these locations
            $ticketAttendees = TicketAttendee::whereIn('location_id', $locationIds)
                ->with(['location', 'event'])
                ->get();

            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique()->values();

            // Get all sign-up form emails for this event
            $signUpEmails = collect();
            $signUpAttendeesByLocation = [];

            $signUpForm = $event->signUpForm;
            if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                $tableName = $signUpForm->table_name;

                foreach ($locations as $location) {
                    $signUpAttendees = DB::table($tableName)
                        ->where('location_id', $location->id)
                        ->where('event_id', $event->id)
                        ->get();

                    $locationSignUpEmails = $signUpAttendees->pluck('email_address')
                        ->map(function ($email) {
                            return strtolower(trim($email));
                        })
                        ->filter()
                        ->unique()
                        ->values();

                    $signUpEmails = $signUpEmails->merge($locationSignUpEmails);
                    $signUpAttendeesByLocation[$location->id] = $signUpAttendees;
                }
            }

            $signUpEmails = $signUpEmails->unique()->values();

            // Find duplicates (emails in both ticket and sign-up data)
            $duplicateEmails = $ticketEmails->intersect($signUpEmails)->values();

            // Emails only in tickets
            $ticketOnlyEmails = $ticketEmails->diff($signUpEmails)->values();

            // Emails only in sign-up forms
            $signUpOnlyEmails = $signUpEmails->diff($ticketEmails)->values();

            // Get detailed duplicate information
            $duplicateDetails = $duplicateEmails->map(function ($email) use ($ticketAttendees, $event, $signUpAttendeesByLocation, $locations) {
                // Find ticket data (case-insensitive match)
                $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                    return strtolower(trim($attendee->email)) === strtolower(trim($email));
                });

                $signUpDataList = [];

                if ($event->signUpForm && $event->signUpForm->table_name && Schema::hasTable($event->signUpForm->table_name)) {
                    foreach ($locations as $location) {
                        if (isset($signUpAttendeesByLocation[$location->id])) {
                            $signUpData = $signUpAttendeesByLocation[$location->id]
                                ->first(function ($attendee) use ($email) {
                                    return strtolower(trim($attendee->email_address ?? '')) === strtolower(trim($email));
                                });

                            if ($signUpData) {
                                $signUpDataList[] = [
                                    'first_name' => $signUpData->first_name ?? null,
                                    'last_name' => $signUpData->last_name ?? null,
                                    'phone' => $signUpData->mobile_number ?? null,
                                    'location_name' => $location->name,
                                    'event_name' => $event->event_name,
                                    'source' => 'Sign-Up Form',
                                ];
                            }
                        }
                    }
                }

                return [
                    'email' => $email,
                    'ticket_data' => $ticketData ? [
                        'first_name' => $ticketData->first_name,
                        'last_name' => $ticketData->last_name,
                        'phone' => $ticketData->phone,
                        'location_name' => $ticketData->location->name ?? 'N/A',
                        'event_name' => $ticketData->event->event_name ?? 'N/A',
                        'source' => 'Eventbrite Ticket',
                    ] : null,
                    'signup_data' => $signUpDataList,
                ];
            });

            // Per location statistics
            $locationStats = $locations->map(function ($location) use ($ticketAttendees, $event, $signUpAttendeesByLocation) {
                $locationTicketAttendees = $ticketAttendees->where('location_id', $location->id);
                $locationTicketEmails = $locationTicketAttendees->pluck('email')
                    ->map(function ($email) {
                        return strtolower(trim($email));
                    })
                    ->filter()
                    ->unique()
                    ->values();

                $locationSignUpEmails = collect();

                if ($event->signUpForm && $event->signUpForm->table_name && Schema::hasTable($event->signUpForm->table_name)) {
                    if (isset($signUpAttendeesByLocation[$location->id])) {
                        $locationSignUpEmails = $signUpAttendeesByLocation[$location->id]
                            ->pluck('email_address')
                            ->map(function ($email) {
                                return strtolower(trim($email));
                            })
                            ->filter()
                            ->unique()
                            ->values();
                    }
                }

                $locationDuplicates = $locationTicketEmails->intersect($locationSignUpEmails)->count();

                return [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'event_name' => $event->event_name,
                    'ticket_emails_count' => $locationTicketEmails->count(),
                    'signup_emails_count' => $locationSignUpEmails->count(),
                    'duplicate_emails_count' => $locationDuplicates,
                    'ticket_only_count' => $locationTicketEmails->diff($locationSignUpEmails)->count(),
                    'signup_only_count' => $locationSignUpEmails->diff($locationTicketEmails)->count(),
                ];
            });

            return response()->json([
                'event' => $event,
                'summary' => [
                    'ticket_emails_count' => $ticketEmails->count(),
                    'signup_emails_count' => $signUpEmails->count(),
                    'duplicate_emails_count' => $duplicateEmails->count(),
                    'ticket_only_count' => $ticketOnlyEmails->count(),
                    'signup_only_count' => $signUpOnlyEmails->count(),
                    'total_unique_emails' => $ticketEmails->merge($signUpEmails)->unique()->count(),
                    'total_locations' => $locations->count(),
                ],
                'duplicate_emails' => $duplicateDetails,
                'ticket_only_emails' => $ticketOnlyEmails,
                'signup_only_emails' => $signUpOnlyEmails,
                'location_stats' => $locationStats,
                'locations' => $locations,
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting event ticket report', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to get event ticket report: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all data for a location including tickets and win form data with tags
     */
    public function exportLocationData($locationId)
    {
        try {
            $location = Location::with('event')->findOrFail($locationId);
            $event = $location->event;

            // Get Mailchimp settings for tag generation
            $autoMailchimpService = app(AutoMailchimpService::class);
            $settings = $autoMailchimpService->getSettings($event->id);

            $filmTour = $settings['film_tour'] ?? 'WM';
            $year = $event->event_year ?? date('Y');
            $locationName = $location->name;
            $locationCountry = $location->country ?? '';

            // Extract location tag (everything before hyphen)
            $locationTag = explode(' - ', $locationName)[0];
            $locationTagUpper = strtoupper($locationTag);

            // SHOW tag: USA & Canada event only = include state. Australia/NZ/other event = location only, no state. Use event country for this decision.
            $eventCountryUpper = strtoupper($event->event_country ?? $location->country ?? '');
            $isUSA = in_array($eventCountryUpper, ['USA', 'USA & CANADA', 'USA AND CANADA']);
            $locationCountry = strtoupper($location->country ?? $event->event_country ?? '');
            $sourceTagLocation = trim(preg_replace('/,?\s+[A-Z]{2,3}$/i', '', $locationTagUpper));
            if (! empty($location->state)) {
                $stateEsc = preg_quote(trim(strtoupper($location->state)), '/');
                $stripped = trim(preg_replace('/,?\s*'.$stateEsc.'$/i', '', $sourceTagLocation));
                if ($stripped !== '') {
                    $sourceTagLocation = $stripped;
                }
            }
            $locationParts = explode(' ', trim($locationTagUpper));
            $stateFromName = '';
            $locationWithoutState = $locationTagUpper;
            if ($isUSA && count($locationParts) > 1) {
                $lastPart = end($locationParts);
                if (preg_match('/^[A-Z]{2,3}$/', $lastPart)) {
                    $stateFromName = $lastPart;
                    $locationWithoutState = trim(str_replace($lastPart, '', $locationTagUpper));
                }
            }
            $state = $stateFromName ?: trim(strtoupper($location->state ?? ''));
            $showTagLocation = ($isUSA && $state)
                ? trim($locationWithoutState).', '.$state
                : $sourceTagLocation;

            // Get default tags
            $defaultTags = [];
            if (! empty($settings['default_tags'])) {
                if (is_array($settings['default_tags'])) {
                    $defaultTags = $settings['default_tags'];
                } else {
                    $defaultTags = array_map('trim', explode(',', $settings['default_tags']));
                }
            }

            // Get ticket attendees
            $ticketAttendees = TicketAttendee::where('location_id', $locationId)->get();
            $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique();

            // Get sign-up form attendees
            $signUpForm = SignUpForm::where('event_id', $event->id)->first();
            $signUpAttendees = collect();

            if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                $signUpAttendees = DB::table($signUpForm->table_name)
                    ->where('location_id', $locationId)
                    ->where('event_id', $event->id)
                    ->get();
            }

            $signUpEmails = $signUpAttendees->pluck('email_address')->map(function ($email) {
                return strtolower(trim($email));
            })->filter()->unique();

            // Combine all unique emails
            $allEmails = $ticketEmails->merge($signUpEmails)->unique();

            // Map sign-up form column names to question text for CSV headers
            $columnToQuestion = [];
            if ($signUpForm && ! empty($signUpForm->questions)) {
                $questions = is_array($signUpForm->questions) ? $signUpForm->questions : (json_decode($signUpForm->questions, true) ?? []);
                foreach ($questions as $q) {
                    $col = $q['column_name'] ?? null;
                    $text = $q['text'] ?? null;
                    if ($col !== null && $text !== null && $text !== '') {
                        $columnToQuestion[$col] = $text;
                    }
                }
            }

            // Collect all columns from ticket attendees and sign-up table so export includes every column
            $ticketColumns = ['email', 'first_name', 'last_name', 'phone', 'city', 'state', 'country', 'eventbrite_event_id', 'location_id', 'event_id'];
            $signUpColumns = [];
            if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                $signUpColumns = Schema::getColumnListing($signUpForm->table_name);
                $signUpColumns = array_values(array_diff($signUpColumns, ['id', 'created_at', 'updated_at']));
            }
            // Preferred header order: common fields first, then ticket-only, then sign-up-only (skip email_address we use email)
            $columnOrder = ['email', 'first_name', 'last_name', 'phone', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'postal_code', 'country', 'gender', 'age', 'eventbrite_event_id', 'location_id', 'event_id'];
            $seen = array_flip($columnOrder);
            foreach (array_merge($ticketColumns, $signUpColumns) as $col) {
                if ($col === 'email_address') {
                    continue; // we output as "email"
                }
                if (! isset($seen[$col])) {
                    $columnOrder[] = $col;
                    $seen[$col] = true;
                }
            }
            $columnOrder[] = 'tags';
            // Use question text as header when available (sign-up form), otherwise human-readable column name
            $csvHeaders = array_map(function ($key) use ($columnToQuestion) {
                if ($key === 'tags') {
                    return 'Tags';
                }
                if ($key === 'email') {
                    return $columnToQuestion['email_address'] ?? 'Email';
                }

                return $columnToQuestion[$key] ?? ucwords(str_replace('_', ' ', $key));
            }, $columnOrder);

            // Build export data (all columns per row)
            $exportData = [];

            foreach ($allEmails as $email) {
                $isInTickets = $ticketEmails->contains($email);
                $isInSignUp = $signUpEmails->contains($email);

                $ticketData = null;
                if ($isInTickets) {
                    $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                        return strtolower(trim($attendee->email)) === $email;
                    });
                }

                $signUpData = null;
                if ($isInSignUp) {
                    $signUpData = $signUpAttendees->first(function ($attendee) use ($email) {
                        return strtolower(trim($attendee->email_address ?? '')) === $email;
                    });
                }

                $signUpRow = $signUpData ? (array) $signUpData : [];
                $rowData = [];
                foreach ($columnOrder as $key) {
                    if ($key === 'tags') {
                        continue;
                    }
                    if ($key === 'email') {
                        $rowData[$key] = $ticketData ? $ticketData->email : ($signUpRow['email_address'] ?? $email);

                        continue;
                    }
                    $fromTicket = $ticketData && in_array($key, $ticketColumns, true) ? ($ticketData->{$key} ?? '') : null;
                    $fromSignUp = array_key_exists($key, $signUpRow) ? ($signUpRow[$key] ?? '') : null;
                    if ($fromTicket !== null && $fromTicket !== '') {
                        $rowData[$key] = $fromTicket;
                    } elseif ($fromSignUp !== null && $fromSignUp !== '') {
                        $rowData[$key] = $fromSignUp;
                    } else {
                        $rowData[$key] = '';
                    }
                }

                // Generate tags
                $tags = [];
                if ($locationCountry) {
                    $tags[] = 'COUNTRY - '.strtoupper($locationCountry);
                }
                $tags[] = 'SHOW - '.$showTagLocation;
                if ($isInTickets) {
                    $tags[] = 'SOURCE - '.strtoupper($filmTour).' '.$sourceTagLocation.' TIX '.$year;
                }
                if ($isInSignUp) {
                    $tags[] = 'SOURCE - '.strtoupper($filmTour).' '.$sourceTagLocation.' COMP '.$year;
                }
                $tags = array_merge($tags, $defaultTags);
                $rowData['tags'] = implode(', ', $tags);

                $exportData[] = $rowData;
            }

            // Generate CSV with all columns
            $filename = 'location_export_'.Str::slug($locationName).'_'.date('Y-m-d').'.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            $callback = function () use ($exportData, $columnOrder, $csvHeaders) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $csvHeaders);
                foreach ($exportData as $row) {
                    $line = [];
                    foreach ($columnOrder as $key) {
                        $line[] = $row[$key] ?? '';
                    }
                    fputcsv($file, $line);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting location data', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to export location data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all data for all locations in an event including tickets and win form data with tags
     */
    public function exportEventData($eventId)
    {
        try {
            $event = Events::findOrFail($eventId);
            $locations = Location::where('event_id', $eventId)->get();

            if ($locations->isEmpty()) {
                return response()->json([
                    'error' => 'No locations found for this event',
                ], 404);
            }

            // Get Mailchimp settings for tag generation
            $autoMailchimpService = app(AutoMailchimpService::class);
            $settings = $autoMailchimpService->getSettings($eventId);

            $filmTour = $settings['film_tour'] ?? 'WM';
            $year = $event->event_year ?? date('Y');

            // Get default tags
            $defaultTags = [];
            if (! empty($settings['default_tags'])) {
                if (is_array($settings['default_tags'])) {
                    $defaultTags = $settings['default_tags'];
                } else {
                    $defaultTags = array_map('trim', explode(',', $settings['default_tags']));
                }
            }

            // Get sign-up form for the event
            $signUpForm = SignUpForm::where('event_id', $eventId)->first();

            // Collect all unique emails across all locations
            // Structure: email => [email, first_name, last_name, phone, city, state, country, locations => []]
            // locations: [{location_id, location_name, location_tag, location_country, has_ticket, has_signup}]
            $allEmailsMap = [];

            // Process each location
            // Use event country for "add state to tags?" so Australia & New Zealand events never get state in SHOW tag.
            $eventCountryUpper = strtoupper($event->event_country ?? '');
            foreach ($locations as $location) {
                $locationName = $location->name;
                $locationCountry = $location->country ?? '';
                $locationTag = explode(' - ', $locationName)[0];
                $locationTagUpper = strtoupper($locationTag);
                $locCountryUpper = strtoupper($locationCountry);
                $isUSA = in_array($eventCountryUpper ?: $locCountryUpper, ['USA', 'USA & CANADA', 'USA AND CANADA']);
                $sourceTagLocation = trim(preg_replace('/,?\s+[A-Z]{2,3}$/i', '', $locationTagUpper));
                if (! empty($location->state)) {
                    $stateEsc = preg_quote(trim(strtoupper($location->state)), '/');
                    $stripped = trim(preg_replace('/,?\s*'.$stateEsc.'$/i', '', $sourceTagLocation));
                    if ($stripped !== '') {
                        $sourceTagLocation = $stripped;
                    }
                }
                $locationParts = explode(' ', trim($locationTagUpper));
                $stateFromName = '';
                $locationWithoutState = $locationTagUpper;
                if ($isUSA && count($locationParts) > 1) {
                    $lastPart = end($locationParts);
                    if (preg_match('/^[A-Z]{2,3}$/', $lastPart)) {
                        $stateFromName = $lastPart;
                        $locationWithoutState = trim(str_replace($lastPart, '', $locationTagUpper));
                    }
                }
                $state = $stateFromName ?: trim(strtoupper($location->state ?? ''));
                $showTagLocation = ($isUSA && $state)
                    ? trim($locationWithoutState).', '.$state
                    : $sourceTagLocation;

                // Get ticket attendees for this location
                $ticketAttendees = TicketAttendee::where('location_id', $location->id)->get();
                $ticketEmails = $ticketAttendees->pluck('email')->map(function ($email) {
                    return strtolower(trim($email));
                })->filter()->unique();

                // Get sign-up form attendees for this location
                $signUpAttendees = collect();
                if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
                    $signUpAttendees = DB::table($signUpForm->table_name)
                        ->where('location_id', $location->id)
                        ->where('event_id', $eventId)
                        ->get();
                }

                $signUpEmails = $signUpAttendees->pluck('email_address')->map(function ($email) {
                    return strtolower(trim($email));
                })->filter()->unique();

                // Process ticket emails - add or update existing email entry
                foreach ($ticketEmails as $email) {
                    $ticketData = $ticketAttendees->first(function ($attendee) use ($email) {
                        return strtolower(trim($attendee->email)) === $email;
                    });

                    if (! isset($allEmailsMap[$email])) {
                        // Create new entry
                        $allEmailsMap[$email] = [
                            'email' => $ticketData ? $ticketData->email : $email,
                            'first_name' => $ticketData ? $ticketData->first_name : '',
                            'last_name' => $ticketData ? $ticketData->last_name : '',
                            'phone' => $ticketData ? $ticketData->phone : '',
                            'city' => $ticketData ? $ticketData->city : '',
                            'state' => $ticketData ? $ticketData->state : '',
                            'country' => $ticketData ? $ticketData->country : '',
                            'locations' => [],
                        ];
                    }

                    // Check if this location already exists in the locations array
                    $locationIndex = null;
                    foreach ($allEmailsMap[$email]['locations'] as $idx => $loc) {
                        if ($loc['location_id'] === $location->id) {
                            $locationIndex = $idx;
                            break;
                        }
                    }

                    if ($locationIndex === null) {
                        // Add new location entry
                        $allEmailsMap[$email]['locations'][] = [
                            'location_id' => $location->id,
                            'location_name' => $locationName,
                            'location_tag' => $showTagLocation, // Use formatted SHOW tag location
                            'location_tag_source' => $sourceTagLocation, // Separate tag for SOURCE
                            'location_country' => $locationCountry,
                            'has_ticket' => false,
                            'has_signup' => false,
                        ];
                        $locationIndex = count($allEmailsMap[$email]['locations']) - 1;
                    }

                    // Mark as having ticket from this location
                    $allEmailsMap[$email]['locations'][$locationIndex]['has_ticket'] = true;

                    // Update data if ticket data is better (has more info)
                    if ($ticketData) {
                        if (empty($allEmailsMap[$email]['first_name']) && $ticketData->first_name) {
                            $allEmailsMap[$email]['first_name'] = $ticketData->first_name;
                        }
                        if (empty($allEmailsMap[$email]['last_name']) && $ticketData->last_name) {
                            $allEmailsMap[$email]['last_name'] = $ticketData->last_name;
                        }
                        if (empty($allEmailsMap[$email]['phone']) && $ticketData->phone) {
                            $allEmailsMap[$email]['phone'] = $ticketData->phone;
                        }
                        if (empty($allEmailsMap[$email]['city']) && $ticketData->city) {
                            $allEmailsMap[$email]['city'] = $ticketData->city;
                        }
                        if (empty($allEmailsMap[$email]['state']) && $ticketData->state) {
                            $allEmailsMap[$email]['state'] = $ticketData->state;
                        }
                        if (empty($allEmailsMap[$email]['country']) && $ticketData->country) {
                            $allEmailsMap[$email]['country'] = $ticketData->country;
                        }
                    }
                }

                // Process sign-up emails - add or update existing email entry
                foreach ($signUpEmails as $email) {
                    $signUpData = $signUpAttendees->first(function ($attendee) use ($email) {
                        return strtolower(trim($attendee->email_address ?? '')) === $email;
                    });

                    if (! isset($allEmailsMap[$email])) {
                        // Create new entry
                        $allEmailsMap[$email] = [
                            'email' => $signUpData ? ($signUpData->email_address ?? $email) : $email,
                            'first_name' => $signUpData ? ($signUpData->first_name ?? '') : '',
                            'last_name' => $signUpData ? ($signUpData->last_name ?? '') : '',
                            'phone' => $signUpData ? ($signUpData->mobile_number ?? '') : '',
                            'city' => $signUpData ? ($signUpData->city ?? '') : '',
                            'state' => $signUpData ? ($signUpData->state ?? '') : '',
                            'country' => $signUpData ? ($signUpData->country ?? '') : '',
                            'locations' => [],
                        ];
                    }

                    // Check if this location already exists in the locations array
                    $locationIndex = null;
                    foreach ($allEmailsMap[$email]['locations'] as $idx => $loc) {
                        if ($loc['location_id'] === $location->id) {
                            $locationIndex = $idx;
                            break;
                        }
                    }

                    if ($locationIndex === null) {
                        // Add new location entry
                        $allEmailsMap[$email]['locations'][] = [
                            'location_id' => $location->id,
                            'location_name' => $locationName,
                            'location_tag' => $showTagLocation, // Use formatted SHOW tag location
                            'location_tag_source' => $sourceTagLocation, // Separate tag for SOURCE
                            'location_country' => $locationCountry,
                            'has_ticket' => false,
                            'has_signup' => false,
                        ];
                        $locationIndex = count($allEmailsMap[$email]['locations']) - 1;
                    }

                    // Mark as having sign-up from this location
                    $allEmailsMap[$email]['locations'][$locationIndex]['has_signup'] = true;

                    // Update data if sign-up data is better
                    if ($signUpData) {
                        if (empty($allEmailsMap[$email]['first_name']) && ($signUpData->first_name ?? '')) {
                            $allEmailsMap[$email]['first_name'] = $signUpData->first_name;
                        }
                        if (empty($allEmailsMap[$email]['last_name']) && ($signUpData->last_name ?? '')) {
                            $allEmailsMap[$email]['last_name'] = $signUpData->last_name;
                        }
                        if (empty($allEmailsMap[$email]['phone']) && ($signUpData->mobile_number ?? '')) {
                            $allEmailsMap[$email]['phone'] = $signUpData->mobile_number;
                        }
                        if (empty($allEmailsMap[$email]['city']) && ($signUpData->city ?? '')) {
                            $allEmailsMap[$email]['city'] = $signUpData->city;
                        }
                        if (empty($allEmailsMap[$email]['state']) && ($signUpData->state ?? '')) {
                            $allEmailsMap[$email]['state'] = $signUpData->state;
                        }
                        if (empty($allEmailsMap[$email]['country']) && ($signUpData->country ?? '')) {
                            $allEmailsMap[$email]['country'] = $signUpData->country;
                        }
                    }
                }
            }

            // Build export data with tags
            $exportData = [];

            foreach ($allEmailsMap as $email => $data) {
                // Collect all tags from all locations this email appears in
                $allTags = [];
                $countries = [];
                $locationTags = [];
                $tixTags = [];
                $compTags = [];

                foreach ($data['locations'] as $loc) {
                    // COUNTRY tag
                    if ($loc['location_country'] && ! in_array($loc['location_country'], $countries)) {
                        $countries[] = $loc['location_country'];
                        $allTags[] = 'COUNTRY - '.strtoupper($loc['location_country']);
                    }

                    // SHOW tag
                    $showTag = 'SHOW - '.$loc['location_tag'];
                    if (! in_array($showTag, $locationTags)) {
                        $locationTags[] = $showTag;
                        $allTags[] = $showTag;
                    }

                    // SOURCE tags based on has_ticket and has_signup flags
                    // If email has ticket from this location, add TIX tag
                    if ($loc['has_ticket']) {
                        $sourceLocation = $loc['location_tag_source'] ?? $loc['location_tag'];
                        $tixTag = 'SOURCE - '.strtoupper($filmTour).' '.$sourceLocation.' TIX '.$year;
                        if (! in_array($tixTag, $tixTags)) {
                            $tixTags[] = $tixTag;
                            $allTags[] = $tixTag;
                        }
                    }

                    // If email has signup from this location, add COMP tag
                    if ($loc['has_signup']) {
                        $sourceLocation = $loc['location_tag_source'] ?? $loc['location_tag'];
                        $compTag = 'SOURCE - '.strtoupper($filmTour).' '.$sourceLocation.' COMP '.$year;
                        if (! in_array($compTag, $compTags)) {
                            $compTags[] = $compTag;
                            $allTags[] = $compTag;
                        }
                    }
                }

                // Add default tags
                $allTags = array_merge($allTags, $defaultTags);

                // Remove duplicates and sort
                $allTags = array_unique($allTags);
                sort($allTags);

                $exportData[] = [
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => $data['phone'],
                    'city' => $data['city'],
                    'state' => $data['state'],
                    'country' => $data['country'],
                    'tags' => implode(', ', $allTags),
                ];
            }

            // Generate CSV
            $filename = 'event_export_'.Str::slug($event->event_name).'_'.date('Y-m-d').'.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            $callback = function () use ($exportData) {
                $file = fopen('php://output', 'w');

                // Write headers
                fputcsv($file, ['Email', 'First Name', 'Last Name', 'Phone', 'City', 'State', 'Country', 'Tags']);

                // Write data
                foreach ($exportData as $row) {
                    fputcsv($file, [
                        $row['email'],
                        $row['first_name'],
                        $row['last_name'],
                        $row['phone'],
                        $row['city'],
                        $row['state'],
                        $row['country'],
                        $row['tags'],
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting event data', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to export event data: '.$e->getMessage(),
            ], 500);
        }
    }
}
