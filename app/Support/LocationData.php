<?php

namespace App\Support;

use App\Models\Events;
use App\Models\Location;
use App\Models\Prize;
use App\Models\TicketAttendee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What a location is holding, for the decisions that would destroy it.
 *
 * Three things hang off a screening, and they do not behave the same way when
 * it is deleted:
 *
 *   ticket attendees - cascade: destroyed with it
 *   prizes           - cascade: destroyed with it
 *   sign-ups         - set null: kept, but detached from any screening, which
 *                      is how a form entry stops being attributable to the
 *                      night it was taken at
 *
 * Sign-ups live in the event's own table, named by its sign-up form, so they
 * take a different query from the other two — which is why they were not being
 * counted at all, and a screening with fifty of them read as empty.
 */
class LocationData
{
    /**
     * Counts per location id, for locations of one event.
     *
     * @param  array<int, int>  $locationIds
     * @return array<int, array{attendees: int, prizes: int, signups: int}>
     */
    public static function forEvent(?Events $event, array $locationIds): array
    {
        $locationIds = array_values(array_unique(array_filter($locationIds)));

        if ($locationIds === []) {
            return [];
        }

        $counts = fn ($query) => $query->whereIn('location_id', $locationIds)
            ->selectRaw('location_id, COUNT(*) as total')
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        $attendees = $counts(TicketAttendee::query());
        $prizes = $counts(Prize::query());
        $signups = self::signupCounts($event, $locationIds);

        $out = [];

        foreach ($locationIds as $id) {
            $out[$id] = [
                'attendees' => (int) ($attendees[$id] ?? 0),
                'prizes' => (int) ($prizes[$id] ?? 0),
                'signups' => (int) ($signups[$id] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array{attendees: int, prizes: int, signups: int} */
    public static function forLocation(Location $location): array
    {
        return self::forEvent($location->event, [$location->id])[$location->id]
            ?? ['attendees' => 0, 'prizes' => 0, 'signups' => 0];
    }

    /** @param  array{attendees: int, prizes: int, signups: int}  $counts */
    public static function isEmpty(array $counts): bool
    {
        return ($counts['attendees'] ?? 0) === 0
            && ($counts['prizes'] ?? 0) === 0
            && ($counts['signups'] ?? 0) === 0;
    }

    /**
     * Readable list of what a location holds, e.g. "12 sign-ups and 3 prizes".
     *
     * @param  array{attendees: int, prizes: int, signups: int}  $counts
     */
    public static function describe(array $counts): string
    {
        $parts = [];

        foreach ([
            'signups' => 'sign-up',
            'attendees' => 'ticket attendee',
            'prizes' => 'prize',
        ] as $key => $noun) {
            $n = (int) ($counts[$key] ?? 0);

            if ($n > 0) {
                $parts[] = $n.' '.$noun.($n === 1 ? '' : 's');
            }
        }

        if ($parts === []) {
            return 'nothing';
        }

        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }

    /**
     * Sign-ups per location, from the event's own form table.
     *
     * The table is named by the sign-up form and may not exist — an event that
     * never had a form, or one whose table was dropped. A count that cannot be
     * taken must read as unknown rather than as zero, because zero is what
     * permits a deletion.
     *
     * @param  array<int, int>  $locationIds
     * @return array<int, int>
     */
    protected static function signupCounts(?Events $event, array $locationIds): array
    {
        $table = $event?->signUpForm?->table_name;

        if (! $table) {
            return [];
        }

        try {
            if (! Schema::hasTable($table)) {
                return [];
            }

            return DB::table($table)
                ->whereIn('location_id', $locationIds)
                ->selectRaw('location_id, COUNT(*) as total')
                ->groupBy('location_id')
                ->pluck('total', 'location_id')
                ->all();
        } catch (Throwable $e) {
            Log::warning('Could not count sign-ups for a location', [
                'event_id' => $event?->id,
                'table' => $table,
                'message' => $e->getMessage(),
            ]);

            // Unknown, not none. Reported as held so nothing is deleted on the
            // strength of a count that failed.
            return array_fill_keys($locationIds, 1);
        }
    }
}
