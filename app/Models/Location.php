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
        'is_hidden'
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
