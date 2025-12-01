<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketAttendee extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'event_id',
        'email',
        'first_name',
        'last_name',
        'phone',
        'city',
        'state',
        'country',
        'eventbrite_event_id'
    ];

    /**
     * Get the location that owns the ticket attendee.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the event that owns the ticket attendee.
     */
    public function event()
    {
        return $this->belongsTo(Events::class, 'event_id');
    }
}
