<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record per row of the uploaded CSV, including rows that are blocked and
 * never leave the app. This table is the single source of truth for both the
 * dry-run preview and the final report, which is why the two can be compared.
 */
class MailchimpImportRow extends Model
{
    use HasFactory;

    // Dry-run outcomes.
    public const WILL_SUBSCRIBE = 'will_subscribe';

    /**
     * Already in the audience as unsubscribed, cleaned or archived. Mailchimp only
     * refuses these when the contact is in a compliance state, and it does not say
     * so on the member object — SignUpFormController discovers it by attempting the
     * PATCH. So the dry run cannot tell the two apart and lands here optimistically;
     * the ones Mailchimp refuses come back as BLOCKED_UNSUBSCRIBED at run time.
     */
    public const WILL_RESUBSCRIBE = 'will_resubscribe';

    public const ALREADY_MEMBER = 'already_member';

    public const BLOCKED_INVALID = 'blocked_invalid';

    public const BLOCKED_DUPLICATE = 'blocked_duplicate';

    public const BLOCKED_MISSING = 'blocked_missing';

    // Post-run outcomes.
    public const SUBSCRIBED = 'subscribed';

    public const RESUBSCRIBED = 'resubscribed';

    /**
     * Already in the audience, and this run rewrote their merge fields and tags
     * from the file. Only produced when the import has update_existing set; kept
     * apart from SUBSCRIBED so the report never claims a contact is new when the
     * audience already had them.
     */
    public const UPDATED = 'updated';

    /**
     * Run-time only: Mailchimp returned 400 Compliance State, so this contact can
     * never be re-subscribed through the API. Only they can opt back in, via
     * Mailchimp's own hosted form.
     */
    public const BLOCKED_UNSUBSCRIBED = 'blocked_unsubscribed';

    /**
     * The API refused them, their opt-in was transcribed to the audience's hosted
     * form, and a fresh read of the member confirms they are back. Kept separate
     * from RESUBSCRIBED so the route a contact came back by stays visible.
     */
    public const RECOVERED_VIA_FORM = 'recovered_via_form';

    public const FAILED = 'failed';

    /**
     * Outcomes the dry run can produce. The preview is built only from these.
     */
    public const DRY_RUN_OUTCOMES = [
        self::WILL_SUBSCRIBE,
        self::WILL_RESUBSCRIBE,
        self::ALREADY_MEMBER,
        self::BLOCKED_INVALID,
        self::BLOCKED_DUPLICATE,
        self::BLOCKED_MISSING,
    ];

    /**
     * Dry-run outcomes the import job will act on. Everything else is recorded and
     * left alone.
     */
    public const ACTIONABLE_OUTCOMES = [
        self::WILL_SUBSCRIBE,
        self::WILL_RESUBSCRIBE,
    ];

    /**
     * Rows Mailchimp will reject or that never had a usable address. Counted
     * together as "cannot subscribe" in the preview.
     */
    public const BLOCKED_OUTCOMES = [
        self::BLOCKED_UNSUBSCRIBED,
        self::BLOCKED_INVALID,
        self::BLOCKED_DUPLICATE,
        self::BLOCKED_MISSING,
    ];

    public const OUTCOME_LABELS = [
        self::WILL_SUBSCRIBE => 'Will subscribe',
        self::WILL_RESUBSCRIBE => 'Will resubscribe',
        self::ALREADY_MEMBER => 'Already a member',
        self::BLOCKED_INVALID => 'Invalid email',
        self::BLOCKED_DUPLICATE => 'Duplicate in file',
        self::BLOCKED_MISSING => 'Missing email',
        self::SUBSCRIBED => 'Subscribed',
        self::RESUBSCRIBED => 'Resubscribed',
        self::UPDATED => 'Updated',
        self::RECOVERED_VIA_FORM => 'Resubscribed (signup form)',
        self::BLOCKED_UNSUBSCRIBED => 'Blocked by Mailchimp (compliance)',
        self::FAILED => 'Failed',
    ];

    protected $fillable = [
        'import_id',
        'row_number',
        'email',
        'outcome',
        'detail',
        'existing_status',
        'mailchimp_status_code',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(MailchimpImport::class, 'import_id');
    }

    public function scopeOutcome(Builder $query, ?string $outcome): Builder
    {
        return $outcome ? $query->where('outcome', $outcome) : $query;
    }

    public function label(): string
    {
        return self::OUTCOME_LABELS[$this->outcome] ?? $this->outcome;
    }

    public function isBlocked(): bool
    {
        return in_array($this->outcome, self::BLOCKED_OUTCOMES, true);
    }

    /**
     * True when the import job still has work to do for this row. Resubscribes are
     * not sent through the batch endpoint — update_existing stays false, so batch
     * skips existing members — and are PATCHed individually instead.
     */
    public function isActionable(): bool
    {
        return in_array($this->outcome, self::ACTIONABLE_OUTCOMES, true);
    }

    public function needsResubscribe(): bool
    {
        return $this->outcome === self::WILL_RESUBSCRIBE;
    }

    /**
     * Masked form for anything that reaches the application log — full addresses
     * belong only in this table and the downloadable report.
     *
     * maria@gmail.com => m***a@gmail.com
     */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return '(empty)';
        }

        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        $masked = match (true) {
            strlen($local) >= 3 => $local[0].'***'.$local[strlen($local) - 1],
            strlen($local) === 2 => $local[0].'***',
            default => '***',
        };

        return $masked.$domain;
    }

    public function maskedEmail(): string
    {
        return self::maskEmail($this->email);
    }
}
