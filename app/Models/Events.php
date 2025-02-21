<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Events extends Model
{
    use HasFactory;
    protected $fillable = [
        'event_name',
        'event_description',
        'event_date',
        'event_year',
        'event_banner',
        'event_coordinator',
        'event_coordinator_email',
        'event_country',
    ];
}
