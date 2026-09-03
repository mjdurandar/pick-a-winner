<?php

namespace App\Services;

use App\Models\SmsSchedule;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes parsed planning-sheet rows and turns each into an SmsSchedule that is
 * either ready for Mailchimp or flagged with what stops it.
 *
 * Resolving is read-only against Mailchimp: it looks tags up, it never creates
 * anything. Segments and campaigns are only created by the job, and only for
 * rows an admin has selected.
 */
class SmsScheduleService
{
    public function __construct(
        protected MailchimpSmsService $mailchimp,
        protected SmsGridParser $parser,
        protected ShortLinkService $shortLinks,
    ) {}

    /**
     * Parse a paste and upsert its rows.
     *
     * A row is identified by tour + location + screening date + trigger + send date, so
     * pasting the sheet again after editing it updates the pending rows in
     * place. Rows that have already reached Mailchimp are left alone and
     * counted as skipped.
     *
     * @return array{batch_id: string, created: int, updated: int, skipped: int, errors: array}
     */
    public function ingest(string $text, string $sendTime, string $timezone, ?int $userId): array
    {
        $parsed = $this->parser->parse($text);
        $batchId = (string) Str::uuid();
        $created = $updated = $skipped = 0;

        foreach ($parsed['rows'] as $data) {
            $existing = SmsSchedule::whereRaw('LOWER(film_tour) = ?', [mb_strtolower($data['film_tour'])])
                ->whereRaw('LOWER(location) = ?', [mb_strtolower($data['location'])])
                ->where('screening_date', $data['screening_date'])
                ->where('trigger_event', $data['trigger_event'])
                // Send date is part of the identity: the sheet sometimes labels
                // both sends "1 Week to Go", and two sends must never merge.
                ->where('send_date', $data['send_date'])
                ->first();

            if ($existing && ! in_array($existing->status, [
                SmsSchedule::STATUS_READY, SmsSchedule::STATUS_NEEDS_ATTENTION, SmsSchedule::STATUS_FAILED,
            ], true)) {
                $skipped++;

                continue;
            }

            $row = $existing ?? new SmsSchedule;
            $row->fill([
                'batch_id' => $batchId,
                'film_tour' => $data['film_tour'],
                'location' => $data['location'],
                'year' => $data['year'],
                'screening_date' => $data['screening_date'],
                'trigger_event' => $data['trigger_event'],
                'sheet_status' => $data['sheet_status'],
                'tag' => $data['tag'],
                'sms_text' => $data['sms_text'],
                'link' => $data['link'],
                'sheet_data_count' => $data['data_count'],
                'send_date' => $data['send_date'],
                'send_time' => $existing?->send_time ?? $sendTime,
                'timezone' => $existing?->timezone ?? $timezone,
                'campaign_name' => $this->campaignName($data['film_tour'], $data['location'], $data['trigger_event']),
                'created_by_user_id' => $existing?->created_by_user_id ?? $userId,
            ]);

            // The sheet says a human already scheduled this one and left the
            // Mailchimp link. Record it and step back; the app must not make a
            // second campaign for the same send.
            if ($data['mailchimp_campaign_id'] && Str::contains(mb_strtolower((string) $data['sheet_status']), 'scheduled')) {
                $row->mailchimp_campaign_id = $data['mailchimp_campaign_id'];
                $row->status = SmsSchedule::STATUS_EXTERNAL;
                $row->issues = [];
                $row->save();
                $existing ? $updated++ : $created++;

                continue;
            }

            $this->resolve($row);
            $row->save();
            $existing ? $updated++ : $created++;
        }

        return [
            'batch_id' => $batchId,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $parsed['errors'],
        ];
    }

    /**
     * Work out everything Mailchimp will be told, and everything that is wrong.
     * Sets tags_resolved, segment_id/name, message_body, send_at_utc, issues
     * and status on the row; does not save it.
     */
    public function resolve(SmsSchedule $row): void
    {
        $issues = [];

        // -- filled in? (rows can be typed straight into the grid) ------------
        foreach (['film_tour' => 'Film Tour', 'location' => 'Location', 'tag' => 'Tag', 'sms_text' => 'SMS Text'] as $field => $label) {
            if (trim((string) $row->{$field}) === '') {
                $issues[] = ['level' => 'error', 'text' => "{$label} is empty."];
            }
        }
        $row->campaign_name = $this->campaignName($row->film_tour, $row->location, $row->trigger_event);

        // -- when -----------------------------------------------------------
        try {
            if (! $row->send_date) {
                throw new \RuntimeException('no send date');
            }
            $sendAt = Carbon::parse($row->send_date->format('Y-m-d').' '.$row->send_time, $row->timezone);
            $row->send_at_utc = $sendAt->copy()->utc();
            if ($sendAt->isPast()) {
                $issues[] = ['level' => 'error', 'text' => 'Send time is in the past.'];
            }
        } catch (Throwable) {
            $row->send_at_utc = null;
            $issues[] = ['level' => 'error', 'text' => "Could not work out a send time from {$row->send_date?->format('Y-m-d')} {$row->send_time} {$row->timezone}."];
        }

        // -- who ------------------------------------------------------------
        $expr = $this->parseTagExpression((string) $row->tag);
        $lookupFailed = false;
        $lookup = function (array $names) use (&$issues, &$lookupFailed): array {
            $found = [];
            foreach ($names as $name) {
                if ($lookupFailed) {
                    break;
                }
                try {
                    $tag = $this->mailchimp->findTag($name);
                } catch (Throwable $e) {
                    $issues[] = ['level' => 'error', 'text' => 'Mailchimp lookup failed: '.$e->getMessage()];
                    $lookupFailed = true;
                    break;
                }
                if (! $tag) {
                    $issues[] = ['level' => 'error', 'text' => "Tag \"{$name}\" does not exist in Mailchimp (needs an exact name match)."];

                    continue;
                }
                $found[] = $tag;
            }

            return $found;
        };

        $included = $lookup($expr['include']);
        $excluded = $lookup($expr['exclude']);
        $row->tags_resolved = $included;
        $row->excluded_tags = $excluded;

        $complete = count($included) === count($expr['include']) && count($excluded) === count($expr['exclude']);

        if (count($expr['include']) === 1 && $complete) {
            // A single tag is already a segment — use it as-is.
            $row->segment_id = $included[0]['id'];
            $row->segment_name = $included[0]['name'];
        } elseif (count($expr['include']) > 1 && $complete) {
            // An intersection needs a saved segment, which is only created when
            // the row is actually sent to Mailchimp.
            $row->segment_id = null;
            $row->segment_name = 'SMS - '.implode(' & ', array_column($included, 'name'));
        } else {
            $row->segment_id = null;
            $row->segment_name = null;
        }

        if ($row->segment_name && $excluded) {
            $row->segment_name .= ' — excluding '.implode(', ', array_column($excluded, 'name'));
        }

        // -- what -----------------------------------------------------------
        $body = (string) $row->sms_text;
        if (preg_match('/\{link\}/i', $body)) {
            if ($row->link) {
                $body = preg_replace('/\{link\}/i', $row->link, $body);
            } else {
                $issues[] = ['level' => 'error', 'text' => 'Message has {link} but the ticket link column is empty.'];
            }
        }

        // Mailchimp only shortens links for campaigns made in its own editor;
        // ours go out as written. So any long URL becomes one of the app's
        // /s/ links here, before the length is judged.
        $body = $this->shortLinks->shortenLinksIn($body);
        $row->message_body = $body;

        // What actually lands on the phone: Mailchimp's compulsory company
        // prefix and opt-out line count toward the 160 too.
        $length = self::deliveredLength($body);
        if ($length > 160) {
            $segments = (int) ceil($length / 153);
            $issues[] = ['level' => 'warning', 'text' => "{$length} characters as delivered (incl. sender name and opt-out) = {$segments} SMS segments — each recipient is charged {$segments}×. Shorten the copy."];
        }

        if ($row->sheet_status && Str::contains(mb_strtolower($row->sheet_status), 'scheduled')) {
            $issues[] = ['level' => 'warning', 'text' => 'The sheet marks this as already Scheduled but has no Mailchimp link. Check Mailchimp before scheduling it again.'];
        }

        $row->issues = $issues;
        $row->status = collect($issues)->contains(fn ($i) => $i['level'] === 'error')
            ? SmsSchedule::STATUS_NEEDS_ATTENTION
            : SmsSchedule::STATUS_READY;
    }

    /**
     * An empty row for the grid's "Add row": today's date, batch defaults,
     * flagged as needing attention until the cells are filled in.
     */
    public function blank(string $sendTime, string $timezone, ?int $userId): SmsSchedule
    {
        $row = new SmsSchedule([
            'batch_id' => (string) Str::uuid(),
            'film_tour' => '',
            'location' => '',
            'tag' => '',
            'sms_text' => '',
            'send_date' => now($timezone)->addDay()->format('Y-m-d'),
            'send_time' => $sendTime,
            'timezone' => $timezone,
            'campaign_name' => '',
            'created_by_user_id' => $userId,
        ]);
        $this->resolve($row);
        $row->save();

        return $row;
    }

    /** Length of the message as Mailchimp delivers it, prefix and opt-out included. */
    public static function deliveredLength(string $body): int
    {
        return mb_strlen((string) config('services.mailchimp.anz.sms_prefix'))
            + mb_strlen($body)
            + mb_strlen((string) config('services.mailchimp.anz.sms_suffix'));
    }

    /**
     * The sheet's tag cell as who-gets-it and who-doesn't:
     *
     *   "SHOW-Melbourne"                          → include [SHOW - MELBOURNE]
     *   "SHOW-Melbourne & INT - FLY FISHING"      → include both (an intersection)
     *   "SHOW-Sydney East exclude INT - FLY FISHING" → include one, exclude one
     *
     * Everything after the first exclude word is excluded; "&" splits either side.
     *
     * @return array{include: string[], exclude: string[]}
     */
    public function parseTagExpression(string $raw): array
    {
        $parts = preg_split('/\s+(?:exclude|excluding|except|excl\.?|minus|without|not)\s+/i', $raw, 2);

        return [
            'include' => $this->tagNames($parts[0] ?? ''),
            'exclude' => $this->tagNames($parts[1] ?? ''),
        ];
    }

    /**
     * "SHOW-Melbourne" → ["SHOW - MELBOURNE"]; "INT-Climbing & SHOW-Melbourne"
     * → both, in Mailchimp's own "PREFIX - NAME" spelling.
     */
    public function tagNames(string $raw): array
    {
        $parts = preg_split('/\s*(?:&|\band\b|\+)\s*/i', $raw);
        $names = [];
        foreach ($parts as $part) {
            $part = mb_strtoupper(trim($part));
            if ($part === '') {
                continue;
            }
            $part = preg_replace('/^(SHOW|INT|SOURCE)\s*-\s*/', '$1 - ', $part);
            $names[] = preg_replace('/\s+/', ' ', $part);
        }

        return array_values(array_unique($names));
    }

    protected function campaignName(?string $tour, ?string $location, ?string $trigger): string
    {
        $name = trim("{$tour} {$location}").' SHOW';

        return $trigger ? "{$name} - {$trigger}" : $name;
    }
}
