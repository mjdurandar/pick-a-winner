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

    /**
     * The API refused them, so their own submission was relayed to Mailchimp's
     * hosted form and Mailchimp has emailed them to confirm. They are not back on
     * the list until they click that link, which is why this is its own outcome and
     * not counted as a resubscribe.
     */
    public const CONFIRMATION_SENT = 'confirmation_sent';

    /**
     * The signup form was rate limiting when this contact opted in, so nothing was
     * decided about them. They gave consent and are owed another attempt — the drip
     * picks these up, which is the whole reason they are not filed as blocked.
     */
    public const DEFERRED = 'deferred';

    /** Anything else — a network error, a rejected merge field, a rate limit. */
    public const FAILED = 'failed';

    public const OUTCOME_LABELS = [
        self::RESUBSCRIBED => 'Resubscribed',
        self::CONFIRMATION_SENT => 'Confirmation sent (hosted form)',
        self::DEFERRED => 'Waiting to retry (form was busy)',
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
