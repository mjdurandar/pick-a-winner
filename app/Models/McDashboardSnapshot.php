<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McDashboardSnapshot extends Model
{
    protected $fillable = [
        'account',
        'period_label',
        'snapshot_date',
        'audiences',
        'film_tags',
        'mag_tags',
        'total',
        'au_total',
        'us_total',
    ];

    protected $casts = [
        'audiences' => 'array',
        'film_tags' => 'array',
        'mag_tags' => 'array',
        'snapshot_date' => 'date',
    ];
}
