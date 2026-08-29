<?php

namespace App\Services;

/**
 * Turns one tab of the master screening schedule into location-shaped rows.
 *
 * The source is a working document, not an export, and it shows: the header is
 * not on row 1, the row beneath it names which staff role owns each column,
 * section banners ("AUSTRALIA", "Premiere Week") sit between the screenings, and
 * calculated columns leak #DIV/0!. Everything here exists to separate the real
 * screenings from that.
 *
 * Columns are located by header name, never by position. Across the twenty tabs
 * in the ANZ schedule there are eighteen different column layouts, so a
 * positional mapping would quietly write a cinema name into a status field.
 */
class SheetRowMapper
{
    /**
     * Canonical field => the header spellings seen across the real tabs.
     *
     * Matched exactly after normalisation, longest alias first, so 'Cinema' can
     * never swallow 'Cinema Contact (Current)'.
     */
    public const ALIASES = [
        'cinema_contact' => ['Cinema Contact (Current)', 'Cinema Contact'],
        'film' => ['Film'],
        'location' => ['Location'],
        'cinema' => ['Cinema'],
        'country' => ['Country', 'Country/State', 'Country/ State'],
        'state' => ['State'],
        'date' => ['Date'],
        'time' => ['Time'],
        'number_of_screenings' => ['Number of Screenings'],
        'category' => ['Show Type'],
        'status' => ['Booking Status', 'Status'],
        'ticketing_type' => ['Ticketing Type'],
        'booked_by' => ['Booked By'],
        'date_booking_confirmed' => ['Date Booking Confirmed'],
        'film_format' => ['Film Format'],
        'dcp_trailer_sent' => ['DCP Trailer Sent'],
        'media_kit_sent' => ['Media Kit Sent'],
        'dcp_sent' => ['DCP Sent'],
        'specific_deliverable_requests' => ['Specific Deliverable Requests'],
    ];

    /**
     * Headers that must be present for a row to be believed to be the header row.
     * All twenty tabs carry these four.
     */
    public const HEADER_ANCHORS = ['location', 'date', 'booked_by', 'film_format'];

    /** How far down a tab to look for the header before giving up. */
    public const HEADER_SEARCH_DEPTH = 30;

    public function __construct(protected LocationValueParser $values) {}

    /**
     * Normalise a header for comparison: case, surrounding space, the escaping a
     * sheet applies to '#', and the non-breaking spaces that come from manual
     * editing all vary between tabs and none of them are meaningful.
     */
    public function normalise(string $header): string
    {
        $header = str_replace(["\xc2\xa0", '\\'], [' ', ''], $header);

        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
    }

    /**
     * Locate the header row and the column index of every field found on it.
     *
     * Returns null when no row in the search depth carries the anchors, which
     * means the tab is not a screening schedule and must not be imported.
     *
     * @param  list<list<string>>  $rows
     * @return array{index: int, columns: array<string, int>}|null
     */
    public function locateHeader(array $rows): ?array
    {
        $limit = min(count($rows), self::HEADER_SEARCH_DEPTH);

        for ($i = 0; $i < $limit; $i++) {
            $columns = $this->columnsFor($rows[$i]);

            $hasAnchors = ! array_diff(self::HEADER_ANCHORS, array_keys($columns));

            if ($hasAnchors) {
                return ['index' => $i, 'columns' => $columns];
            }
        }

        return null;
    }

    /**
     * Map one header row to field => column index.
     *
     * Several tabs repeat a header ('AE%', 'Capacity', 'Hire Cost of Venue'
     * appear twice); the first occurrence wins, which is the one inside the
     * screening block rather than the later financial summary.
     *
     * @param  list<string>  $headerRow
     * @return array<string, int>
     */
    protected function columnsFor(array $headerRow): array
    {
        $normalised = array_map(fn ($h) => $this->normalise($h), $headerRow);
        $columns = [];

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($this->normalise($alias), $normalised, true);

                if ($index !== false) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * Read the raw cells of one row into canonical fields, untouched.
     *
     * @param  list<string>  $row
     * @param  array<string, int>  $columns
     * @return array<string, string>
     */
    public function rawFields(array $row, array $columns): array
    {
        $out = [];

        foreach ($columns as $field => $index) {
            $value = trim($row[$index] ?? '');

            $out[$field] = $this->values->isFormulaError($value) ? '' : $value;
        }

        return $out;
    }

    /**
     * Why this row is not a screening, or null if it is one.
     *
     * The decisive test is the date. A real screening carries a parseable date or
     * the literal TBA; the role row ('Booker'), the section banners ('Premiere
     * Week') and the blank spacers never do. Rows are reported with their reason
     * rather than dropped silently, so the review screen can show exactly what
     * was left behind.
     *
     * @param  array<string, string>  $fields
     */
    public function rejectionReason(array $fields): ?string
    {
        $filled = count(array_filter($fields, fn ($v) => $v !== ''));

        if ($filled === 0) {
            return 'Blank row';
        }

        // A banner spans the sheet visually but fills only the one cell it is
        // written in.
        if ($filled <= 2) {
            return 'Section heading, not a screening';
        }

        if (($fields['location'] ?? '') === '') {
            return 'No location';
        }

        $date = $fields['date'] ?? '';

        if ($date === '') {
            return 'No date — not booked in yet';
        }

        if ($this->values->parseDate($date) === null) {
            // Catches the role row, where every column reads 'Booker' or
            // 'Tour Manager', as well as any other prose in the date column.
            return "Date column reads \"{$date}\" — not a date";
        }

        // A per-country subtotal ("AUSTRALIA", 31 screenings, the date the leg
        // opens) survives every test above: it has a location, and its date is
        // real. What it does not have is a venue or a start time, because it is
        // not a screening — every actual screening in the schedule says both
        // where and when. Without this the subtotal is imported as a location
        // named after the country.
        if (($fields['cinema'] ?? '') === '' && ($fields['time'] ?? '') === '') {
            return 'Section subtotal, not a screening';
        }

        return null;
    }

    /**
     * The Location attribute payload for a screening row.
     *
     * Six columns only — cinema and location (composed into the name), state,
     * country, date, time and show type. These are what a sign-up form shows;
     * everything else on the tab belongs to the master sheet page and is read
     * but not written. See the note at the end of this method.
     *
     * Only fields present on this tab are returned. A tab with no Time column
     * must leave the existing time alone rather than blanking it.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public function toLocationAttributes(array $fields): array
    {
        $attributes = [];

        $has = fn (string $f) => array_key_exists($f, $fields);

        // 'Country/State' holds whichever the tab tracks, so which of the two a
        // cell means has to be worked out rather than assumed by column.
        ['state' => $state, 'country' => $country] = $this->values->splitRegion(
            $fields['country'] ?? '',
            $fields['state'] ?? ''
        );

        $attributes['name'] = $this->values->composeName(
            $fields['location'] ?? '',
            $state,
            $fields['cinema'] ?? ''
        );

        $attributes['date'] = $this->values->parseDate($fields['date'] ?? null);

        // locations.time is NOT NULL, and a screening is routinely booked before
        // its start time is settled — the schedule leaves the cell empty. 'TBA'
        // is what the rest of the application already stores for that.
        //
        // Only written when the tab actually has a Time column: on a tab without
        // one, an absent value means "this sheet does not track times", and
        // blanking real times to TBA would be a loss.
        if ($has('time')) {
            $attributes['time'] = $this->values->parseTime($fields['time']) ?? 'TBA';
        }

        if ($state !== '') {
            $attributes['state'] = $state;
        }

        if ($country !== '') {
            $attributes['country'] = $country;
        }

        // Show type is the last of the six columns this sync owns: cinema and
        // location (folded into the name), state, country, date, time, show type.
        if ($has('category') && $fields['category'] !== '') {
            $attributes['category'] = $fields['category'];
        }

        // Deliberately stops here.
        //
        // The tab also carries booking status, ticketing type, booked by, film
        // format, cinema contact, deliverable requests, no. of screenings, date
        // booking confirmed and the three DCP checkboxes. Those belong to the
        // master sheet page, which is a later feature — nothing in the app reads
        // them from here yet.
        //
        // Importing them anyway had a cost with no benefit: they are the columns
        // a working spreadsheet churns through all week, so every touched cell
        // became a screening "changed in the sheet" and an alert to approve. The
        // six below are the ones a sign-up form actually shows, and they barely
        // move once a screening is booked.
        //
        // Locations already holding values in those columns keep them untouched —
        // this only narrows what the sheet writes, it never blanks anything.

        return $attributes;
    }
}
