<?php

namespace App\Jobs;

use App\Exceptions\FilmSiteException;
use App\Models\FilmSiteCheck;
use App\Models\FilmSiteCheckRun;
use App\Services\FilmSiteComparisonService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Compares one film site with its event and logs the result as a run.
 *
 * Writes only film_site_check_runs and the check's last_* summary — never a
 * location, never the site.
 */
class RunFilmSiteCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A handful of REST reads, each with a 30s timeout. */
    public int $timeout = 300;

    /** Not retried: the next scheduled run is the retry, and it gets logged. */
    public int $tries = 1;

    public function __construct(
        public int $checkId,
        public string $trigger = FilmSiteCheckRun::TRIGGER_SCHEDULE,
        public ?int $userId = null,
    ) {}

    public function handle(FilmSiteComparisonService $service): void
    {
        $check = FilmSiteCheck::find($this->checkId);

        if (! $check || ($this->trigger === FilmSiteCheckRun::TRIGGER_SCHEDULE && ! $check->enabled)) {
            return;
        }

        $started = microtime(true);

        try {
            $result = $service->compare($check);
        } catch (FilmSiteException $e) {
            $this->recordFailure($check, $e->getMessage(), $started);

            return;
        } catch (\Throwable $e) {
            Log::error('Film site check threw', [
                'film_site_check_id' => $check->id,
                'message' => $e->getMessage(),
            ]);

            $this->recordFailure($check, 'The check failed unexpectedly. The error has been logged.', $started);

            return;
        }

        $items = $result['items'];
        $counts = array_count_values(array_column($items, 'severity'));

        // What changed since the last run that got far enough to compare.
        $previous = $check->runs()->where('status', '!=', FilmSiteCheck::STATUS_FAILED)->latest('id')->first();
        $previousKeys = collect($previous?->items ?? [])
            ->where('severity', '!=', FilmSiteComparisonService::SEVERITY_OK)
            ->pluck('key')
            ->flip();

        $currentKeys = [];

        foreach ($items as &$item) {
            if ($item['severity'] === FilmSiteComparisonService::SEVERITY_OK) {
                continue;
            }

            $currentKeys[$item['key']] = true;
            // The first run has nothing to compare with, so nothing is "new".
            $item['new'] = $previous !== null && ! $previousKeys->has($item['key']);
        }
        unset($item);

        $new = collect($items)->where('new', true)->count();
        $resolved = $previous ? $previousKeys->keys()->reject(fn ($k) => isset($currentKeys[$k]))->count() : 0;

        $errors = $counts[FilmSiteComparisonService::SEVERITY_ERROR] ?? 0;
        $warnings = $counts[FilmSiteComparisonService::SEVERITY_WARNING] ?? 0;

        $status = $errors > 0
            ? FilmSiteCheck::STATUS_ERRORS
            : ($warnings > 0 ? FilmSiteCheck::STATUS_WARNINGS : FilmSiteCheck::STATUS_CLEAN);

        $check->runs()->create([
            'status' => $status,
            'trigger' => $this->trigger,
            'triggered_by_user_id' => $this->userId,
            'matched' => $counts[FilmSiteComparisonService::SEVERITY_OK] ?? 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'notices' => $counts[FilmSiteComparisonService::SEVERITY_NOTICE] ?? 0,
            'new_issues' => $new,
            'resolved_issues' => $resolved,
            'items' => $items,
            'meta' => $result['meta'],
            'duration_ms' => $this->elapsed($started),
        ]);

        $check->forceFill([
            'last_checked_at' => now(),
            'last_status' => $status,
            'last_error' => null,
            'last_errors' => $errors,
            'last_warnings' => $warnings,
        ])->save();

        if ($new > 0) {
            Log::warning('Film site check found new differences', [
                'film_site_check_id' => $check->id,
                'site' => $check->label(),
                'new' => $new,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        }

        $this->prune($check);
    }

    protected function recordFailure(FilmSiteCheck $check, string $message, float $started): void
    {
        $check->runs()->create([
            'status' => FilmSiteCheck::STATUS_FAILED,
            'error' => $message,
            'trigger' => $this->trigger,
            'triggered_by_user_id' => $this->userId,
            'duration_ms' => $this->elapsed($started),
        ]);

        $check->forceFill([
            'last_checked_at' => now(),
            'last_status' => FilmSiteCheck::STATUS_FAILED,
            'last_error' => $message,
        ])->save();

        Log::warning('Film site check failed', [
            'film_site_check_id' => $check->id,
            'site' => $check->label(),
            'message' => $message,
        ]);

        $this->prune($check);
    }

    /** Only this check's own log rows, and never the newest one. */
    protected function prune(FilmSiteCheck $check): void
    {
        $check->runs()
            ->where('created_at', '<', now()->subDays(FilmSiteCheckRun::RETENTION_DAYS))
            ->where('id', '<', $check->runs()->max('id'))
            ->delete();
    }

    protected function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
