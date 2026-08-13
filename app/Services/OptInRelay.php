<?php

namespace App\Services;

use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;

/**
 * Puts one recorded opt-in to the audience's hosted signup form and writes down
 * what came of it.
 *
 * Shared by the import run and the drip that picks up whatever the run could not
 * finish, because both have to answer the same question the same way — and getting
 * "did this contact come back" wrong in one of them would quietly corrupt the
 * report the other writes.
 */
class OptInRelay
{
    /** The contact is back on the list. */
    public const RECOVERED = 'recovered';

    /** The form turned this submission away for now; the contact is untouched. */
    public const THROTTLED = 'throttled';

    /** The form turned the contact down. */
    public const REFUSED = 'refused';

    /** No opt-in is documented for this import, so nothing is offered. */
    public const NOT_PERMITTED = 'not_permitted';

    public function __construct(protected MailchimpHostedForm $hostedForm) {}

    /**
     * Whether this refusal may be answered with the contact's recorded opt-in.
     *
     * Two things have to hold. The import must carry documented consent, so a run
     * without one cannot reach the form at all. And the refusal must be an ordinary
     * compliance state rather than a forgotten contact: "forgotten" and "permanently
     * deleted" are Mailchimp's words for a deletion request, and someone who asked
     * to be erased is not answered by pointing at a form they signed beforehand.
     */
    public function permitted(MailchimpImport $import, string $refusal): bool
    {
        if (blank($import->consent_details['wording'] ?? null)) {
            return false;
        }

        $refusal = strtolower($refusal);

        return ! str_contains($refusal, 'forgotten')
            && ! str_contains($refusal, 'permanently deleted');
    }

    /**
     * Submit the opt-in, then ask Mailchimp what actually happened.
     *
     * The form's own reply is not taken as proof: it answers about the submission,
     * not about the member. Only a fresh read of the member record can say whether
     * the contact is back, and a report that claims otherwise is worse than no
     * report at all.
     *
     * A throttled submission writes nothing. The row keeps the classification the
     * dry run gave it, so it stays outstanding and a later attempt picks it up —
     * recording it as blocked would bury a contact the form never answered for.
     */
    public function relay(
        MailchimpImport $import,
        MailchimpApi $api,
        int $rowNumber,
        string $email,
        string $refusal,
    ): string {
        if (! $this->permitted($import, $refusal)) {
            return self::NOT_PERMITTED;
        }

        $submission = $this->hostedForm->submit($import->account, $email);

        if ($submission['status'] === MailchimpHostedForm::THROTTLED) {
            return self::THROTTLED;
        }

        $key = strtolower(trim($email));
        $status = $api->memberStatusesFor($import->audience_id, [$key])[$key] ?? null;

        if (in_array($status, ['subscribed', 'pending'], true)) {
            if (filled($import->tag)) {
                $api->tagMember($import->audience_id, $email, $import->tag);
            }

            $this->record(
                $import,
                $rowNumber,
                MailchimpImportRow::RECOVERED_VIA_FORM,
                $status === 'pending' ? 'Awaiting their confirmation of the opt-in.' : null,
                200,
            );

            return self::RECOVERED;
        }

        // Genuinely turned down. Both answers are kept: Mailchimp's reason, and what
        // the form said when the opt-in was put to it.
        $this->record(
            $import,
            $rowNumber,
            MailchimpImportRow::BLOCKED_UNSUBSCRIBED,
            $refusal.' → signup form: '.($submission['detail'] ?? $submission['status']),
            400,
        );

        return self::REFUSED;
    }

    protected function record(
        MailchimpImport $import,
        int $rowNumber,
        string $outcome,
        ?string $detail,
        int $statusCode,
    ): void {
        $import->rows()->where('row_number', $rowNumber)->update([
            'outcome' => $outcome,
            'detail' => $detail,
            'mailchimp_status_code' => $statusCode,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
