<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;

/**
 * When the weekly digest goes out: a weekday, a time and the timezone that
 * time is in.
 *
 * The default comes from config (WEEKLY_DIGEST_DAY / _TIME / _TIMEZONE in
 * .env); an admin can override it from the weekly report screen, and that
 * override lives in the settings table. Resetting deletes the override, so
 * the default is always one click away and is shown alongside the form.
 */
class WeeklyDigestSchedule
{
    public const SETTING_KEY = 'weekly_digest.schedule';

    public const DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * Offered in the picker. Anything PHP knows is accepted on save, so a
     * zone missing here still works — it just has to be typed.
     */
    public const TIMEZONES = [
        'UTC',
        'Australia/Sydney',
        'Australia/Melbourne',
        'Australia/Brisbane',
        'Australia/Adelaide',
        'Australia/Perth',
        'Pacific/Auckland',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'Europe/London',
    ];

    public function __construct(
        public readonly int $day,
        public readonly string $time,
        public readonly string $timezone,
    ) {}

    /** What the digest is scheduled for right now: the override, or the default. */
    public static function current(): self
    {
        $default = self::default();

        try {
            $saved = Setting::get(self::SETTING_KEY);
        } catch (\Throwable $e) {
            // A broken DB must not take the whole scheduler down with it; the
            // other jobs still need to run, and the digest on its default is
            // better than no digest.
            Log::warning('Could not read the weekly digest schedule; using the default', ['error' => $e->getMessage()]);

            return $default;
        }

        if (! is_array($saved)) {
            return $default;
        }

        return self::fromArray($saved, $default);
    }

    public static function default(): self
    {
        $config = (array) config('mail.weekly_digest_schedule', []);

        return self::fromArray($config, new self(1, '08:00', config('app.timezone', 'UTC')));
    }

    public static function isOverridden(): bool
    {
        return is_array(Setting::get(self::SETTING_KEY));
    }

    public static function save(int $day, string $time, string $timezone): self
    {
        $schedule = new self($day, self::normaliseTime($time), $timezone);

        Setting::set(self::SETTING_KEY, $schedule->toArray());

        return $schedule;
    }

    public static function reset(): self
    {
        Setting::forget(self::SETTING_KEY);

        return self::default();
    }

    /** Apply this schedule to a scheduler entry. */
    public function apply(Event $event): Event
    {
        return $event->weeklyOn($this->day, $this->time)->timezone($this->timezone);
    }

    /** The next moment this schedule fires, in its own timezone. */
    public function nextRunAt(): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->time));

        $now = CarbonImmutable::now($this->timezone);
        $next = $now->setTime($hour, $minute);

        // Carbon's dayOfWeek is Sunday = 0, same as cron and self::DAYS.
        $daysAhead = ($this->day - $now->dayOfWeek + 7) % 7;
        $next = $next->addDays($daysAhead);

        if ($next->lessThanOrEqualTo($now)) {
            $next = $next->addWeek();
        }

        return $next;
    }

    /** "Monday at 08:00 (Australia/Sydney)". */
    public function describe(): string
    {
        return sprintf('%s at %s (%s)', self::DAYS[$this->day], $this->time, $this->timezone);
    }

    /** @return array{day: int, time: string, timezone: string} */
    public function toArray(): array
    {
        return ['day' => $this->day, 'time' => $this->time, 'timezone' => $this->timezone];
    }

    /** What the screen shows: the values, the words, and when it next fires. */
    public function toResponse(): array
    {
        return $this->toArray() + [
            'label' => $this->describe(),
            'next_run_at' => $this->nextRunAt()->toIso8601String(),
            'next_run_label' => $this->nextRunAt()->format('D j M Y, H:i').' '.$this->timezone,
        ];
    }

    /**
     * Build from loosely-typed input, falling back field by field: a saved row
     * or .env line with one bad value should not throw away the good ones.
     */
    protected static function fromArray(array $values, self $fallback): self
    {
        $day = filter_var($values['day'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        $time = isset($values['time']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $values['time'])
            ? self::normaliseTime((string) $values['time'])
            : null;
        $timezone = isset($values['timezone']) && in_array((string) $values['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? (string) $values['timezone']
            : null;

        if ($time !== null && ((int) explode(':', $time)[0] > 23 || (int) explode(':', $time)[1] > 59)) {
            $time = null;
        }

        return new self(
            $day === false ? $fallback->day : $day,
            $time ?? $fallback->time,
            $timezone ?? $fallback->timezone,
        );
    }

    /** "8:00" and "08:00" mean the same thing; store one spelling. */
    protected static function normaliseTime(string $time): string
    {
        [$hour, $minute] = explode(':', $time);

        return sprintf('%02d:%02d', (int) $hour, (int) $minute);
    }
}
