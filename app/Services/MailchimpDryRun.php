<?php

namespace App\Services;

use App\Exceptions\CsvImportException;
use App\Exceptions\MailchimpApiException;
use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;

/**
 * Classifies every row of an uploaded CSV against the destination audience
 * without sending anything to Mailchimp.
 *
 * The audience is read once into a lookup and the file is streamed past it, so a
 * hundred-thousand-row import costs a request per thousand audience members
 * rather than a request per row.
 *
 * Nothing here writes to Mailchimp. The only outward call is the read that builds
 * the member index.
 */
class MailchimpDryRun
{
    /** Rows buffered before each insert, to keep one statement per chunk. */
    private const INSERT_CHUNK = 500;

    /**
     * Above this many distinct addresses, reading the whole audience costs less than
     * asking about each one. Set where ten-at-a-time lookups stop beating a page per
     * thousand members for the audiences this app imports into.
     */
    private const TARGETED_LOOKUP_LIMIT = 500;

    /**
     * Mailchimp member states that mean the contact is present and already
     * receiving email. Importing them again would change nothing, so they are
     * recorded and left alone.
     *
     * 'pending' belongs here: they have been sent a double opt-in invitation and
     * re-importing would send it a second time.
     */
    private const PRESENT_STATUSES = ['subscribed', 'pending'];

    public function __construct(protected CsvImportParser $parser) {}

    /**
     * @return array<string, int> outcome => count
     *
     * @throws CsvImportException
     * @throws MailchimpApiException
     */
    public function run(MailchimpImport $import, MailchimpApi $api, string $path): array
    {
        $emailColumn = $this->emailColumn($import);
        $existing = $this->existingMembers($import, $api, $path, $emailColumn);

        // A second dry run — after the admin went back and changed the mapping —
        // must replace the previous classification outright, not merge with it.
        $import->rows()->delete();

        $seen = [];
        $buffer = [];
        $counts = array_fill_keys(MailchimpImportRow::DRY_RUN_OUTCOMES, 0);
        $now = now();

        foreach ($this->parser->rows($path) as [$rowNumber, $values]) {
            $raw = trim((string) ($values[$emailColumn] ?? ''));
            $email = strtolower($raw);

            [$outcome, $detail] = $this->classify($raw, $email, $seen, $existing);

            if ($outcome !== MailchimpImportRow::BLOCKED_MISSING && $outcome !== MailchimpImportRow::BLOCKED_INVALID) {
                $seen[$email] = $rowNumber;
            }

            $counts[$outcome]++;

            $buffer[] = [
                'import_id' => $import->id,
                'row_number' => $rowNumber,
                'email' => $raw === '' ? null : $raw,
                'outcome' => $outcome,
                'detail' => $detail,
                'mailchimp_status_code' => null,
                'processed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::INSERT_CHUNK) {
                MailchimpImportRow::insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            MailchimpImportRow::insert($buffer);
        }

        return $counts;
    }

    /**
     * Which of these contacts Mailchimp already knows, by whichever route costs
     * less.
     *
     * Reading the whole audience is a request per thousand members no matter how
     * small the file, which is most of a minute against a large audience and was
     * long enough to be killed by the queue's per-job process timeout. Asking about
     * named addresses instead is a request each, run ten at a time — far cheaper
     * until the file gets big, at which point paging wins again.
     *
     * @return array<string, string> lowercase email => Mailchimp status
     *
     * @throws CsvImportException
     * @throws MailchimpApiException
     */
    protected function existingMembers(
        MailchimpImport $import,
        MailchimpApi $api,
        string $path,
        string $emailColumn,
    ): array {
        $emails = [];

        foreach ($this->parser->rows($path) as [, $values]) {
            $email = strtolower(trim((string) ($values[$emailColumn] ?? '')));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Keyed, so a file that repeats an address only asks about it once.
                $emails[$email] = true;
            }

            if (count($emails) > self::TARGETED_LOOKUP_LIMIT) {
                return $api->memberStatusIndex($import->audience_id);
            }
        }

        return $api->memberStatusesFor($import->audience_id, array_keys($emails));
    }

    /**
     * @param  array<string, int>  $seen  lowercase email => row number already taken
     * @param  array<string, string>  $existing  lowercase email => Mailchimp status
     * @return array{0: string, 1: ?string}
     */
    protected function classify(string $raw, string $email, array $seen, array $existing): array
    {
        if ($raw === '') {
            return [MailchimpImportRow::BLOCKED_MISSING, 'No email address in this row.'];
        }

        if (! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [MailchimpImportRow::BLOCKED_INVALID, 'Not a valid email address.'];
        }

        // The first occurrence is kept and imported; later ones are blocked so the
        // same contact is not sent twice in one batch.
        if (isset($seen[$email])) {
            return [
                MailchimpImportRow::BLOCKED_DUPLICATE,
                'Same address as row '.$seen[$email].'.',
            ];
        }

        $status = $existing[$email] ?? null;

        if ($status === null) {
            return [MailchimpImportRow::WILL_SUBSCRIBE, null];
        }

        if (in_array($status, self::PRESENT_STATUSES, true)) {
            return [
                MailchimpImportRow::ALREADY_MEMBER,
                $status === 'pending'
                    ? 'Already invited and waiting to confirm.'
                    : 'Already subscribed to this audience.',
            ];
        }

        // Unsubscribed, cleaned, archived or transactional. Whether Mailchimp will
        // actually accept the change is only knowable by attempting it, so this is
        // recorded optimistically and the run reports what came back.
        return [
            MailchimpImportRow::WILL_RESUBSCRIBE,
            'Currently '.$status.' in this audience.',
        ];
    }

    /**
     * The CSV header the admin mapped to EMAIL. configure() will not save a map
     * without one, so a missing column here means the record was tampered with.
     *
     * @throws CsvImportException
     */
    protected function emailColumn(MailchimpImport $import): string
    {
        $column = array_search('EMAIL', $import->field_map ?? [], true);

        if ($column === false) {
            throw new CsvImportException('No column is mapped to the email address.');
        }

        return (string) $column;
    }
}
