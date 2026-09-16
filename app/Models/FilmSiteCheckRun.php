<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One comparison of a film site against the Win App — the log row behind the
 * dashboard. `items` holds every compared screening with its severity.
 */
class FilmSiteCheckRun extends Model
{
    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CLI = 'cli';

    /** Runs older than this are pruned after each new run. */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'film_site_check_id',
        'status',
        'error',
        'trigger',
        'triggered_by_user_id',
        'matched',
        'errors',
        'warnings',
        'notices',
        'new_issues',
        'resolved_issues',
        'items',
        'meta',
        'duration_ms',
    ];

    protected $casts = [
        'items' => 'array',
        'meta' => 'array',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(FilmSiteCheck::class, 'film_site_check_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
