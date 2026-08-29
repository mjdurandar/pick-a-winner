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
        'sheet_source_id',
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
     * The master sheet tab this location came from, if any.
     *
     * Null means nobody's spreadsheet owns it — either it was made by hand, or
     * an admin pressed Keep after its row disappeared from the sheet.
     */
    public function sheetSource()
    {
        return $this->belongsTo(SheetSource::class, 'sheet_source_id');
    }

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
