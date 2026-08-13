<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt at the hosted signup form, and the rule that reads a history of them.
 *
 * Mailchimp publishes no rate limit for that form. Rather than guess one, each
 * attempt is treated as an experiment: it relays at the pacing the last attempt
 * arrived at, stops the moment the form pushes back, and records how far it got.
 * plan() turns that history into the next attempt's pacing, so the schedule finds
 * the real limit by walking towards it instead of being told.
 */
class MailchimpFormRelayRun extends Model
{
    use HasFactory;

    /** Where a fresh account starts: deliberately timid, since nothing is known yet. */
    public const OPENING_INTERVAL_SECONDS = 30;

    public const OPENING_BATCH = 8;

    /** Bounds, so a long run of either outcome cannot walk somewhere absurd. */
    private const MIN_INTERVAL_SECONDS = 5;

    private const MAX_INTERVAL_SECONDS = 900;

    private const MIN_BATCH = 1;

    private const MAX_BATCH = 60;

    /** Cooldown after an attempt that never tripped the limit. */
    private const CLEAR_RUN_COOLDOWN_MINUTES = 30;

    /** First cooldown after being throttled; it doubles while throttling repeats. */
    private const THROTTLED_COOLDOWN_MINUTES = 60;

    private const MAX_COOLDOWN_MINUTES = 1440;

    protected $fillable = [
        'account',
        'started_at',
        'finished_at',
        'interval_seconds',
        'planned',
        'accepted',
        'throttled',
        'next_attempt_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'throttled' => 'boolean',
    ];

    public static function latestFor(string $account): ?self
    {
        return static::where('account', $account)->latest('started_at')->first();
    }

    /**
     * What the next attempt for this account should try, and whether it may start.
     *
     * @return array{interval: int, batch: int, may_run: bool, next_attempt_at: ?\Illuminate\Support\Carbon}
     */
    public static function plan(string $account): array
    {
        $last = static::latestFor($account);

        if (! $last) {
            return [
                'interval' => self::OPENING_INTERVAL_SECONDS,
                'batch' => self::OPENING_BATCH,
                'may_run' => true,
                'next_attempt_at' => null,
            ];
        }

        return [
            'interval' => (int) $last->interval_seconds,
            'batch' => (int) $last->planned,
            'may_run' => $last->next_attempt_at === null || $last->next_attempt_at->isPast(),
            'next_attempt_at' => $last->next_attempt_at,
        ];
    }

    /**
     * Close this attempt out and decide what the next one does.
     *
     * Throttled: aim below whatever tripped it and wait longer — and if the form
     * pushed back before a single contact got through, the last cooldown was not
     * long enough, so it doubles.
     *
     * Clear: nothing was refused, so the limit is somewhere above what was just
     * done. Edge towards it — a little more per attempt, a little less waiting
     * between contacts — rather than jumping and losing the good pacing.
     */
    public function settle(int $accepted, bool $throttled): void
    {
        $interval = (int) $this->interval_seconds;
        $batch = (int) $this->planned;
        $cooldown = self::CLEAR_RUN_COOLDOWN_MINUTES;

        if ($throttled) {
            $batch = max(self::MIN_BATCH, $accepted > 0 ? $accepted - 1 : 1);

            // Where the form pushed back says which knob was wrong. Late in the
            // attempt means the count was too high and the pacing was fine — the
            // interval must not creep up for that, or it inflates a little on every
            // cycle and the schedule ends up crawling for no reason. Early means the
            // submissions themselves were too close together.
            if ($accepted * 2 < $batch) {
                $interval = min(self::MAX_INTERVAL_SECONDS, (int) ceil($interval * 1.5));
            }

            $cooldown = $accepted === 0
                ? min(self::MAX_COOLDOWN_MINUTES, $this->lastCooldownMinutes() * 2)
                : self::THROTTLED_COOLDOWN_MINUTES;
        } elseif ($accepted >= $batch) {
            // Only widen when the attempt actually did everything it set out to do;
            // a short run means it ran out of contacts, which says nothing about the
            // limit.
            $batch = min(self::MAX_BATCH, $batch + 2);
            $interval = max(self::MIN_INTERVAL_SECONDS, (int) floor($interval * 0.8));
        }

        $this->update([
            'accepted' => $accepted,
            'throttled' => $throttled,
            'finished_at' => now(),
            'next_attempt_at' => now()->addMinutes($cooldown),
        ]);

        // Carried on the row the next plan() will read.
        $this->forceFill(['interval_seconds' => $interval, 'planned' => $batch])->save();
    }

    /**
     * How long the previous attempt waited before this one, so a repeat throttle can
     * double it rather than restarting from the opening value.
     */
    protected function lastCooldownMinutes(): int
    {
        $previous = static::where('account', $this->account)
            ->where('id', '<', $this->id)
            ->latest('started_at')
            ->first();

        if (! $previous || ! $previous->next_attempt_at || ! $previous->finished_at) {
            return self::THROTTLED_COOLDOWN_MINUTES;
        }

        return max(
            self::THROTTLED_COOLDOWN_MINUTES,
            (int) $previous->finished_at->diffInMinutes($previous->next_attempt_at),
        );
    }
}
