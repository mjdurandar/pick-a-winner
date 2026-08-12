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
    public function __construct(protected CsvImportParser $parser) {}

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

        foreach ($this->parser->rows($path) as [$rowNumber, $values]) {
            $outcome = $outcomes[$rowNumber] ?? null;

            if ($outcome === null) {
                continue;
            }

            $email = trim((string) ($values[$emailColumn] ?? ''));

            if ($outcome === MailchimpImportRow::WILL_RESUBSCRIBE) {
                $this->resubscribe($import, $api, $rowNumber, $email);

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
        } else {
            // Mailchimp holds this address in a compliance state. Nothing the app can
            // send will restore it; only the contact can opt back in themselves. Its
            // own explanation is kept so the report can say why.
            $this->record($import, $rowNumber, MailchimpImportRow::BLOCKED_UNSUBSCRIBED, $refusal, 400);
        }

        $this->refreshCounts($import);
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
            'resubscribed_count' => $counts[MailchimpImportRow::RESUBSCRIBED] ?? 0,
            'failed_count' => $counts[MailchimpImportRow::FAILED] ?? 0,
            'skipped_count' => ($counts[MailchimpImportRow::ALREADY_MEMBER] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_UNSUBSCRIBED] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_INVALID] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_DUPLICATE] ?? 0)
                + ($counts[MailchimpImportRow::BLOCKED_MISSING] ?? 0),
        ]);
    }
}
