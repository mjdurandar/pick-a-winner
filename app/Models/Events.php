<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Events extends Model
{
    use HasFactory;
    protected $fillable = [
        'event_name',
        'is_enabled',
        'show_all_locations',
        'event_date',
        'event_year',
        'event_banner',
        'event_logo',
        'event_coordinator',
        'event_coordinator_email',
        'event_country',
        'event_uuid',
        'film_id'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($event) {
            if (empty($event->event_uuid)) {
                $event->event_uuid = Str::uuid()->toString();
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
