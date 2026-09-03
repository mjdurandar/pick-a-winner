<?php

namespace App\Jobs;

use App\Models\SmsSchedule;
use App\Services\MailchimpSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pushes one planned SMS into Mailchimp — as a draft, or scheduled.
 *
 * Draft mode is the safe rehearsal: the campaign appears in Mailchimp with its
 * audience and text, nothing is timed, and it can be deleted from the SMS
 * screen. Schedule mode does the same and then asks Mailchimp to send it at
 * the row's time. Neither mode ever calls the send action.
 *
 * One job per row so a bad tag on one city cannot hold up the other twenty.
 */
class SmsCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MODE_DRAFT = 'draft';

    public const MODE_SCHEDULE = 'schedule';

    public int $timeout = 120;

    /** Not retried: a failure is a fixable row, and the screen shows why. */
    public int $tries = 1;

    public function __construct(public int $rowId, public string $mode) {}

    public function handle(MailchimpSmsService $mailchimp): void
    {
        $row = SmsSchedule::find($this->rowId);

        if (! $row || $row->status !== SmsSchedule::STATUS_QUEUED) {
            return;
        }

        try {
            // A short link only works if a phone can reach it. Refuse to put a
            // laptop's address into a real campaign.
            if (preg_match('~https?://(localhost|127\.0\.0\.1|\[::1\])~i', (string) $row->message_body)) {
                throw new RuntimeException('The message contains a localhost short link — set SHORT_LINK_BASE_URL / APP_URL to the public domain (and run this on that server) before pushing to Mailchimp.');
            }

            // A multi-tag row targets a saved segment made for it; a single tag
            // is already a segment and was resolved at paste time.
            if (! $row->segment_id) {
                $tagIds = array_column($row->tags_resolved ?? [], 'id');
                if (count($tagIds) < 2 || ! $row->segment_name) {
                    throw new RuntimeException('No Mailchimp segment resolved for this row. Re-paste it to resolve tags again.');
                }
                $segment = $mailchimp->findOrCreateIntersectionSegment($row->segment_name, $tagIds);
                $row->segment_id = $segment['id'];
                $row->segment_name = $segment['name'];
                $row->save();
            }

            $excluded = array_column($row->excluded_tags ?? [], 'id');

            if ($row->mailchimp_campaign_id) {
                $mailchimp->updateCampaign($row->mailchimp_campaign_id, $row->campaign_name, $row->segment_id, $excluded);
            } else {
                $row->mailchimp_campaign_id = $mailchimp->createCampaign($row->campaign_name, $row->segment_id, $excluded);
                $row->save(); // Keep the id even if the next call fails, so nothing is orphaned.
            }

            $content = $mailchimp->setContent($row->mailchimp_campaign_id, $row->message_body);
            $row->message_segments = $content['estimated_segments'] ?? null;

            if ($this->mode === self::MODE_SCHEDULE) {
                if (! $row->send_at_utc || $row->send_at_utc->isPast()) {
                    throw new RuntimeException('Send time is missing or already in the past.');
                }
                $mailchimp->schedule($row->mailchimp_campaign_id, $row->send_at_utc);
            }

            $campaign = $mailchimp->getCampaign($row->mailchimp_campaign_id);
            $row->recipient_count = $campaign['recipient_count'] ?? null;

            // Mailchimp answers the schedule call with 200 and stores the send
            // time even when it has NOT booked the send — seen when the SMS
            // credit balance cannot cover the campaign. Read the status back
            // and believe that, not the 200.
            if ($this->mode === self::MODE_SCHEDULE && ! in_array($campaign['status'] ?? '', ['schedule', 'scheduled'], true)) {
                throw new RuntimeException(
                    'Mailchimp accepted the schedule but left the campaign as a draft ('.($campaign['status'] ?? '?').
                    '). This usually means not enough SMS credits for '.($campaign['recipient_count'] ?? '?').
                    ' recipients — check Account → Billing → SMS credits, then Schedule again.'
                );
            }

            $row->status = $this->mode === self::MODE_SCHEDULE ? SmsSchedule::STATUS_SCHEDULED : SmsSchedule::STATUS_DRAFT;
            $row->error = null;
            $row->save();
        } catch (\Throwable $e) {
            $row->status = SmsSchedule::STATUS_FAILED;
            $row->error = $e->getMessage();
            $row->save();

            Log::warning('SMS campaign job failed', [
                'sms_schedule_id' => $row->id,
                'mode' => $this->mode,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
