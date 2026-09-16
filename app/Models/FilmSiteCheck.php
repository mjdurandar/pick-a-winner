<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One film site (region + season) compared against one event's locations.
 *
 * Read-only in both directions: a run fetches the site's public REST API and
 * reads locations, and writes nothing but its own run log.
 */
class FilmSiteCheck extends Model
{
    public const STATUS_CLEAN = 'clean';

    public const STATUS_WARNINGS = 'warnings';

    public const STATUS_ERRORS = 'errors';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'event_id',
        'site_url',
        'region',
        'season',
        'enabled',
        'last_checked_at',
        'last_status',
        'last_error',
        'last_errors',
        'last_warnings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(FilmSiteCheckRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(FilmSiteCheckRun::class)->latestOfMany();
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /** Enabled checks whose last run failed or found errors, for the nav badge. */
    public function scopeNeedingAttention($query)
    {
        return $query->enabled()->whereIn('last_status', [self::STATUS_ERRORS, self::STATUS_FAILED]);
    }

    /** "womensadventurefilmtour.com · AU + NEW-ZEALAND · 2026" */
    public function label(): string
    {
        return implode(' · ', array_filter([
            preg_replace('#^https?://(www\.)?#', '', $this->site_url),
            $this->region ? strtoupper(str_replace(',', ' + ', $this->region)) : null,
            $this->season,
        ]));
    }
}
