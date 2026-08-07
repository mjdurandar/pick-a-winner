<?php

namespace App\Services;

use App\Exceptions\CsvImportException;
use Generator;

/**
 * Server-side CSV reading for the import wizard.
 *
 * Rows are streamed with a generator rather than read into an array — a 10 MB
 * file can hold well over a hundred thousand rows, and both the dry run and the
 * import job walk the file rather than holding it in memory.
 */
class CsvImportParser
{
    /**
     * Header labels are used as the keys of the saved field map, so they have to be
     * unique. Blank and duplicated headers are made unique here and everything
     * downstream — the mapping UI, the map itself, the row reader — agrees on them.
     *
     * @return array{headers: array<int, string>, row_count: int}
     *
     * @throws CsvImportException
     */
    public function inspect(string $path): array
    {
        $handle = $this->open($path);

        try {
            $headers = $this->readHeaders($handle);

            $rowCount = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if ($this->isBlank($row)) {
                    continue;
                }
                $rowCount++;
            }

            return ['headers' => $headers, 'row_count' => $rowCount];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Yields [row number, values keyed by header]. Row numbers are 1-based and skip
     * the header line, so they line up with what the admin sees in a spreadsheet
     * minus the header — the same numbering the report and the export use.
     *
     * @return Generator<int, array{0: int, 1: array<string, string>}>
     *
     * @throws CsvImportException
     */
    public function rows(string $path): Generator
    {
        $handle = $this->open($path);

        try {
            $headers = $this->readHeaders($handle);
            $width = count($headers);
            $rowNumber = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if ($this->isBlank($row)) {
                    continue;
                }

                $rowNumber++;

                // Short rows are padded and long rows truncated so every row has
                // exactly the header columns; a ragged file must not shift values
                // into the wrong field.
                $values = array_slice(array_pad($row, $width, null), 0, $width);

                yield [$rowNumber, array_combine($headers, array_map(
                    fn ($value) => trim((string) $value),
                    $values
                ))];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     *
     * @throws CsvImportException
     */
    protected function open(string $path)
    {
        if (! is_readable($path)) {
            throw new CsvImportException('The uploaded file could not be read.');
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new CsvImportException('The uploaded file could not be opened.');
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>
     *
     * @throws CsvImportException
     */
    protected function readHeaders($handle): array
    {
        $first = fgetcsv($handle);

        if ($first === false || $this->isBlank($first)) {
            throw new CsvImportException('This file has no header row. The first row must name the columns.');
        }

        // Excel writes a UTF-8 BOM that would otherwise become part of the first
        // header's name and break auto-detection of the email column.
        $first[0] = preg_replace('/^\x{FEFF}/u', '', (string) $first[0]);

        foreach ($first as $cell) {
            if (filter_var(trim((string) $cell), FILTER_VALIDATE_EMAIL)) {
                throw new CsvImportException(
                    'The first row looks like data, not column names. Add a header row naming each column.'
                );
            }
        }

        $headers = [];
        $seen = [];

        foreach ($first as $index => $cell) {
            $label = trim((string) $cell);

            if ($label === '') {
                $label = 'Column '.($index + 1);
            }

            // "email, email" would collapse to one key in the field map, silently
            // dropping a column. Suffix repeats instead.
            $key = strtolower($label);
            if (isset($seen[$key])) {
                $seen[$key]++;
                $label .= ' ('.$seen[$key].')';
            } else {
                $seen[$key] = 1;
            }

            $headers[] = $label;
        }

        return $headers;
    }

    /**
     * fgetcsv returns [null] for a blank line, and trailing newlines are common.
     *
     * @param  array<int, string|null>  $row
     */
    protected function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
