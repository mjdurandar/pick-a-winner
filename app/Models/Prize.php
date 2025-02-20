<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prize extends Model
{
    protected $fillable = [
        'event_id',
        'location_id',
        'prize_name',
        'winner',
        'winner_email',
        'winner_mobile_number',
    ];
}
