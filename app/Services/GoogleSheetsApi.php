<?php

namespace App\Services;

use App\Exceptions\GoogleOAuthException;
use App\Exceptions\GoogleSheetsException;
use App\Models\GoogleConnection;
use App\Models\SheetSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads cell values from the Google Sheets API.
 *
 * Deliberately not the google/apiclient package — that pulls in some fifty
 * transitive dependencies to wrap what is, for a read, one authenticated GET.
 * This application's composer.json is lean and reading values is the only thing
 * ever asked of it.
 */
class GoogleSheetsApi
{
    public const BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function __construct(protected GoogleOAuth $oauth) {}

    /**
     * The connection the sync reads as, refreshed if its access token has aged
     * out. Returns null when Google has never been connected or the connection
     * needs a human to reauthorize.
     *
     * @throws GoogleOAuthException
     */
    public function connection(): ?GoogleConnection
    {
        $connection = GoogleConnection::where('purpose', GoogleConnection::PURPOSE_SHEETS)->first();

        if (! $connection || $connection->needsReconnect()) {
            return null;
        }

        return $connection->tokenIsStale()
            ? $this->oauth->refresh($connection)
            : $connection;
    }

    /**
     * Fetch a rectangular block of cells in A1 notation, e.g. 'Bookings!A1:Z500'.
     *
     * Returns raw rows exactly as the API gives them: a list of lists of strings,
     * the first being whatever the range started on. Two shapes matter to callers
     * and are normalised here:
     *
     *  - Google truncates each row at its last non-empty cell, so rows are ragged
     *    and a row of all-empty trailing columns comes back short.
     *  - A range covering entirely empty cells omits 'values' altogether.
     *
     * Rows are padded to the width of the header row so column offsets line up.
     *
     * @return list<list<string>>
     *
     * @throws GoogleSheetsException
     */
    public function values(string $spreadsheetId, string $range): array
    {
        $connection = $this->connection();

        if (! $connection) {
            throw new GoogleSheetsException(
                'No active Google connection. Connect a Google account that can see the master sheet.'
            );
        }

        $url = self::BASE_URL.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range);

        $response = Http::withToken($connection->access_token)
            ->timeout(60)
            ->get($url, [
                // Dates and numbers arrive as the strings a human sees in the
                // sheet rather than Sheets' internal serial numbers, which is what
                // the importer's parsing expects.
                'valueRenderOption' => 'FORMATTED_VALUE',
                'dateTimeRenderOption' => 'FORMATTED_STRING',
                // Without this a range starting mid-sheet would come back
                // transposed relative to how it reads on screen.
                'majorDimension' => 'ROWS',
            ]);

        if ($response->failed()) {
            Log::error('Google Sheets read failed', [
                'spreadsheet_id' => $spreadsheetId,
                'range' => $range,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new GoogleSheetsException($this->readErrorMessage($response->status(), $range));
        }

        $rows = $response->json('values') ?? [];

        if ($rows === []) {
            return [];
        }

        $width = max(array_map('count', $rows));

        return array_map(
            fn (array $row) => array_pad(array_map(fn ($cell) => (string) $cell, $row), $width, ''),
            $rows
        );
    }

    /**
     * Which cells of the given columns are struck through.
     *
     * Strikethrough is how the schedule says a screening is off — the row is left
     * in place as a record and drawn through. It is formatting, not a value, so
     * the values endpoint cannot see it and a second read is unavoidable.
     *
     * Only the named columns are asked for. The same read across the whole tab is
     * seventeen megabytes, because the API emits 'strikethrough: false' for every
     * cell of every one of the seventy-eight columns; three single-column ranges
     * are a few hundred kilobytes and answer the same question.
     *
     * Indices are 0-based and line up with the rows values() returns, both ranges
     * starting at row 1. A row or column the response stops short of is simply
     * not struck.
     *
     * @param  list<int>  $columns  0-based column indexes
     * @return array<int, array<int, bool>> row index => column index => struck
     *
     * @throws GoogleSheetsException
     */
    public function strikethrough(string $spreadsheetId, SheetSource $source, array $columns): array
    {
        $columns = array_values(array_unique($columns));

        if ($columns === []) {
            return [];
        }

        $connection = $this->connection();

        if (! $connection) {
            throw new GoogleSheetsException(
                'No active Google connection. Connect a Google account that can see the master sheet.'
            );
        }

        $ranges = array_map(
            fn (int $c) => $source->rangeFor(self::columnLetter($c).':'.self::columnLetter($c)),
            $columns
        );

        // Built by hand because the ranges repeat: Google wants 'ranges=A:A&
        // ranges=B:B', and an array passed as a query option is encoded as
        // 'ranges[0]=', which it rejects as malformed.
        $query = implode('&', array_map(fn (string $r) => 'ranges='.rawurlencode($r), $ranges))
            .'&includeGridData=true'
            .'&fields='.rawurlencode('sheets.data.rowData.values.effectiveFormat.textFormat.strikethrough');

        $response = Http::withToken($connection->access_token)
            ->timeout(60)
            ->get(self::BASE_URL.'/'.rawurlencode($spreadsheetId).'?'.$query);

        if ($response->failed()) {
            Log::error('Google Sheets formatting read failed', [
                'spreadsheet_id' => $spreadsheetId,
                'ranges' => $ranges,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new GoogleSheetsException($this->readErrorMessage($response->status(), implode(', ', $ranges)));
        }

        $struck = [];

        // One data block per range, in the order they were asked for.
        foreach ($response->json('sheets.0.data') ?? [] as $i => $block) {
            $column = $columns[$i] ?? null;

            if ($column === null) {
                continue;
            }

            foreach ($block['rowData'] ?? [] as $rowIndex => $row) {
                $struck[$rowIndex][$column] =
                    (bool) ($row['values'][0]['effectiveFormat']['textFormat']['strikethrough'] ?? false);
            }
        }

        return $struck;
    }

    /** 0-based column index to its A1 letters: 0 => A, 26 => AA. */
    public static function columnLetter(int $index): string
    {
        $letters = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letters = chr(65 + $i % 26).$letters;
        }

        return $letters;
    }

    /**
     * A spreadsheet's own title and the names of its tabs. Used by the settings
     * screen so an admin picks a tab from a list instead of typing A1 notation
     * from memory.
     *
     * Only the two title fields are requested — the default response for a
     * twenty-tab workbook carries every sheet's grid properties, formatting and
     * conditional rules, which is megabytes to learn twenty strings.
     *
     * @return array{title: string, tabs: list<string>}
     *
     * @throws GoogleSheetsException
     */
    public function metadata(string $spreadsheetId): array
    {
        $connection = $this->connection();

        if (! $connection) {
            throw new GoogleSheetsException(
                'No active Google connection. Connect a Google account that can see the master sheet.'
            );
        }

        $response = Http::withToken($connection->access_token)
            ->timeout(30)
            ->get(self::BASE_URL.'/'.rawurlencode($spreadsheetId), [
                'fields' => 'properties.title,sheets.properties.title',
            ]);

        if ($response->failed()) {
            Log::error('Google Sheets metadata read failed', [
                'spreadsheet_id' => $spreadsheetId,
                'status' => $response->status(),
            ]);

            throw new GoogleSheetsException($this->readErrorMessage($response->status(), null));
        }

        return [
            'title' => (string) ($response->json('properties.title') ?? ''),
            'tabs' => array_values(array_filter(array_map(
                fn (array $sheet) => $sheet['properties']['title'] ?? null,
                $response->json('sheets') ?? []
            ))),
        ];
    }

    /**
     * Google's own error bodies are unhelpful to an admin ("Requested entity was
     * not found"), so each status is turned into the thing they would actually
     * need to go and change.
     */
    protected function readErrorMessage(int $status, ?string $range): string
    {
        return match ($status) {
            401 => 'Google rejected the stored credentials. Reconnect the Google account.',
            403 => 'The connected Google account cannot open this spreadsheet. Check it is shared with that account, and that the Google Sheets API is enabled on the Cloud project.',
            404 => 'No spreadsheet with that id. Check GOOGLE_MASTER_SHEET_ID matches the id in the sheet URL.',
            400 => $range
                ? "Google could not read the range '{$range}'. Check the tab name matches the sheet exactly, including spaces."
                : 'Google rejected the request as malformed.',
            429 => 'Google rate-limited the request. The next scheduled run will retry.',
            default => 'Google Sheets returned HTTP '.$status.'.',
        };
    }
}
