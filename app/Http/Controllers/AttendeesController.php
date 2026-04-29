<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\SignUpForm;
use App\Models\Location;
use App\Models\Prize;
use App\Models\MailchimpImportLog;
use App\Services\AutoMailchimpService;
use App\Services\MailchimpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $attendees = $this->attachInterestTags($attendees, in_array('fave_sport', $signupColumns), $eventId);

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

        // Mailchimp import history (session logs) for this event
        $mailchimpImportLogs = MailchimpImportLog::query()
            ->select('mailchimp_import_logs.*', 'locations.name as location_name', 'users.name as imported_by_name')
            ->join('locations', 'locations.id', '=', 'mailchimp_import_logs.location_id')
            ->leftJoin('users', 'users.id', '=', 'mailchimp_import_logs.imported_by')
            ->where('locations.event_id', $eventId)
            ->orderByDesc('mailchimp_import_logs.created_at')
            ->get();
        
        return Inertia::render('Attendees', [
            'event' => $event,  // ✅ Pass the full event object instead of just ID
            'attendees' => $attendees,
            'form' => $signupForm, // Pass the form data to get access to questions
            'prizes' => $prizes,
            'mailchimpImportLogs' => $mailchimpImportLogs,
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

        $attendees = $this->attachInterestTags($attendees, Schema::hasColumn($tableName, 'fave_sport'), $eventId);

        // ✅ Fetch prizes/winners for this location
        $prizes = Prize::where('event_id', $eventId)
            ->where('location_id', $locationId)
            ->get();

        // Win vs ticket import to Mailchimp (for header indicators)
        $location->imported_win_to_mailchimp = MailchimpImportLog::where('location_id', $locationId)
            ->where('source', 'signup_form')
            ->exists();
        $location->imported_ticket_to_mailchimp = MailchimpImportLog::where('location_id', $locationId)
            ->where(function ($q) {
                $q->where('source', 'ticket_data')
                    ->orWhereRaw("(tags IS NOT NULL AND (tags LIKE '%TIX%'))");
            })
            ->exists();
        
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

    /**
     * Return ALL attendees for an event (for export). No limit – same data as the table, every location.
     */
    public function exportAll($eventId)
    {
        $event = Events::findOrFail($eventId);
        $signupForm = SignUpForm::where('event_id', $eventId)->first();
        if (!$signupForm) {
            return response()->json(['success' => false, 'error' => 'Sign-up form not found'], 404);
        }
        $tableName = $signupForm->table_name;

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

        return response()->json([
            'success' => true,
            'attendees' => $attendees,
            'form' => $signupForm ? $signupForm->toArray() : null,
        ]);
    }

    /**
     * Stream ALL attendees as CSV (no limit). Use this when export must include every row in the table.
     */
    public function exportCsv(Request $request, $eventId): StreamedResponse
    {
        $event = Events::findOrFail($eventId);
        $signupForm = SignUpForm::where('event_id', $eventId)->first();
        if (!$signupForm) {
            abort(404, 'Sign-up form not found');
        }

        $tableName = $signupForm->table_name;
        $defaultSourceWord = $request->input('defaultSourceWord', 'WM');
        $exportTags = $request->input('exportTags', '');
        $questions = is_string($signupForm->questions) ? json_decode($signupForm->questions, true) : ($signupForm->questions ?? []);
        $questionByColumn = collect($questions)->keyBy('column_name');

        $excluded = ['id', 'event_id', 'location_id', 'updated_at', 'events_location', 'mobile_number_format'];
        $signupColumns = Schema::getColumnListing($tableName);
        $exportCols = array_values(array_filter($signupColumns, fn ($c) => !in_array($c, $excluded)));
        $exportCols[] = 'location_name';

        $filename = 'attendees_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name ?? 'event') . '.csv';

        return new StreamedResponse(function () use ($tableName, $eventId, $event, $exportCols, $questionByColumn, $defaultSourceWord, $exportTags) {
            $handle = fopen('php://output', 'w');
            $headerLabels = array_map(function ($col) use ($questionByColumn) {
                $q = $questionByColumn->get($col);
                return (is_array($q) && isset($q['text'])) ? $q['text'] : ucfirst(str_replace('_', ' ', $col));
            }, $exportCols);
            fputcsv($handle, array_merge($headerLabels, ['Opt In Date', 'Tags']));

            $signupColumnsFull = array_map(fn ($c) => "{$tableName}.{$c}", Schema::getColumnListing($tableName));
            $selectClause = array_merge($signupColumnsFull, [DB::raw('locations.name as location_name')]);

            $query = DB::table($tableName)
                ->leftJoin('locations', "{$tableName}.location_id", '=', 'locations.id')
                ->where("{$tableName}.event_id", $eventId)
                ->select($selectClause)
                ->orderBy("{$tableName}.location_id")
                ->orderBy("{$tableName}.id");

            foreach ($query->cursor() as $row) {
                $rowArray = (array) $row;
                $dataRow = [];
                foreach ($exportCols as $col) {
                    $val = $rowArray[$col] ?? '';
                    $dataRow[] = $val;
                }
                $optInDate = isset($rowArray['created_at']) ? date('Y-m-d H:i:s', strtotime($rowArray['created_at'])) : '';
                $tags = $this->buildTagsForExport($rowArray, $event, $defaultSourceWord, $exportTags);
                fputcsv($handle, array_merge($dataRow, [$optInDate, $tags]));
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Attach an interest_tags array to each attendee row based on their
     * fave_sport answer. Returns the (mutated) collection unchanged when the
     * signup table has no fave_sport column. Uses the admin-configured
     * interest_tag_map from the event's Mailchimp auto-sync settings so the
     * displayed tags match what auto-sync and batch imports apply.
     */
    private function attachInterestTags($attendees, bool $hasFaveSport, $eventId = null)
    {
        $map = [];
        if ($hasFaveSport && $eventId) {
            $settings = app(AutoMailchimpService::class)->getSettings($eventId);
            $map = is_array($settings['interest_tag_map'] ?? null) ? $settings['interest_tag_map'] : [];
        }

        return $attendees->each(function ($attendee) use ($hasFaveSport, $map) {
            $attendee->interest_tags = $hasFaveSport
                ? MailchimpService::mapFaveSportToInterestTags($attendee->fave_sport ?? null, $map)
                : [];
        });
    }

    /**
     * Stream ALL ticket_attendees for an event as CSV. Accepts defaultSourceWord
     * (the film tour code) and exportTags (comma-separated default tags) so the
     * generated Tags column matches the sign-up form export's tag conventions.
     */
    public function exportTickets(Request $request, $eventId): StreamedResponse
    {
        $event = Events::findOrFail($eventId);
        $defaultSourceWord = $request->input('defaultSourceWord', 'WM');
        $exportTags = $request->input('exportTags', '');
        $filename = 'tix_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name ?? 'event') . '.csv';

        return new StreamedResponse(function () use ($event, $eventId, $defaultSourceWord, $exportTags) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Location', 'Email', 'First Name', 'Last Name', 'Phone', 'City', 'State', 'Country', 'Eventbrite Event ID', 'Imported At', 'Tags']);

            $query = DB::table('ticket_attendees')
                ->leftJoin('locations', 'ticket_attendees.location_id', '=', 'locations.id')
                ->where('ticket_attendees.event_id', $eventId)
                ->select([
                    'ticket_attendees.email',
                    'ticket_attendees.first_name',
                    'ticket_attendees.last_name',
                    'ticket_attendees.phone',
                    'ticket_attendees.city',
                    'ticket_attendees.state',
                    'ticket_attendees.country',
                    'ticket_attendees.eventbrite_event_id',
                    'ticket_attendees.created_at',
                    DB::raw('locations.name as location_name'),
                ])
                ->orderBy('ticket_attendees.location_id')
                ->orderBy('ticket_attendees.id');

            foreach ($query->cursor() as $row) {
                $rowArr = (array) $row;
                fputcsv($handle, [
                    $rowArr['location_name'] ?? '',
                    $rowArr['email'] ?? '',
                    $rowArr['first_name'] ?? '',
                    $rowArr['last_name'] ?? '',
                    $rowArr['phone'] ?? '',
                    $rowArr['city'] ?? '',
                    $rowArr['state'] ?? '',
                    $rowArr['country'] ?? '',
                    $rowArr['eventbrite_event_id'] ?? '',
                    !empty($rowArr['created_at']) ? date('Y-m-d H:i:s', strtotime($rowArr['created_at'])) : '',
                    $this->buildTagsForExport($rowArr, $event, $defaultSourceWord, $exportTags),
                ]);
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Stream a single combined CSV merging sign-up form (win) + ticket_attendees (tix) data
     * for an event, deduplicated by lowercased email. Rows present in both sources collapse to
     * one and are flagged source = "both" in the output. Accepts defaultSourceWord (film tour
     * code) and exportTags (comma-separated default tags) for the Tags column.
     */
    public function exportAllWinTix(Request $request, $eventId): StreamedResponse
    {
        $event = Events::findOrFail($eventId);
        $defaultSourceWord = $request->input('defaultSourceWord', 'WM');
        $exportTags = $request->input('exportTags', '');
        $signupForm = SignUpForm::where('event_id', $eventId)->first();
        if (!$signupForm) {
            abort(404, 'Sign-up form not found for this event');
        }

        $tableName = $signupForm->table_name;
        $excluded = ['id', 'event_id', 'location_id', 'updated_at', 'events_location', 'mobile_number_format'];
        $signupColumns = Schema::getColumnListing($tableName);
        $exportSignupCols = array_values(array_filter($signupColumns, fn ($c) => !in_array($c, $excluded)));

        $questions = is_string($signupForm->questions) ? json_decode($signupForm->questions, true) : ($signupForm->questions ?? []);
        $questionByColumn = collect($questions)->keyBy('column_name');

        // Sign-up form rows: bucketed by lowercased email; rows without email kept separately
        $signupByEmail = [];
        $signupNoEmail = [];
        $signupQuery = DB::table($tableName)
            ->leftJoin('locations', "{$tableName}.location_id", '=', 'locations.id')
            ->where("{$tableName}.event_id", $eventId)
            ->select(array_merge(
                array_map(fn ($c) => "{$tableName}.{$c}", $signupColumns),
                [DB::raw('locations.name as location_name')]
            ))
            ->orderBy("{$tableName}.location_id")
            ->orderBy("{$tableName}.id");
        foreach ($signupQuery->cursor() as $row) {
            $rowArr = (array) $row;
            $email = strtolower(trim($rowArr['email'] ?? ''));
            if ($email !== '') {
                if (!isset($signupByEmail[$email])) $signupByEmail[$email] = $rowArr;
            } else {
                $signupNoEmail[] = $rowArr;
            }
        }

        // Ticket rows: same bucketing
        $tixByEmail = [];
        $tixNoEmail = [];
        $tixQuery = DB::table('ticket_attendees')
            ->leftJoin('locations', 'ticket_attendees.location_id', '=', 'locations.id')
            ->where('ticket_attendees.event_id', $eventId)
            ->select([
                'ticket_attendees.email',
                'ticket_attendees.first_name',
                'ticket_attendees.last_name',
                'ticket_attendees.phone',
                'ticket_attendees.city',
                'ticket_attendees.state',
                'ticket_attendees.country',
                'ticket_attendees.eventbrite_event_id',
                'ticket_attendees.created_at as imported_at',
                DB::raw('locations.name as location_name'),
            ])
            ->orderBy('ticket_attendees.location_id')
            ->orderBy('ticket_attendees.id');
        foreach ($tixQuery->cursor() as $row) {
            $rowArr = (array) $row;
            $email = strtolower(trim($rowArr['email'] ?? ''));
            if ($email !== '') {
                if (!isset($tixByEmail[$email])) $tixByEmail[$email] = $rowArr;
            } else {
                $tixNoEmail[] = $rowArr;
            }
        }

        $filename = 'all_data_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $event->event_name ?? 'event') . '.csv';

        return new StreamedResponse(function () use (
            $event, $exportSignupCols, $questionByColumn, $signupByEmail, $signupNoEmail, $tixByEmail, $tixNoEmail, $defaultSourceWord, $exportTags
        ) {
            $handle = fopen('php://output', 'w');

            $headers = ['Source'];
            foreach ($exportSignupCols as $col) {
                $q = $questionByColumn->get($col);
                $headers[] = (is_array($q) && isset($q['text'])) ? $q['text'] : ucfirst(str_replace('_', ' ', $col));
            }
            $headers = array_merge($headers, [
                'Location',
                'Tix Phone',
                'Tix City',
                'Tix State',
                'Tix Country',
                'Tix Eventbrite Event ID',
                'Tix Imported At',
                'Opt In Date',
                'Tags',
            ]);
            fputcsv($handle, $headers);

            $emit = function (string $source, ?array $signup, ?array $tix) use ($handle, $exportSignupCols, $event, $defaultSourceWord, $exportTags) {
                $out = [$source];
                foreach ($exportSignupCols as $col) {
                    if ($signup !== null) {
                        $out[] = $signup[$col] ?? '';
                    } else {
                        // Tix-only row: backfill identity columns where the sign-up schema has them
                        if ($col === 'email')          $out[] = $tix['email'] ?? '';
                        elseif ($col === 'first_name') $out[] = $tix['first_name'] ?? '';
                        elseif ($col === 'last_name')  $out[] = $tix['last_name'] ?? '';
                        else                           $out[] = '';
                    }
                }
                $out[] = $signup['location_name'] ?? ($tix['location_name'] ?? '');
                $out[] = $tix['phone'] ?? '';
                $out[] = $tix['city'] ?? '';
                $out[] = $tix['state'] ?? '';
                $out[] = $tix['country'] ?? '';
                $out[] = $tix['eventbrite_event_id'] ?? '';
                $out[] = isset($tix['imported_at']) && $tix['imported_at']
                    ? date('Y-m-d H:i:s', strtotime($tix['imported_at'])) : '';
                $out[] = isset($signup['created_at']) && $signup['created_at']
                    ? date('Y-m-d H:i:s', strtotime($signup['created_at'])) : '';
                $tagSourceRow = $signup ?: ($tix ?: []);
                $out[] = $this->buildTagsForExport($tagSourceRow, $event, $defaultSourceWord, $exportTags);
                fputcsv($handle, $out);
            };

            $allEmails = array_unique(array_merge(array_keys($signupByEmail), array_keys($tixByEmail)));
            foreach ($allEmails as $email) {
                $signup = $signupByEmail[$email] ?? null;
                $tix = $tixByEmail[$email] ?? null;
                $source = ($signup && $tix) ? 'both' : ($signup ? 'win' : 'tix');
                $emit($source, $signup, $tix);
            }
            // Rows lacking an email can't be deduped — emit each as its own row
            foreach ($signupNoEmail as $r) $emit('win', $r, null);
            foreach ($tixNoEmail as $r)    $emit('tix', null, $r);

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function buildTagsForExport(array $row, $event, string $defaultSourceWord, ?string $manualTagsStr): string
    {
        $manualTagsStr = $manualTagsStr ?? '';
        $locationName = $row['location_name'] ?? '';
        $extracted = $locationName;
        if (strpos($locationName, ' - ') !== false) {
            $extracted = trim(explode(' - ', $locationName, 2)[0] ?? '');
        }
        $eventYear = $event->event_year ?? date('Y');
        $eventCountry = strtoupper($event->event_country ?? '');
        $isUsaOrCanada = in_array($eventCountry, ['USA', 'CANADA', 'USA & CANADA']);
        $state = '';
        $locationWithoutState = strtoupper($extracted);
        if ($isUsaOrCanada && $extracted !== '') {
            $parts = preg_split('/\s+/', strtoupper($extracted), -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) > 1 && preg_match('/^[A-Z]{2,3}$/', end($parts))) {
                $state = end($parts);
                $locationWithoutState = trim(implode(' ', array_slice($parts, 0, -1)));
            }
        }
        $tags = [];
        if ($extracted !== '') {
            $showTag = $isUsaOrCanada && $state ? "{$locationWithoutState}, {$state}" : $locationWithoutState;
            $sourceTag = strtoupper($defaultSourceWord) . ' ' . $locationWithoutState . ' COMP ' . $eventYear;
            $tags[] = 'SHOW - ' . $showTag;
            $tags[] = 'SOURCE - ' . $sourceTag;
        }
        $manual = array_map('trim', array_filter(explode(',', $manualTagsStr)));
        $manual = array_map('strtoupper', $manual);
        return implode(', ', array_merge($tags, $manual));
    }
}
