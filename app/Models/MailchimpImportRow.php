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

    public const ALREADY_MEMBER = 'already_member';

    public const BLOCKED_UNSUBSCRIBED = 'blocked_unsubscribed';

    public const BLOCKED_INVALID = 'blocked_invalid';

    public const BLOCKED_DUPLICATE = 'blocked_duplicate';

    public const BLOCKED_MISSING = 'blocked_missing';

    // Post-run outcomes.
    public const SUBSCRIBED = 'subscribed';

    public const FAILED = 'failed';

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
        self::ALREADY_MEMBER => 'Already a member',
        self::BLOCKED_UNSUBSCRIBED => 'Previously unsubscribed',
        self::BLOCKED_INVALID => 'Invalid email',
        self::BLOCKED_DUPLICATE => 'Duplicate in file',
        self::BLOCKED_MISSING => 'Missing email',
        self::SUBSCRIBED => 'Subscribed',
        self::FAILED => 'Failed',
    ];

    protected $fillable = [
        'import_id',
        'row_number',
        'email',
        'outcome',
        'detail',
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
