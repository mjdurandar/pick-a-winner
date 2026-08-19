<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'event_id',
        'date',
        'time',
        'password',
        'state',
        'country',
        'category',
        'cinema_contact',
        'number_of_screenings',
        'status',
        'ticketing_type',
        'booked_by',
        'date_booking_confirmed',
        'film_format',
        'dcp_trailer_sent',
        'media_kit_sent',
        'dcp_sent',
        'specific_deliverable_requests',
    ];

    /**
     * Get the event that owns the location.
     */
    public function event()
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    /**
     * Get the ticket attendees for this location.
     */
    public function ticketAttendees()
    {
        return $this->hasMany(TicketAttendee::class);
    }
}
