<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Events extends Model
{
    use HasFactory;

    /** Fallback support number shown on the host guide when an event has no coordinator phone. */
    public const DEFAULT_COORDINATOR_PHONE = '0485 952 778';

    protected $fillable = [
        'event_name',
        'is_enabled',
        'show_all_locations',
        'show_all_locations_draw',
        'event_date',
        'event_end_date',
        'event_year',
        'event_banner',
        'event_logo',
        'event_coordinator',
        'event_coordinator_email',
        'event_coordinator_phone',
        'event_country',
        'event_uuid',
        'instructions_password',
        'film_id',
        'auto_import_enabled',
        'auto_import_list_id',
        'auto_import_list_name',
        'auto_import_account',
    ];

    protected $casts = [
        'auto_import_enabled' => 'boolean',
    ];

    // event_date / event_end_date are intentionally left uncast: the admin form's
    // ScrollDatePicker binds to the raw Y-m-d string, and casting would serialize
    // them as ISO timestamps.

    protected $appends = [
        'is_signup_closed',
    ];

    /**
     * Sign-ups close at the end of event_end_date, so the last day is still open.
     * An event with no end date never closes.
     */
    public function getIsSignupClosedAttribute(): bool
    {
        if (empty($this->event_end_date)) {
            return false;
        }

        return Carbon::parse($this->event_end_date)->endOfDay()->isPast();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($event) {
            if (empty($event->event_uuid)) {
                $event->event_uuid = Str::uuid()->toString();
            }
            if (empty($event->instructions_password)) {
                $event->instructions_password = strtoupper(Str::random(6));
            }
        });
    }

    public function signUpForm()
    {
        return $this->hasOne(SignUpForm::class, 'event_id');
    }

    public function locations()
    {
        return $this->hasMany(Location::class, 'event_id');
    }

    public function film()
    {
        return $this->belongsTo(Films::class, 'film_id');
    }
}
