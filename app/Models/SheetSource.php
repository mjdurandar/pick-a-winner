<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tab of one Google spreadsheet, mapped to the event it feeds.
 *
 * The ANZ and USA schedules live in separate spreadsheets owned by someone else,
 * each holding roughly twenty per-film tabs. An admin picks the handful that
 * matter; the rest are never read.
 */
class SheetSource extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    /**
     * The run worked but wrote nothing, because the tab is not approved yet. Kept
     * distinct from STATUS_OK so the counts are never read as rows written — they
     * are what the run *would* do.
     */
    public const STATUS_PREVIEW = 'preview';

    public const STATUS_FAILED = 'failed';

    public const REGIONS = [
        'anz' => 'ANZ',
        'usa' => 'USA',
    ];

    protected $fillable = [
        'spreadsheet_id',
        'tab_name',
        'spreadsheet_title',
        'region',
        'event_id',
        'enabled',
        'approved_at',
        'approved_by_user_id',
        'review_notified_digest',
        'review_notified_at',
        'last_synced_at',
        'last_status',
        'last_error',
        'last_rows_created',
        'last_rows_updated',
        'last_rows_skipped',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'approved_at' => 'datetime',
        'review_notified_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SheetSourceChange::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Whether this tab is holding changes an admin has not accepted yet.
     *
     * Derived from the parked batch rather than stored on the row: the batch is
     * rewritten by every run, so a flag would only be another thing that could
     * disagree with it. Once a batch is applied the next run finds everything
     * unchanged and this goes quiet on its own.
     */
    public function needsReview(): bool
    {
        return $this->pendingChangeCount() > 0 || $this->missingCount() > 0;
    }

    /** How many rows of the parked batch would create or change a location. */
    public function pendingChangeCount(): int
    {
        return SheetSourceChange::where('sheet_source_id', $this->id)
            ->whereIn('action', SheetSourceChange::ACTIONABLE)
            ->count();
    }

    /**
     * Locations of this source the sheet has stopped mentioning.
     *
     * Kept apart from pendingChangeCount because approving a batch never acts on
     * these — they are a question for an admin, not queued work. Folding them
     * into the pending count would make "Apply 3 changes" write two rows.
     */
    public function missingCount(): int
    {
        return SheetSourceChange::where('sheet_source_id', $this->id)
            ->where('action', SheetSourceChange::ACTION_MISSING)
            ->count();
    }

    /**
     * Sources sitting on changes nobody has looked at, for the review alert.
     */
    public function scopeNeedingReview($query)
    {
        return $query->where('enabled', true)->whereHas(
            'changes',
            fn ($q) => $q->whereIn('action', SheetSourceChange::NEEDS_REVIEW)
        );
    }

    /** Records who accepted the most recent batch. */
    public function recordApproval(?int $userId): void
    {
        $this->forceFill([
            'approved_at' => now(),
            'approved_by_user_id' => $userId,
        ])->save();
    }

    /**
     * The country to assume for a tab that tracks states but not countries.
     *
     * The USA schedule has a 'Show Location (State)' column and no country
     * column — the country is in the workbook's name, not its cells. 'anz'
     * covers two countries and so can never be assumed; those tabs all carry a
     * Country/State column of their own anyway.
     */
    public function defaultCountry(): ?string
    {
        return $this->region === 'usa' ? 'USA' : null;
    }

    /** The spreadsheet as a human would open it, for links on the settings screen. */
    public function url(): string
    {
        return 'https://docs.google.com/spreadsheets/d/'.$this->spreadsheet_id.'/edit';
    }

    /**
     * The A1 range read for this tab.
     *
     * Deliberately unbounded on rows — a fixed ceiling would silently stop
     * importing the day the tab grew past it. Columns stop at BZ because the
     * widest layout seen runs to 97 columns and everything past the deliverable
     * flags is weekly sales tracking this application does not store.
     */
    public function range(): string
    {
        return $this->rangeFor('A:BZ');
    }

    /** The same tab, narrowed to some other A1 span — a single column, say. */
    public function rangeFor(string $span): string
    {
        // A tab name containing a quote or space has to be quoted in A1 notation,
        // with embedded single quotes doubled.
        $escaped = str_replace("'", "''", $this->tab_name);

        return "'{$escaped}'!{$span}";
    }

    public function recordSuccess(int $created, int $updated, int $skipped, bool $applied = false): void
    {
        $this->forceFill([
            'last_synced_at' => now(),
            'last_status' => $applied ? self::STATUS_OK : self::STATUS_PREVIEW,
            'last_error' => null,
            'last_rows_created' => $created,
            'last_rows_updated' => $updated,
            'last_rows_skipped' => $skipped,
        ])->save();
    }

    public function recordFailure(string $message): void
    {
        $this->forceFill([
            'last_synced_at' => now(),
            'last_status' => self::STATUS_FAILED,
            'last_error' => $message,
        ])->save();
    }

    /**
     * What is waiting on this tab, counted the way the alert talks about it.
     *
     * @return array{creates: int, updates: int, missing: int}
     */
    public function reviewCounts(): array
    {
        $counts = SheetSourceChange::where('sheet_source_id', $this->id)
            ->whereIn('action', SheetSourceChange::NEEDS_REVIEW)
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        return [
            'creates' => (int) $counts->get(SheetSourceChange::ACTION_CREATE, 0),
            'updates' => (int) $counts->get(SheetSourceChange::ACTION_UPDATE, 0),
            'missing' => (int) $counts->get(SheetSourceChange::ACTION_MISSING, 0),
        ];
    }

    /**
     * A fingerprint of the batch currently waiting, or null when nothing is.
     *
     * Covers what each row would do and to what, not just how many rows there
     * are: a sheet edited from "three updates" to "three different updates"
     * deserves a fresh alert, and an unchanged batch re-parked by the next
     * five-minute run deserves silence. Ordered by row so the hash does not
     * move with the order the database happens to return.
     */
    public function reviewDigest(): ?string
    {
        $rows = SheetSourceChange::where('sheet_source_id', $this->id)
            ->whereIn('action', SheetSourceChange::NEEDS_REVIEW)
            ->orderBy('sheet_row')
            ->orderBy('id')
            ->get(['sheet_row', 'action', 'location_id', 'label', 'diff']);

        if ($rows->isEmpty()) {
            return null;
        }

        return hash('sha256', $rows->map(fn (SheetSourceChange $c) => implode('|', [
            $c->sheet_row,
            $c->action,
            $c->location_id,
            $c->label,
            json_encode($c->diff),
        ]))->implode("\n"));
    }

    /**
     * Remember the batch the owner has now been told about (or has just seen on
     * screen). A null digest clears it, so the same change reappearing after an
     * approval is alerted again rather than swallowed as a repeat.
     */
    public function markReviewNotified(?string $digest): void
    {
        $this->forceFill([
            'review_notified_digest' => $digest,
            'review_notified_at' => $digest === null ? null : now(),
        ])->save();
    }
}
