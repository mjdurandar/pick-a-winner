<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to bring a contact back to the newsletter from the sign-up form.
 *
 * Every attempt is recorded, including the ones Mailchimp refuses. The per-location
 * counters on MailchimpImportLog only ever counted successes, so "who could we not
 * resubscribe, and why" had no answer before this table.
 */
class NewsletterResubscribeAttempt extends Model
{
    use HasFactory;

    public const RESUBSCRIBED = 'resubscribed';

    /**
     * Mailchimp holds the address in a compliance state. Only the contact can opt
     * back in, through Mailchimp's own hosted form.
     */
    public const BLOCKED_COMPLIANCE = 'blocked_compliance';

    /** Anything else — a network error, a rejected merge field, a rate limit. */
    public const FAILED = 'failed';

    public const OUTCOME_LABELS = [
        self::RESUBSCRIBED => 'Resubscribed',
        self::BLOCKED_COMPLIANCE => 'Blocked by Mailchimp (compliance)',
        self::FAILED => 'Failed',
    ];

    protected $fillable = [
        'event_id',
        'location_id',
        'email',
        'mailchimp_account',
        'list_id',
        'list_name',
        'outcome',
        'detail',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Events::class, 'event_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function label(): string
    {
        return self::OUTCOME_LABELS[$this->outcome] ?? $this->outcome;
    }
}
