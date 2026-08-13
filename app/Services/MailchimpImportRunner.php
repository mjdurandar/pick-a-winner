<?php

namespace App\Services;

use App\Exceptions\CsvImportException;
use App\Exceptions\MailchimpApiException;
use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;

/**
 * Sends an import that has been previewed.
 *
 * Only rows the dry run marked actionable are touched, and each one is written
 * back the moment Mailchimp answers for it. That is what makes a killed worker
 * safe to restart: a row that has already been sent no longer looks actionable,
 * so the retry passes over it.
 *
 * New contacts go through the batch endpoint. Resubscribes cannot — batch runs
 * with update_existing false and skips anyone already on the list, which is every
 * resubscribe by definition — so those are PATCHed one at a time.
 */
class MailchimpImportRunner
{
    /**
     * Resubscribes processed between refreshes of the import's summary counters.
     *
     * Refreshing after every contact meant a GROUP BY over the row log plus an
     * UPDATE for each one — ten thousand extra queries on a five-thousand-contact
     * import. Nothing reads those columns while the run is in flight: the preview
     * endpoint counts the rows themselves, which are written as each contact is
     * answered for. They exist so a run that dies has recent numbers to report.
     */
    private const COUNTER_REFRESH_INTERVAL = 50;

    /**
     * Paced between hosted-form submissions. The endpoint is a public web form, not
     * an API, and a file's worth of submissions arriving flat out is both rude and
     * the shape abuse systems look for. A quarter of a second was far too quick:
     * sixteen went through and the seventeenth onwards were all turned away.
     */
    private const FORM_SUBMISSION_PAUSE_SECONDS = 3;

    /** Waited out before trying a throttled contact again, in order. */
    private const THROTTLE_BACKOFF_SECONDS = [20, 60];

    /**
     * Consecutive throttles that end the relay for this run. Once the form is
     * turning everything away, continuing only deepens it — the remaining contacts
     * keep their actionable classification and a later run picks them up.
     */
    private const THROTTLES_BEFORE_STOPPING = 3;

    /** Throttles in a row, reset by any submission the form actually answers. */
    protected int $consecutiveThrottles = 0;

    /** Set once the form is turning everything away; no further contact is offered. */
    protected bool $relayStopped = false;

    public function __construct(
        protected CsvImportParser $parser,
        protected OptInRelay $relay,
    ) {}

    /**
     * @throws CsvImportException
     * @throws MailchimpApiException
     */
    public function run(MailchimpImport $import, MailchimpApi $api, string $path): void
    {
        $outcomes = $import->rows()
            ->whereIn('outcome', MailchimpImportRow::ACTIONABLE_OUTCOMES)
            ->pluck('outcome', 'row_number');

        if ($outcomes->isEmpty()) {
            return;
        }

        $map = $import->field_map ?? [];
        $emailColumn = (string) array_search('EMAIL', $map, true);
        $batch = [];
        $resubscribed = 0;

        foreach ($this->parser->rows($path) as [$rowNumber, $values]) {
            $outcome = $outcomes[$rowNumber] ?? null;

            if ($outcome === null) {
                continue;
            }

            $email = trim((string) ($values[$emailColumn] ?? ''));

            if ($outcome === MailchimpImportRow::WILL_RESUBSCRIBE) {
                $this->resubscribe($import, $api, $rowNumber, $email);

                if (++$resubscribed % self::COUNTER_REFRESH_INTERVAL === 0) {
                    $this->refreshCounts($import);
                }

                continue;
            }

            $batch[$rowNumber] = $this->member($import, $map, $emailColumn, $values, $email);

            if (count($batch) >= MailchimpImport::BATCH_SIZE) {
                $this->sendBatch($import, $api, $batch);
                $batch = [];
            }
        }

        if ($batch) {
            $this->sendBatch($import, $api, $batch);
        }

        // The last partial group of resubscribes, and any run that was all
        // resubscribes and so never sent a batch, still have to be counted.
        $this->refreshCounts($import);
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch  keyed by row number
     *
     * @throws MailchimpApiException
     */
    protected function sendBatch(MailchimpImport $import, MailchimpApi $api, array $batch): void
    {
        $result = $api->batchSubscribe($import->audience_id, array_values($batch));

        $created = array_flip($result['new']);
        $errors = collect($result['errors'])->keyBy('email');

        foreach ($batch as $rowNumber => $member) {
            $email = strtolower($member['email_address']);

            if (isset($created[$email])) {
                $this->record($import, $rowNumber, MailchimpImportRow::SUBSCRIBED, null, 200);

                continue;
            }

            $error = $errors->get($email);

            if (! $error) {
                $this->record(
                    $import,
                    $rowNumber,
                    MailchimpImportRow::FAILED,
                    'Mailchimp did not report an outcome for this contact.',
                );

                continue;
            }

            // A retry after a lost response re-sends a batch that Mailchimp already
            // accepted. Saying so is the truth; calling it a failure is not.
            if (str_contains(strtolower($error['message']), 'already a list member')) {
                $this->record($import, $rowNumber, MailchimpImportRow::ALREADY_MEMBER, $error['message']);

                continue;
            }

            $this->record($import, $rowNumber, MailchimpImportRow::FAILED, $error['message']);
        }

        // Numbered from zero. increment() is no use here — the column starts null,
        // and NULL + 1 is still NULL.
        $import->update(['last_batch_index' => $import->nextBatchIndex()]);

        $this->refreshCounts($import);
    }

    /**
     * @throws MailchimpApiException
     */
    protected function resubscribe(MailchimpImport $import, MailchimpApi $api, int $rowNumber, string $email): void
    {
        $refusal = $api->resubscribe($import->audience_id, $email);

        if ($refusal === null) {
            if (filled($import->tag)) {
                $api->tagMember($import->audience_id, $email, $import->tag);
            }

            $this->record($import, $rowNumber, MailchimpImportRow::RESUBSCRIBED, null, 200);

            return;
        }

        // Mailchimp holds this address in a compliance state — it once unsubscribed,
        // bounced, or was reviewed — and the API will not lift that. Where the import
        // carries a documented opt-in collected since, that opt-in is transcribed to
        // the audience's own hosted form, which is the route Mailchimp does accept.
        if ($this->relayStopped || ! $this->relay->permitted($import, $refusal)) {
            // Once the relay has stopped, a compliance refusal is left exactly as the
            // dry run classified it. Recording it as blocked would bury contacts the
            // form never got a chance to answer for.
            if (! $this->relayStopped) {
                $this->record($import, $rowNumber, MailchimpImportRow::BLOCKED_UNSUBSCRIBED, $refusal, 400);
            }

            return;
        }

        $this->relayToHostedForm($import, $api, $rowNumber, $email, $refusal);
    }

    /**
     * Submit the opt-in, waiting out a throttle, then ask Mailchimp what actually
     * happened.
     *
     * The form's own reply is not taken as proof: it answers about the submission,
     * not about the member. Only a fresh read of the member record can say whether
     * the contact is back, and a report that claims otherwise is worse than no
     * report at all.
     */
    protected function relayToHostedForm(
        MailchimpImport $import,
        MailchimpApi $api,
        int $rowNumber,
        string $email,
        string $refusal,
    ): void {
        $outcome = $this->withBackoff(
            $import,
            fn () => $this->relay->relay($import, $api, $rowNumber, $email, $refusal),
        );

        if ($outcome === OptInRelay::THROTTLED) {
            // The form is turning submissions away. This contact is left as the dry
            // run classified it so a later run tries again, and the rest of this run
            // stops asking.
            if (++$this->consecutiveThrottles >= self::THROTTLES_BEFORE_STOPPING) {
                $this->relayStopped = true;

                $this->note($import, "Mailchimp's signup form stopped accepting submissions. "
                    .'The contacts it did not take are left for a later run — send again in a few minutes.');
            }

            return;
        }

        $this->consecutiveThrottles = 0;
        $this->note($import, null);
    }

    /**
     * One relay attempt, retried through the backoff schedule while the form is
     * only asking us to come back later.
     *
     * @param  callable(): string  $attempt
     */
    protected function withBackoff(MailchimpImport $import, callable $attempt): string
    {
        $waits = self::THROTTLE_BACKOFF_SECONDS;

        while (true) {
            $outcome = $attempt();

            if ($outcome !== OptInRelay::THROTTLED || ! $waits) {
                // Paced whatever the answer was: the next contact is another
                // submission to the same public form either way.
                sleep(self::FORM_SUBMISSION_PAUSE_SECONDS);

                return $outcome;
            }

            $wait = (int) array_shift($waits);

            // Said out loud, because the alternative is a minute of silence that
            // looks exactly like a dead worker.
            $this->note($import, "Mailchimp's signup form is rate limiting. Waiting {$wait}s before trying again.");

            sleep($wait);
        }
    }

    /**
     * Leave a line on the import saying what this run is waiting for.
     *
     * Every write touches the record, which is what tells the preview page the run
     * is still alive even when no contact has been answered for in minutes.
     */
    protected function note(MailchimpImport $import, ?string $note): void
    {
        if ($import->progress_note === $note) {
            // Still true, but the page needs to see movement rather than sameness.
            $import->touch();

            return;
        }

        $import->update(['progress_note' => $note]);
    }

    /**
     * @param  array<string, string>  $map  CSV header => merge tag
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    protected function member(
        MailchimpImport $import,
        array $map,
        string $emailColumn,
        array $values,
        string $email,
    ): array {
        $merge = [];

        foreach ($map as $header => $tag) {
            if ($tag === 'EMAIL' || $header === $emailColumn) {
                continue;
            }

            $value = trim((string) ($values[$header] ?? ''));

            // Empty cells are left out rather than sent as blanks, so a sparse
            // column cannot overwrite anything with nothing.
            if ($value !== '') {
                $merge[$tag] = $value;
            }
        }

        return array_filter([
            'email_address' => $email,
            // pending on a double opt-in audience — Mailchimp rejects 'subscribed'
            // there, and the contact only counts once they confirm.
            'status' => $import->memberStatus(),
            'merge_fields' => $merge ?: null,
            'tags' => filled($import->tag) ? [$import->tag] : null,
        ], fn ($value) => $value !== null);
    }

    protected function record(
        MailchimpImport $import,
        int $rowNumber,
        string $outcome,
        ?string $detail = null,
        ?int $statusCode = null,
    ): void {
        $import->rows()->where('row_number', $rowNumber)->update([
            'outcome' => $outcome,
            'detail' => $detail,
            'mailchimp_status_code' => $statusCode,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Counters are rebuilt from the row log rather than incremented, so a resumed
     * run cannot double-count what an earlier attempt already recorded.
     */
    protected function refreshCounts(MailchimpImport $import): void
    {
        $counts = $import->outcomeCounts();

        $import->update([
            'subscribed_count' => $counts[MailchimpImportRow::SUBSCRIBED] ?? 0,
            'resubscribed_count' => ($counts[MailchimpImportRow::RESUBSCRIBED] ?? 0)
                + ($counts[MailchimpImportRow::RECOVERED_VIA_FORM] ?? 0),
            'failed_count' => $counts[MailchimpImportRow::FAILED] ?? 0,
            'skipped_count' => ($counts[MailchimpImportRow::ALREADY_MEMBER] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_UNSUBSCRIBED] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_INVALID] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_DUPLICATE] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_MISSING] ?? 0),
        ]);
    }
}
