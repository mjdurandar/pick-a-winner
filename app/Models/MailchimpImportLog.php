<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailchimpImportLog extends Model
{
    protected $fillable = [
        'location_id',
        'imported_by',
        'total_data',
        'new_contacts',
        'updated_data',
        'data_with_error',
        'errors',
        'tags',
        'source',
        'has_import_file',
    ];

    protected $casts = [
        'tags' => 'array',
        'errors' => 'array',
        'has_import_file' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
