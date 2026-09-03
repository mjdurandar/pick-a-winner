<?php

namespace App\Http\Controllers;

use App\Jobs\SmsCampaignJob;
use App\Models\SmsSchedule;
use App\Services\MailchimpSmsService;
use App\Services\SmsScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The SMS screen: paste the planning sheet, see what each row resolves to,
 * push selected rows into Mailchimp as drafts or scheduled campaigns.
 *
 * Every Mailchimp write goes through an explicit button on selected rows.
 * Pasting only reads (tag lookups); nothing is created until an admin asks.
 */
class SmsScheduleController extends Controller
{
    public function __construct(
        protected SmsScheduleService $service,
        protected MailchimpSmsService $mailchimp,
    ) {}

    public function index(): Response
    {
        $prefix = $this->mailchimp->serverPrefix();

        return Inertia::render('SmsSchedule', [
            'configured' => $this->mailchimp->isConfigured(),
            'listId' => $this->mailchimp->listId(),
            'defaults' => [
                'send_time' => '13:00',
                'timezone' => 'Australia/Sydney',
                'credits_per_segment' => (int) config('services.mailchimp.anz.sms_credits_per_segment', 4),
                // What Mailchimp wraps around every message; the counter includes it.
                'sms_prefix' => (string) config('services.mailchimp.anz.sms_prefix'),
                'sms_suffix' => (string) config('services.mailchimp.anz.sms_suffix'),
                'short_link_base' => app(\App\Services\ShortLinkService::class)->baseUrl(),
            ],
            'timezones' => [
                'Australia/Sydney', 'Australia/Melbourne', 'Australia/Brisbane', 'Australia/Adelaide',
                'Australia/Perth', 'Australia/Hobart', 'Australia/Darwin', 'Pacific/Auckland',
            ],
            // Newest paste first; within a paste, the order the rows were pasted in,
            // so a column copied out of the grid lines up with the sheet.
            'rows' => SmsSchedule::orderByDesc('created_at')
                ->orderBy('id')
                ->limit(500)
                ->get()
                ->map(fn (SmsSchedule $r) => [
                    'id' => $r->id,
                    'batch_id' => $r->batch_id,
                    'film_tour' => $r->film_tour,
                    'location' => $r->location,
                    'screening_date' => $r->screening_date?->format('Y-m-d'),
                    'trigger_event' => $r->trigger_event,
                    'tag' => $r->tag,
                    'send_date' => $r->send_date?->format('Y-m-d'),
                    'send_time' => substr((string) $r->send_time, 0, 5),
                    'timezone' => $r->timezone,
                    'send_at_utc' => $r->send_at_utc?->toIso8601String(),
                    'sms_text' => $r->sms_text,
                    'link' => $r->link,
                    'message_body' => $r->message_body,
                    'segment_id' => $r->segment_id,
                    'segment_name' => $r->segment_name,
                    'tags_resolved' => $r->tags_resolved ?? [],
                    'excluded_tags' => $r->excluded_tags ?? [],
                    'campaign_name' => $r->campaign_name,
                    'mailchimp_campaign_id' => $r->mailchimp_campaign_id,
                    'mailchimp_url' => $r->mailchimpUrl($prefix),
                    'recipient_count' => $r->recipient_count,
                    'sheet_data_count' => $r->sheet_data_count,
                    'message_segments' => $r->message_segments,
                    'status' => $r->status,
                    'issues' => $r->issues ?? [],
                    'error' => $r->error,
                    'sheet_status' => $r->sheet_status,
                    'updated_at' => $r->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    /** A blank row to type into. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->service->blank($data['send_time'], $data['timezone'], $request->user()?->id);

        return back();
    }

    public function paste(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500000'],
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $result = $this->service->ingest($data['text'], $data['send_time'], $data['timezone'], $request->user()?->id);

        $summary = "{$result['created']} added, {$result['updated']} updated";
        if ($result['skipped']) {
            $summary .= ", {$result['skipped']} skipped (already in Mailchimp)";
        }

        if (! $result['created'] && ! $result['updated'] && $result['errors']) {
            return back()->with('error', implode(' ', $result['errors']));
        }

        return back()
            ->with('success', $summary.'.')
            ->with('warning', $result['errors'] ? implode(' ', $result['errors']) : null);
    }

    /**
     * Bulk action on selected rows. draft/schedule are queued per row; the
     * rest are quick single calls done inline so the screen updates at once.
     */
    public function actions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:sms_schedules,id'],
            'action' => ['required', Rule::in(['draft', 'schedule', 'cancel', 'refresh', 'delete_campaign', 'remove'])],
        ]);

        $rows = SmsSchedule::whereIn('id', $data['ids'])->get();
        $done = 0;
        $problems = [];

        foreach ($rows as $row) {
            try {
                match ($data['action']) {
                    'draft' => $this->queue($row, SmsCampaignJob::MODE_DRAFT),
                    'schedule' => $this->queue($row, SmsCampaignJob::MODE_SCHEDULE),
                    'cancel' => $this->cancel($row),
                    'refresh' => $this->refresh($row),
                    'delete_campaign' => $this->deleteCampaign($row),
                    'remove' => $this->remove($row),
                };
                $done++;
            } catch (Throwable $e) {
                $problems[] = "{$row->location} ({$row->trigger_event}): {$e->getMessage()}";
            }
        }

        $labels = [
            'draft' => 'queued to create as drafts',
            'schedule' => 'queued to schedule',
            'cancel' => 'unscheduled (campaign deleted in Mailchimp; Schedule recreates it)',
            'refresh' => 'refreshed from Mailchimp',
            'delete_campaign' => 'deleted from Mailchimp',
            'remove' => 'removed',
        ];

        $response = back()->with('success', $done ? "{$done} row(s) {$labels[$data['action']]}." : null);

        return $problems ? $response->with('error', implode(' ', $problems)) : $response;
    }

    /**
     * One or more cells edited in the grid; the row is resolved again afterwards.
     *
     * Answers with JSON, never a redirect: the grid saves cells with axios, and a
     * 302 to a PATCH makes the browser re-send the PATCH to /sms — a 405.
     */
    public function update(Request $request, SmsSchedule $smsSchedule): JsonResponse
    {
        if (in_array($smsSchedule->status, [SmsSchedule::STATUS_SCHEDULED, SmsSchedule::STATUS_SENT, SmsSchedule::STATUS_QUEUED, SmsSchedule::STATUS_EXTERNAL], true)) {
            return response()->json(['message' => 'Cancel it first — a scheduled, sent or queued row cannot be edited.'], 409);
        }

        $data = $request->validate([
            'year' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'film_tour' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'screening_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trigger_event' => ['sometimes', 'nullable', 'string', 'max:255'],
            'send_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'send_time' => ['sometimes', 'required', 'date_format:H:i'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'sms_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'link' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);

        // Text cells that are cleared arrive as null; the columns are NOT NULL.
        foreach (['film_tour', 'location', 'tag', 'sms_text'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        $smsSchedule->fill($data);
        $this->service->resolve($smsSchedule);
        $smsSchedule->save();

        return response()->json(['ok' => true, 'status' => $smsSchedule->status]);
    }

    // -- per-row actions ---------------------------------------------------

    protected function queue(SmsSchedule $row, string $mode): void
    {
        if (! $row->isActionable()) {
            throw new \RuntimeException('not ready — fix its issues first');
        }

        $row->update(['status' => SmsSchedule::STATUS_QUEUED, 'error' => null]);
        SmsCampaignJob::dispatch($row->id, $mode);
    }

    /**
     * Stop a scheduled send. The SMS API has no unschedule action and its
     * cancel-send is a no-op for a scheduled campaign (204, still scheduled —
     * seen 2026-09-03), so the only way to stop it is to delete the campaign.
     * The row goes back to Ready; Schedule creates a fresh campaign.
     */
    protected function cancel(SmsSchedule $row): void
    {
        if ($row->status !== SmsSchedule::STATUS_SCHEDULED || ! $row->mailchimp_campaign_id) {
            throw new \RuntimeException('is not scheduled');
        }

        $this->mailchimp->delete($row->mailchimp_campaign_id);

        try {
            $this->mailchimp->getCampaign($row->mailchimp_campaign_id);
            throw new \RuntimeException('Mailchimp still has the campaign after delete — stop it in the Mailchimp UI');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // 404 is the success case: it is gone.
        }

        $row->update(['status' => SmsSchedule::STATUS_READY, 'mailchimp_campaign_id' => null, 'message_segments' => null, 'error' => null]);
    }

    protected function refresh(SmsSchedule $row): void
    {
        if (! $row->mailchimp_campaign_id) {
            throw new \RuntimeException('has no Mailchimp campaign yet');
        }

        $campaign = $this->mailchimp->getCampaign($row->mailchimp_campaign_id);

        $status = match ($campaign['status'] ?? '') {
            'sent', 'sending' => SmsSchedule::STATUS_SENT,
            'schedule', 'scheduled', 'paused' => SmsSchedule::STATUS_SCHEDULED,
            'draft' => $row->status === SmsSchedule::STATUS_EXTERNAL ? SmsSchedule::STATUS_EXTERNAL : SmsSchedule::STATUS_DRAFT,
            default => $row->status,
        };

        $row->update([
            'status' => $status,
            'recipient_count' => $campaign['recipient_count'] ?? $row->recipient_count,
            'error' => null,
        ]);
    }

    protected function deleteCampaign(SmsSchedule $row): void
    {
        if (! in_array($row->status, [SmsSchedule::STATUS_DRAFT, SmsSchedule::STATUS_FAILED], true) || ! $row->mailchimp_campaign_id) {
            throw new \RuntimeException('only a draft the app created can be deleted');
        }

        $this->mailchimp->delete($row->mailchimp_campaign_id);
        $row->update(['mailchimp_campaign_id' => null, 'recipient_count' => null, 'status' => SmsSchedule::STATUS_READY, 'error' => null]);
    }

    protected function remove(SmsSchedule $row): void
    {
        if (in_array($row->status, [SmsSchedule::STATUS_SCHEDULED, SmsSchedule::STATUS_QUEUED], true)) {
            throw new \RuntimeException('cancel it before removing');
        }

        // Removing the row never removes the campaign: a draft left behind in
        // Mailchimp is visible there, an orphaned schedule would not be.
        $row->delete();
    }
}
