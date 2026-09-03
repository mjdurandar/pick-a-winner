<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned SMS: a tour + location + trigger ("1 Day to Go") that becomes one
 * Mailchimp SMS campaign.
 *
 * Rows come from pasting the SMS planning sheet. The app never sends — it only
 * creates campaigns and asks Mailchimp to schedule them, and only when an admin
 * presses the button for the rows they have selected.
 */
class SmsSchedule extends Model
{
    /** Pasted and resolved cleanly; can be drafted or scheduled. */
    public const STATUS_READY = 'ready';

    /** Something in the row could not be resolved (see issues). Not schedulable until fixed. */
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    /** A job is working on it. */
    public const STATUS_QUEUED = 'queued';

    /** Exists in Mailchimp as a draft — visible there, nothing scheduled. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT = 'sent';

    /** The Mailchimp call failed; error holds the reason. */
    public const STATUS_FAILED = 'failed';

    /**
     * The sheet said this one was already scheduled by hand and carried a
     * Mailchimp link. Tracked for completeness; the app will not touch it.
     */
    public const STATUS_EXTERNAL = 'external';

    protected $fillable = [
        'batch_id',
        'film_tour',
        'location',
        'year',
        'screening_date',
        'trigger_event',
        'sheet_status',
        'tag',
        'sms_text',
        'link',
        'send_date',
        'send_time',
        'timezone',
        'send_at_utc',
        'tags_resolved',
        'excluded_tags',
        'segment_id',
        'segment_name',
        'campaign_name',
        'message_body',
        'mailchimp_campaign_id',
        'recipient_count',
        'sheet_data_count',
        'message_segments',
        'status',
        'issues',
        'error',
        'created_by_user_id',
    ];

    protected $casts = [
        'screening_date' => 'date:Y-m-d',
        'send_date' => 'date:Y-m-d',
        'send_at_utc' => 'datetime',
        'tags_resolved' => 'array',
        'excluded_tags' => 'array',
        'issues' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** True when the row can be handed to Mailchimp (drafted or scheduled). */
    public function isActionable(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_DRAFT, self::STATUS_FAILED], true)
            && ! $this->hasBlockingIssue();
    }

    public function hasBlockingIssue(): bool
    {
        foreach ($this->issues ?? [] as $issue) {
            if (($issue['level'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /** Where the campaign lives in the Mailchimp UI, or null if it isn't there yet. */
    public function mailchimpUrl(?string $serverPrefix): ?string
    {
        if (! $this->mailchimp_campaign_id || ! $serverPrefix) {
            return null;
        }

        return "https://{$serverPrefix}.admin.mailchimp.com/sms/bulk?id={$this->mailchimp_campaign_id}";
    }
}
