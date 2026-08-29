<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one row of one tab would do to the locations table.
 *
 * Rewritten wholesale on every run — this is a view of what the sheet says right
 * now, never an append-only history. It exists so an admin can see a tab's first
 * run before anything is written, and so a later run that suddenly wants to
 * change fifty screenings is visible rather than silent.
 */
class SheetSourceChange extends Model
{
    use HasFactory;

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_UNCHANGED = 'unchanged';

    public const ACTION_SKIPPED = 'skipped';

    /**
     * A location this source created that the sheet no longer mentions.
     *
     * Not an instruction to delete anything — approving a batch never touches
     * these. It exists so a screening dropped from the sheet is visible instead
     * of silently diverging, and so an admin can decide, one row at a time,
     * whether it was cancelled or just cut and pasted mid-edit.
     */
    public const ACTION_MISSING = 'missing';

    /** The actions that would write something when a batch is approved. */
    public const ACTIONABLE = [self::ACTION_CREATE, self::ACTION_UPDATE];

    /** Everything that should raise the review alert, writes or not. */
    public const NEEDS_REVIEW = [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_MISSING];

    protected $fillable = [
        'sheet_source_id',
        'sheet_row',
        'action',
        'location_id',
        'label',
        'payload',
        'diff',
        'skip_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'diff' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SheetSource::class, 'sheet_source_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
