<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailchimpLog extends Model
{
    protected $fillable = [
        'location_id',
        'email_address',
        'status',
        'error_message',
        'tags'
    ];

    protected $casts = [
        'tags' => 'array'
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
} 