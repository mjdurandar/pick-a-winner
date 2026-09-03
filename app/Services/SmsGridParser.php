<?php

namespace App\Services;

/**
 * Turns a paste of the SMS planning sheet into rows.
 *
 * The sheet is a hand-kept Google Sheet, so this expects noise: banner rows
 * above the header, blank filler rows, and columns in whatever order they were
 * last dragged to. Columns are found by header name, never by position, and
 * the header row is wherever the known headings first appear together.
 */
class SmsGridParser
{
    /**
     * Column keys and the header text that identifies each. Matching is
     * case-insensitive substring, so "Bitly Ticket Link" matches 'ticket link'.
     */
    protected const COLUMNS = [
        'year' => ['year'],
        'film_tour' => ['film tour', 'tour'],
        'location' => ['location'],
        'screening_date' => ['screening date', 'screening'],
        'tag' => ['tag'],
        'trigger_event' => ['trigger event', 'trigger'],
        'send_date' => ['send date'],
        'sheet_status' => ['status'],
        'sms_text' => ['sms text', 'message'],
        'link' => ['bitly', 'ticket link'],
        'mailchimp_link' => ['mailchimp sms link', 'mailchimp link'],
        'data_count' => ['data count'],
    ];

    /** The sheet's column order, used when a paste carries no heading row. */
    public const DEFAULT_ORDER = [
        'year' => 0, 'film_tour' => 1, 'location' => 2, 'screening_date' => 3, 'tag' => 4,
        'trigger_event' => 5, 'send_date' => 6, 'sheet_status' => 7, 'sms_text' => 8,
        'link' => 9, 'mailchimp_link' => 10, 'data_count' => 11,
    ];

    /** Without these the paste is not the sheet (or is only half of it). */
    protected const REQUIRED = ['film_tour', 'location', 'send_date', 'tag', 'sms_text'];

    /**
     * @return array{rows: array, errors: array, columns: array}
     */
    public function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $grid = array_map(fn ($line) => array_map('trim', explode("\t", $line)), $lines);

        [$headerIndex, $columns] = $this->locateHeader($grid);

        // No headings pasted — rows copied straight out of the body of the
        // sheet. Assume the sheet's own column order, which is stable.
        if ($headerIndex === null) {
            $widest = max(array_map('count', $grid) ?: [0]);
            if ($widest < 9) {
                return ['rows' => [], 'columns' => [], 'errors' => [
                    'Could not read that as sheet rows. Copy whole rows (Year through Data Count), with or without the heading row.',
                ]];
            }
            $headerIndex = -1;
            $columns = self::DEFAULT_ORDER;
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ($missing) {
            $labels = implode(', ', array_map(fn ($k) => ucwords(str_replace('_', ' ', $k)), $missing));

            return ['rows' => [], 'columns' => $columns, 'errors' => [
                "The paste is missing these columns: {$labels}. Select the whole row across both halves of the sheet before copying.",
            ]];
        }

        $rows = [];
        $errors = [];

        foreach (array_slice($grid, $headerIndex + 1, null, true) as $lineNo => $cells) {
            $cell = fn (string $key) => isset($columns[$key]) ? ($cells[$columns[$key]] ?? '') : '';

            // Filler rows: the sheet has a few with only the Year dropdown set.
            if ($cell('film_tour') === '' && $cell('location') === '' && $cell('sms_text') === '') {
                continue;
            }

            $year = (int) preg_replace('/\D/', '', $cell('year')) ?: null;
            $sendDate = $this->parseDate($cell('send_date'), $year);

            if ($cell('film_tour') === '' || $cell('location') === '' || $cell('sms_text') === '' || $cell('tag') === '') {
                $errors[] = 'Line '.($lineNo + 1).': missing tour, location, tag or SMS text — skipped.';

                continue;
            }

            if (! $sendDate) {
                $errors[] = 'Line '.($lineNo + 1).": could not read the send date \"{$cell('send_date')}\" — skipped.";

                continue;
            }

            $rows[] = [
                'line' => $lineNo + 1,
                'year' => $year,
                'film_tour' => $cell('film_tour'),
                'location' => $cell('location'),
                'screening_date' => $this->parseDate($cell('screening_date'), $year),
                'tag' => $cell('tag'),
                'trigger_event' => $cell('trigger_event') ?: null,
                'send_date' => $sendDate,
                'sheet_status' => $cell('sheet_status') ?: null,
                'sms_text' => $cell('sms_text'),
                'link' => $cell('link') ?: null,
                'mailchimp_campaign_id' => $this->campaignIdFromLink($cell('mailchimp_link')),
                'data_count' => (int) preg_replace('/\D/', '', $cell('data_count')) ?: null,
            ];
        }

        return ['rows' => $rows, 'columns' => $columns, 'errors' => $errors];
    }

    /**
     * The header is the first row where at least three known headings appear.
     * Returns [row index, [column key => cell index]].
     */
    protected function locateHeader(array $grid): array
    {
        foreach ($grid as $i => $cells) {
            $columns = [];
            foreach ($cells as $idx => $cell) {
                $needle = mb_strtolower(trim($cell));
                if ($needle === '') {
                    continue;
                }
                foreach (self::COLUMNS as $key => $aliases) {
                    if (isset($columns[$key])) {
                        continue;
                    }
                    foreach ($aliases as $alias) {
                        if (str_contains($needle, $alias)) {
                            $columns[$key] = $idx;

                            continue 3;
                        }
                    }
                }
            }
            if (count($columns) >= 3) {
                return [$i, $columns];
            }
        }

        return [null, []];
    }

    /**
     * "Tue, 1 Sep 2026", "Wednesday, 2 September" (year from the Year column),
     * "2026-09-01", "01/09/2026" (day first, this is Australia).
     *
     * Explicit formats rather than a free parser: a free parser reads "TBC" or
     * a stray word as today, and a wrong send date is worse than a rejected one.
     */
    public function parseDate(string $value, ?int $year): ?string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        // Drop a leading weekday ("Wednesday, " / "Tue, ") — it only confuses
        // parsers when it disagrees with the date, which hand-edited sheets do.
        $clean = preg_replace('/^(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*,?\s+/i', '', $value);
        $clean = preg_replace('/\s+/', ' ', $clean);

        // A date with no year takes the row's Year column.
        if ($year && ! preg_match('/\b(19|20)\d{2}\b/', $clean)) {
            $clean .= " {$year}";
        }

        foreach (['!j F Y', '!j M Y', '!F j Y', '!M j Y', '!Y-m-d', '!d/m/Y', '!j/n/Y', '!j-M-Y', '!j F, Y', '!M j, Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $clean);
            $errors = \DateTime::getLastErrors();
            if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** The campaign id out of a pasted ".../sms/bulk?id=<uuid>" link. */
    protected function campaignIdFromLink(string $link): ?string
    {
        if (preg_match('/[?&]id=([0-9a-f-]{36})/i', $link, $m)) {
            return $m[1];
        }

        return null;
    }
}
