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
        'cinema_contact' => ['Cinema or Promoter Contact Email', 'Cinema Contact (Current)', 'Cinema Contact'],
        'film' => ['Film'],
        'location' => ['Show Location', 'Location'],
        'cinema' => ['Cinema Name', 'Cinema'],
        'country' => ['Country', 'Country/State', 'Country/ State'],
        'state' => ['Show Location (State)', 'State'],
        'date' => ['Screening Start Date', 'Screening Date', 'Date'],
        'time' => ['Screening Time', 'Time'],
        'number_of_screenings' => ['Number of Screenings', 'No. of Screenings', '# of Screenings', '# Screenings'],
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
     *
     * Only the two that every schedule carries and that the sync cannot work
     * without: a row with no location is nothing to name, and one with no date is
     * nothing to book. Anything more specific is a regional habit — the ANZ tabs
     * all carry Booked By and Film Format, the USA tabs carry neither — and
     * anchoring on those rejected the USA schedule outright.
     */
    public const HEADER_ANCHORS = ['location', 'date'];

    /**
     * On top of the anchors, how many known columns a row must carry to be the
     * header rather than a coincidence.
     *
     * Two columns alone are weak evidence: a summary tab can easily have a
     * "Location" and a "Date". A real schedule row maps eight or more.
     */
    public const HEADER_MIN_FIELDS = 4;

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

            if ($hasAnchors && count($columns) >= self::HEADER_MIN_FIELDS) {
                return ['index' => $i, 'columns' => $columns];
            }
        }

        return null;
    }

    /**
     * The country a section banner announces, or null if the row is not one.
     *
     * The USA workbook is not only the USA: 'USA - THEATRICAL', 'USA - HAS',
     * 'CANADA - THEATRICAL' and 'CANADA - HAS' divide it into blocks, and the
     * rows beneath a banner carry a province ('AB', 'ON') with no country column
     * to put it in. Reading the banner is the only way to know that Calgary is
     * not in the United States.
     *
     * Only asked of rows that are not screenings, so the banner text is the row's
     * whole meaning and there is nothing else it could be. 'Premiere Week' and
     * the other non-country banners name no country and leave the current one
     * standing.
     *
     * The cells beside the text are not empty: the banner is drawn as a black bar
     * by filling the rest of the row with '.', and one of them carries a running
     * total. The country is whatever the first cell that says something says.
     *
     * @param  list<string>  $row
     */
    public function sectionCountry(array $row): ?string
    {
        foreach ($row as $cell) {
            $cell = trim($cell);

            // The bar's filler, not a value.
            if ($cell === '' || preg_match('/^[.\-–—_·]+$/u', $cell)) {
                continue;
            }

            // 'CANADA - HAS' names the country up to the dash that separates it
            // from the show type; 'AUSTRALIA' has no dash and is the whole cell.
            $name = trim(preg_split('/[-–—]/u', $cell)[0]);

            return LocationValueParser::COUNTRIES[strtolower($name)] ?? null;
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

        // A cancelled screening is left in the sheet, struck through, as a record
        // of what was booked — it is not a screening to sell tickets to. One
        // already imported turns up as missing on the next run, which is a
        // question for an admin rather than something to delete here.
        if ($this->isCancelled($fields['status'] ?? '')) {
            return 'Cancelled in the sheet';
        }

        // Where the screening is: the town, the venue, or both. A row naming
        // neither cannot be given a name, and a name is the identity this sync
        // matches on. Either one alone is enough — a venue with no town still
        // says where to go, and the placeholders a booker types for the other
        // ('-', 'TBD') are the absence of a value, not one.
        $location = $this->values->blankPlaceholder($fields['location'] ?? '');
        $cinema = $this->values->blankPlaceholder($fields['cinema'] ?? '');

        if ($location === '' && $cinema === '') {
            return 'No location or cinema';
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
        // Read from the raw cells, not the placeholder-blanked ones: a subtotal
        // leaves both truly empty, while a booked screening whose venue is not
        // settled has 'TBD' typed in it, and that difference is the whole test.
        if (($fields['cinema'] ?? '') === '' && ($fields['time'] ?? '') === '') {
            return 'Section subtotal, not a screening';
        }

        return null;
    }

    /**
     * Whether a status column says the screening is off.
     *
     * Matched on the stem so the spellings a dropdown collects over the years —
     * 'Cancelled', 'Canceled', 'CANCELLED - venue closed' — all count.
     */
    public function isCancelled(?string $status): bool
    {
        return str_contains(strtolower(trim((string) $status)), 'cancel');
    }

    /**
     * The name this row would have composed before placeholders were stripped
     * from it, or null when stripping changed nothing.
     *
     * Only ever used to match, never to write. The identity of a screening is
     * its composed name, so tightening what goes into that name renames every
     * location the change touches — 'Townsville QLD - TBA' becomes 'Townsville
     * QLD'. Without this the sync sees a name it has never met, creates a second
     * location beside the first, and reports the original as dropped from the
     * sheet; approving that would leave the ticket attendees on the row nobody
     * is looking at any more. Matching the old name instead renames in place.
     *
     * @param  array<string, string>  $fields
     */
    public function priorName(array $fields): ?string
    {
        ['state' => $state] = $this->values->splitRegion(
            $fields['country'] ?? '',
            $fields['state'] ?? ''
        );

        $prior = $this->values->composeName(
            $fields['location'] ?? '',
            $state,
            $fields['cinema'] ?? ''
        );

        $current = $this->toLocationAttributes($fields)['name'];

        return $prior === $current ? null : $prior;
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
     * $defaultCountry is the country the tab's region names, used only when the
     * tab has no country column at all — the USA schedule tracks the state and
     * takes the country as read, and without this every screening it creates
     * would have a state and a blank country.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public function toLocationAttributes(array $fields, ?string $defaultCountry = null): array
    {
        $attributes = [];

        $has = fn (string $f) => array_key_exists($f, $fields);

        // 'Country/State' holds whichever the tab tracks, so which of the two a
        // cell means has to be worked out rather than assumed by column.
        //
        // Every part of the name is read through blankPlaceholder: a dash or a
        // 'TBD' typed into any of them means the value is not known, and letting
        // one through would bake it into the location's name.
        ['state' => $state, 'country' => $country] = $this->values->splitRegion(
            $this->values->blankPlaceholder($fields['country'] ?? ''),
            $this->values->blankPlaceholder($fields['state'] ?? '')
        );

        // Only when the column is absent, never when it is present and empty: a
        // blank cell on a tab that does track country is a gap in the sheet, and
        // filling it in from the tab's region would be a guess.
        if ($country === '' && ! $has('country') && $defaultCountry !== null) {
            $country = $defaultCountry;
        }

        $attributes['name'] = $this->values->composeName(
            $this->values->blankPlaceholder($fields['location'] ?? ''),
            $state,
            $this->values->blankPlaceholder($fields['cinema'] ?? '')
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
