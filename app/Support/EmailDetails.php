<?php

namespace App\Support;

use App\Models\SheetSourceChange;
use App\Services\FilmSiteComparisonService;
use Carbon\CarbonImmutable;

/**
 * Spells out a film site difference or a parked master sheet row in full, for
 * the alert emails and the weekly digest.
 *
 * The emails are meant to be read on their own — everything someone needs to
 * decide what to fix is in the message, not behind a link to the app.
 */
class EmailDetails
{
    /**
     * One film site comparison item, with both sides written out.
     *
     * @param  array<string, mixed>  $item  a row of FilmSiteCheckRun::items
     * @return array{place: string, severity: string, message: string, app: string, site: string, new: bool}
     */
    public static function siteItem(array $item): array
    {
        $app = $item['app'] ?? null;
        $site = $item['site'] ?? null;

        return [
            // The comparison lowercases place names to match on them.
            'place' => ucwords((string) ($item['place'] ?? '')) ?: '—',
            'severity' => (string) ($item['severity'] ?? ''),
            'message' => (string) ($item['message'] ?? ''),
            'app' => $app
                ? self::join([
                    $app['name'] ?? null,
                    self::date($app['date'] ?? null),
                    $app['time'] ?? null,
                    $app['status'] ?? null,
                ])
                : 'Not in the Win App',
            'site' => $site
                ? self::join([
                    $site['title'] ?? null,
                    ($site['tbc'] ?? false) ? 'date TBC' : (self::date($site['date'] ?? null) ?? 'no date published'),
                    $site['time'] ?? null,
                    $site['venue'] ?? null,
                    $site['status'] ?? null,
                ])
                : 'Not listed on the site',
            'new' => (bool) ($item['new'] ?? false),
        ];
    }

    /**
     * Errors and warnings first, then notices; matches are left out.
     *
     * @param  iterable<array<string, mixed>>  $items
     * @return array{problems: list<array<string, mixed>>, notices: list<array<string, mixed>>}
     */
    public static function siteItems(iterable $items): array
    {
        $items = collect($items);

        $problems = $items
            ->whereIn('severity', [FilmSiteComparisonService::SEVERITY_ERROR, FilmSiteComparisonService::SEVERITY_WARNING])
            ->sortBy(fn ($i) => [
                ($i['severity'] ?? '') === FilmSiteComparisonService::SEVERITY_ERROR ? 0 : 1,
                ($i['new'] ?? false) ? 0 : 1,
                $i['place'] ?? '',
            ]);

        $notices = $items
            ->where('severity', FilmSiteComparisonService::SEVERITY_NOTICE)
            ->sortBy(fn ($i) => $i['place'] ?? '');

        return [
            'problems' => $problems->map(fn ($i) => self::siteItem($i))->values()->all(),
            'notices' => $notices->map(fn ($i) => self::siteItem($i))->values()->all(),
        ];
    }

    /**
     * One parked sheet row: what it is, and every value it would write.
     *
     * @return array{action: string, label: string, lines: list<string>}
     */
    public static function sheetChange(SheetSourceChange $change): array
    {
        $diff = $change->diff ?? [];
        $movesDate = array_intersect(array_keys($diff), ['date', 'time']) !== [];

        return [
            'action' => match ($change->action) {
                SheetSourceChange::ACTION_CREATE => 'New',
                SheetSourceChange::ACTION_UPDATE => $movesDate ? 'Date moved' : 'Changed',
                default => 'Gone from sheet',
            },
            'label' => $change->label ?: 'Row '.$change->sheet_row,
            'lines' => match ($change->action) {
                SheetSourceChange::ACTION_CREATE => self::payloadLines($change->payload ?? []),
                SheetSourceChange::ACTION_UPDATE => self::diffLines($diff),
                default => self::missingLines($change),
            },
        ];
    }

    /**
     * Every parked row of a tab, date moves first, then new, then the rest.
     *
     * @return list<array{action: string, label: string, lines: list<string>}>
     */
    public static function sheetChanges(int $sheetSourceId): array
    {
        return SheetSourceChange::with('location')
            ->where('sheet_source_id', $sheetSourceId)
            ->whereIn('action', SheetSourceChange::NEEDS_REVIEW)
            ->orderBy('sheet_row')
            ->orderBy('id')
            ->get()
            ->map(fn (SheetSourceChange $c) => self::sheetChange($c))
            ->sortBy(fn ($row) => match ($row['action']) {
                'Date moved' => 0,
                'New' => 1,
                'Changed' => 2,
                default => 3,
            })
            ->values()
            ->all();
    }

    /** @return list<string> */
    protected static function payloadLines(array $payload): array
    {
        $lines = [];

        foreach (['date', 'time', 'state', 'country', 'category'] as $field) {
            $value = $field === 'date' ? self::date($payload[$field] ?? null) : ($payload[$field] ?? null);

            if (filled($value)) {
                $lines[] = self::field($field).': '.$value;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    protected static function diffLines(array $diff): array
    {
        $lines = [];

        foreach ($diff as $field => $entry) {
            // SheetSyncService::diff() writes a positional [old, new] pair.
            [$old, $new] = is_array($entry) ? [$entry[0] ?? null, $entry[1] ?? null] : [null, $entry];

            if ($field === 'date') {
                [$old, $new] = [self::date($old) ?? $old, self::date($new) ?? $new];
            }

            $lines[] = sprintf(
                '%s: %s → %s',
                self::field((string) $field),
                self::text($old) === '' ? '—' : self::text($old),
                self::text($new) === '' ? '—' : self::text($new)
            );
        }

        return $lines;
    }

    /** @return list<string> */
    protected static function missingLines(SheetSourceChange $change): array
    {
        $location = $change->location;

        if (! $location) {
            return ['No longer in the sheet. The location has already been removed from the Win App.'];
        }

        return [
            'Still in the Win App: '.self::join([
                $location->name,
                self::date($location->date),
                $location->time,
            ]),
            'The sheet no longer lists it — either cancelled or mid-edit. Nothing has been removed.',
        ];
    }

    /** "Sun 20 Sep 2026", or the raw value when it is not a date (e.g. TBA). */
    public static function date(mixed $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($raw, 0, 10))->format('D j M Y');
        } catch (\Throwable) {
            return $raw;
        }
    }

    protected static function field(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    protected static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return trim((string) $value);
    }

    /** @param  list<mixed>  $parts */
    protected static function join(array $parts): string
    {
        return implode(' · ', array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));
    }
}
