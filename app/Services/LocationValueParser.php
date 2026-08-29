<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The value conventions shared by everything that writes a Location from
 * spreadsheet-shaped input.
 *
 * Two callers rely on these being identical: the paste-a-grid save in
 * LocationController, and the scheduled Google Sheets sync. If they parsed dates
 * or composed names differently, the same screening pasted by hand and pulled by
 * the sync would become two separate locations.
 */
class LocationValueParser
{
    /**
     * Parse time string to 24-hour format (HH:MM) like "20:00", "19:00", "18:30".
     * Handles "7:00PM", "7pm", "7:00 pm", "07:00", and 'TBA'.
     */
    public function parseTime($timeString): ?string
    {
        if (empty($timeString)) {
            return null;
        }

        $timeString = trim($timeString);

        // 'TBA' is a real value, not a parse failure — a screening is often booked
        // before its time is locked in.
        if (strcasecmp($timeString, 'TBA') === 0) {
            return 'TBA';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeString, $matches)) {
            $hour24 = (int) $matches[1];
            $minutes = (int) $matches[2];

            if ($hour24 < 0 || $hour24 > 23 || $minutes < 0 || $minutes > 59) {
                Log::warning('Invalid 24-hour time format', ['time' => $timeString]);

                return null;
            }

            return sprintf('%02d:%02d', $hour24, $minutes);
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', $timeString, $matches)) {
            $hour = (int) $matches[1];
            $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
            $ampm = strtoupper($matches[3]);

            if ($hour < 1 || $hour > 12) {
                Log::warning('Invalid hour in time string', ['time' => $timeString, 'hour' => $hour]);

                return null;
            }

            if ($minutes < 0 || $minutes > 59) {
                Log::warning('Invalid minutes in time string', ['time' => $timeString, 'minutes' => $minutes]);

                return null;
            }

            $hour24 = $hour;
            if ($ampm === 'PM' && $hour != 12) {
                $hour24 = $hour + 12;
            } elseif ($ampm === 'AM' && $hour == 12) {
                $hour24 = 0;
            }

            return sprintf('%02d:%02d', $hour24, $minutes);
        }

        try {
            return Carbon::createFromTimeString($timeString)->format('H:i');
        } catch (\Exception $e) {
            Log::warning('Unable to parse time string', [
                'time_string' => $timeString,
                'error' => $e->getMessage(),
            ]);

            return $timeString;
        }
    }

    /**
     * Parse date string to YYYY-MM-DD.
     *
     * The master schedule writes dates as "Thursday, 5 November 2026" — day-first,
     * month spelled out — which Format 1 below handles after the day name is
     * stripped. 'TBA' passes through as itself.
     */
    public function parseDate($dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        $dateString = trim($dateString);

        if (strcasecmp($dateString, 'TBA') === 0) {
            return 'TBA';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return $dateString;
        }

        $cleanedDate = preg_replace('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*/i', '', $dateString);

        // Format 1: "31 May 2026" (UK/Australian — day month year)
        if (preg_match('/^(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})$/i', $cleanedDate, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $monthName = ucfirst(strtolower($matches[2]));
            $year = $matches[3];

            $monthNum = date('m', strtotime($monthName.' 1'));
            if ($monthNum === false) {
                Log::error('Failed to convert month name', ['month' => $monthName]);

                return null;
            }

            return "$year-$monthNum-$day";
        }

        // Format 2: "January 23, 2026" (US — month day, year)
        if (preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2}),?\s+(\d{4})$/i', $cleanedDate, $matches)) {
            $monthName = ucfirst(strtolower($matches[1]));
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            $monthNum = date('m', strtotime($monthName.' 1'));
            if ($monthNum === false) {
                Log::error('Failed to convert month name', ['month' => $monthName]);

                return null;
            }

            return "$year-$monthNum-$day";
        }

        try {
            return Carbon::parse($cleanedDate)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::error('Unable to parse date string', [
                'original_date' => $dateString,
                'cleaned_date' => $cleanedDate,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The sheet's checkbox columns arrive as the literal strings TRUE / FALSE.
     * Anything unrecognised is treated as false rather than guessed at — these
     * drive "has the DCP been sent", and a wrong true is worse than a wrong false.
     */
    public function parseBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtoupper(trim((string) $value)), ['TRUE', 'YES', 'Y', '1', 'X', '✓'], true);
    }

    /**
     * A cell holding a spreadsheet error carries no value — #DIV/0!, #REF!, #N/A
     * and friends appear throughout the source sheet's calculated columns.
     */
    public function isFormulaError($value): bool
    {
        return (bool) preg_match('/^#(DIV\/0|REF|N\/A|VALUE|NAME\?|NUM|NULL|ERROR)[!?]?$/i', trim((string) $value));
    }

    /**
     * Whole numbers only, ignoring stray formatting and formula errors.
     */
    public function parseInt($value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || $this->isFormulaError($value)) {
            return null;
        }

        $digits = preg_replace('/[^\d-]/', '', $value);

        return $digits === '' || $digits === '-' ? null : (int) $digits;
    }

    /**
     * The spellings that mean a country rather than a state.
     *
     * Needed because the shape of the value cannot decide it on its own — 'USA'
     * and 'NZ' are as short as a state code, and 'WA' is a state in two of the
     * countries this tours. Keyed by the lowercased cell, valued with the
     * spelling already used across the locations table so a report grouping by
     * country does not silently split into "Australia" and "AUS".
     */
    public const COUNTRIES = [
        'australia' => 'Australia',
        'aus' => 'Australia',
        'au' => 'Australia',
        'new zealand' => 'New Zealand',
        'newzealand' => 'New Zealand',
        'nz' => 'New Zealand',
        'usa' => 'USA',
        'us' => 'USA',
        'u.s.' => 'USA',
        'u.s.a.' => 'USA',
        'united states' => 'USA',
        'united states of america' => 'USA',
        'uk' => 'United Kingdom',
        'united kingdom' => 'United Kingdom',
        'great britain' => 'United Kingdom',
        'canada' => 'Canada',
        'ca' => 'Canada',
        'ireland' => 'Ireland',
    ];

    /**
     * Resolve the tab's region columns into a state code and a country.
     *
     * Tabs vary: some carry a dedicated State column beside Country, most carry
     * one combined 'Country/State' holding whichever the tour tracks in that
     * territory — 'VIC' for an Australian leg, 'New Zealand' for a country with
     * no state breakdown. Getting this right matters beyond tidiness: the state
     * is baked into the location's name, and the name is what decides whether a
     * sheet row updates an existing screening or creates a second one.
     *
     * A value is a country when it is a known spelling of one; anything else
     * short and wordless is a state code. 'CA' is ambiguous between California
     * and Canada — it is listed as Canada only for the combined column, and a
     * tab with a real State column always wins over the guess.
     *
     * @return array{state: string, country: string}
     */
    public function splitRegion(?string $country, ?string $state = null): array
    {
        $country = trim((string) $country);
        $state = trim((string) $state);

        // A dedicated State column is unambiguous — nothing to work out.
        if ($state !== '') {
            return [
                'state' => strtoupper($state),
                'country' => self::COUNTRIES[strtolower($country)] ?? $country,
            ];
        }

        if ($country === '') {
            return ['state' => '', 'country' => ''];
        }

        if (isset(self::COUNTRIES[strtolower($country)])) {
            return ['state' => '', 'country' => self::COUNTRIES[strtolower($country)]];
        }

        // Short, one word, letters only: a state or province code. Longer than
        // that and it is a place name the country list simply has not seen.
        if (preg_match('/^[A-Za-z]{2,4}$/', $country)) {
            return ['state' => strtoupper($country), 'country' => ''];
        }

        return ['state' => '', 'country' => $country];
    }

    /**
     * Compose a location's stored name: "Location STATE - Cinema".
     *
     * This exact shape is what LocationController::getSheetsData splits back apart,
     * so it must not drift — the state is appended uppercased with no comma, and
     * the cinema follows a spaced hyphen.
     */
    public function composeName(string $location, ?string $state, ?string $cinema): string
    {
        $name = trim($location);
        $state = trim((string) $state);
        $cinema = trim((string) $cinema);

        if ($state !== '') {
            $name .= ' '.strtoupper($state);
        }

        if ($cinema !== '') {
            $name .= ' - '.$cinema;
        }

        return $name;
    }

    /**
     * The password a newly created location should get.
     *
     * Hosts for one event are told a single password, so a new location joins the
     * one its siblings already share. Only a password used by at least three
     * locations counts as the event's common one — below that it is more likely a
     * coincidence than a convention, and a random password is safer.
     */
    public function passwordForNewLocation(int $eventId): string
    {
        $common = Location::where('event_id', $eventId)
            ->whereNotNull('password')
            ->selectRaw('password, COUNT(*) as total')
            ->groupBy('password')
            ->having('total', '>=', 3)
            ->orderByDesc('total')
            ->value('password');

        return $common ?: Str::random(10);
    }
}
