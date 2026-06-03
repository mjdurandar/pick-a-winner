<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_name_snapshot',
        'user_email_snapshot',
        'route_name',
        'method',
        'url',
        'target_type',
        'target_id',
        'params',
        'ip_address',
        'user_agent',
        'row_count',
        'file_name',
        'status',
    ];

    protected $casts = [
        'params' => 'array',
        'row_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
