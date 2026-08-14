<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One CSV import run: upload, dry run, execution and report all hang off this
 * record. Counts are not authoritative — the row log is. They are stored so the
 * history list can render without aggregating every run's rows.
 */
class MailchimpImport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DRY_RUN_RUNNING = 'dry_run_running';

    public const STATUS_DRY_RUN_COMPLETE = 'dry_run_complete';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    /**
     * Mailchimp's batch subscribe endpoint accepts up to 500 members per call.
     */
    public const BATCH_SIZE = 500;

    /**
     * Field-map target for a column holding tags rather than a merge field.
     *
     * Tags are not merge fields — they are their own thing on a Mailchimp contact —
     * so they never appear in /lists/{id}/merge-fields and a column of them had
     * nowhere to map to. This sentinel sits alongside the real merge tags in
     * field_map; it cannot collide with one, because Mailchimp merge tags are
     * uppercase alphanumerics and cannot contain an underscore-wrapped name.
     */
    public const TAGS_TARGET = '__TAGS__';

    /**
     * One cell can carry several tags. Commas separate them, the way Mailchimp's own
     * export writes them.
     */
    public static function splitTags(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected $fillable = [
        'account',
        'filename',
        'stored_path',
        'file_size',
        'original_row_count',
        'audience_id',
        'audience_name',
        'tag',
        'update_existing',
        'field_map',
        'double_optin',
        'consent_confirmed_by_user_id',
        'consent_confirmed_at',
        'consent_source',
        'consent_details',
        'progress_note',
        'status',
        'last_batch_index',
        'subscribed_count',
        'resubscribed_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'started_at',
        'dry_run_started_at',
        'completed_at',
        'failure_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'field_map' => 'array',
        'consent_details' => 'array',
        'double_optin' => 'boolean',
        'update_existing' => 'boolean',
        'consent_confirmed_at' => 'datetime',
        'started_at' => 'datetime',
        'dry_run_started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(MailchimpImportRow::class, 'import_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function consentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_confirmed_by_user_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MailchimpConnection::class, 'account', 'account');
    }

    /**
     * The status sent to Mailchimp for each member. On a double opt-in audience
     * contacts land as pending and only become subscribed once they confirm, so
     * sending 'subscribed' there would be rejected.
     */
    public function memberStatus(): string
    {
        return $this->double_optin ? 'pending' : 'subscribed';
    }

    /**
     * Preview wording follows the same distinction — on a double opt-in audience
     * nothing is subscribed by this import, only invited.
     */
    public function subscribeMetricLabel(): string
    {
        return $this->double_optin ? 'Will be invited' : 'Will subscribe';
    }

    /**
     * Batches are numbered from zero and last_batch_index records the last one
     * Mailchimp confirmed. A resumed job starts here, so already-sent batches are
     * never replayed.
     */
    public function nextBatchIndex(): int
    {
        return $this->last_batch_index === null ? 0 : $this->last_batch_index + 1;
    }

    /**
     * Row counts by outcome, read back from the row log rather than from any
     * counter, so the preview and the final report are the same numbers computed
     * the same way. Outcomes with no rows are present as zero.
     *
     * @return array<string, int>
     */
    public function outcomeCounts(): array
    {
        return array_merge(
            array_fill_keys(MailchimpImportRow::DRY_RUN_OUTCOMES, 0),
            $this->rows()
                ->selectRaw('outcome, count(*) as total')
                ->groupBy('outcome')
                ->pluck('total', 'outcome')
                ->map(fn ($total) => (int) $total)
                ->all(),
        );
    }

    /**
     * The dry-run outcomes this particular run will act on.
     *
     * Contacts already in the audience are normally left alone. With update_existing
     * on they become actionable too, so a CSV can carry corrections — a changed
     * phone number, a new tag — to people who are already subscribed.
     *
     * @return array<int, string>
     */
    public function actionableOutcomes(): array
    {
        return $this->update_existing
            ? array_merge(MailchimpImportRow::ACTIONABLE_OUTCOMES, [MailchimpImportRow::ALREADY_MEMBER])
            : MailchimpImportRow::ACTIONABLE_OUTCOMES;
    }

    /**
     * How many contacts this import would actually act on.
     */
    public function actionableCount(): int
    {
        return $this->rows()->whereIn('outcome', $this->actionableOutcomes())->count();
    }

    public function isConsentConfirmed(): bool
    {
        return $this->consent_confirmed_by_user_id !== null && $this->consent_confirmed_at !== null;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_FAILED], true);
    }
}
