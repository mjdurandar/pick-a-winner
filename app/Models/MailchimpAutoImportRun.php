<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MailchimpAutoImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'ran_at',
        'days_threshold',
        'dry_run',
        'triggered_by',
        'triggered_by_user_id',
        'events_processed',
        'locations_imported',
        'locations_skipped',
        'total_new',
        'total_updated',
        'total_errors',
        'details',
        'status',
        'error',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
        'dry_run' => 'boolean',
        'details' => 'array',
    ];
}
