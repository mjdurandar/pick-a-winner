<?php

namespace App\Services;

use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Models\TicketAttendee;

/**
 * Imports ONE finished location to Mailchimp and returns a normalised result.
 *
 * Extracted from AutoImportFinishedLocationsJob so both execution paths reuse the exact same
 * per-location logic:
 *   - the inline/scheduled path (AutoImportFinishedLocationsJob, dispatchSync — no worker needed)
 *   - the queued fan-out path (AutoImportLocationJob, one short job per location)
 *
 * This method does NOT catch fatal errors: it lets them bubble so the queued caller can retry a
 * single location. The inline caller wraps it in try/catch to record an "error" detail and keep going.
 * Re-running is safe — runForOneLocation upserts its MailchimpImportLog rows (updateOrCreate) and
 * Mailchimp add-or-update is keyed on the email hash, so retries never double-import.
 */
class AutoImportLocationRunner
{
    /**
     * Ticket rows carry the venue's city/state (not the contact's home address), so we only
     * map name — mirroring the manual "Import All" ticket mode in LocationPage.vue.
     */
    public const TICKET_FIELD_MAPPING = [
        'FNAME' => 'first_name',
        'LNAME' => 'last_name',
    ];

    /**
     * Full field mapping for win/sign-up-form rows (they carry the contact's real address).
     * Mirrors the tagToDefault map in LocationPage.vue. SMSPHONE is intentionally omitted.
     */
    public const FORM_FIELD_MAPPING = [
        'FNAME' => 'first_name', 'LNAME' => 'last_name',
        'PHONE' => 'mobile_number', 'MERGE4' => 'mobile_number', 'MERGE30' => 'mobile_number',
        'ADDRESSWIN' => 'address_full', 'MMERGE10' => 'address_full', 'MERGE10' => 'address_full', 'MERGE11' => 'address_full',
        'SHOWCITY' => 'city', 'CITY' => 'city', 'MERGE3' => 'city', 'MERGE5' => 'city',
        'STATEWIN' => 'state', 'STATE' => 'state', 'MERGE6' => 'state',
        'ZIPCODEWIN' => 'zip_code', 'ZIPCODE' => 'zip_code', 'MERGE7' => 'zip_code',
        'COUNTRYWIN' => 'country', 'COUNTRY' => 'country', 'MERGE8' => 'country',
        'GENDER' => 'gender', 'MERGE17' => 'gender',
        'AGEWIN' => 'age', 'MERGE14' => 'age', 'MMERGE14' => 'age',
    ];

    /**
     * @param  array{event_id:int, event_name:?string, location_id:int, location_name:string, list_id:string, list_name:?string, account:string}  $item
     * @return array{detail: array<string, mixed>, imported: int, skipped: int, new: int, updated: int, errors: int}
     */
    public function importOneLocation(array $item): array
    {
        $locationId = (int) $item['location_id'];
        $eventId = (int) $item['event_id'];
        $listId = (string) $item['list_id'];
        $account = (string) $item['account'];
        $listName = $item['list_name'] ?? null;
        $eventName = $item['event_name'] ?? null;

        $location = Location::with('event')->find($locationId);
        if (! $location) {
            return [
                'detail' => [
                    'location_id' => $locationId,
                    'location_name' => $item['location_name'] ?? (string) $locationId,
                    'event_id' => $eventId,
                    'event_name' => $eventName,
                    'status' => 'skipped',
                    'reason' => 'location not found',
                ],
                'imported' => 0, 'skipped' => 1, 'new' => 0, 'updated' => 0, 'errors' => 0,
            ];
        }

        $service = new MailchimpLocationImportService;
        $tagBuilder = app(AutoMailchimpService::class);

        $attendees = TicketAttendee::where('location_id', $locationId)->get()->map(function ($a) {
            return [
                'email' => $a->email,
                'first_name' => $a->first_name ?? '',
                'last_name' => $a->last_name ?? '',
                'phone' => $a->phone ?? '',
                'city' => $a->city ?? '',
                'state' => $a->state ?? '',
                'country' => $a->country ?? '',
            ];
        })->all();

        // Ticket and form run as separate imports because they need different field mappings
        // (ticket = name only; form = full address). Each writes its own per-source log line.
        $ticketResult = ['imported' => 0, 'skipped' => true];
        if (! empty($attendees)) {
            $ticketResult = $service->runForOneLocation($eventId, $listId, $account, $listName, [
                'location_id' => $locationId,
                'import_ticket' => true,
                'import_form' => false,
                'attendees' => $attendees,
                'tags' => $tagBuilder->buildLocationTags($location, 'ticket'),
                'form_tags' => [],
            ], 0, self::TICKET_FIELD_MAPPING);
        }

        $formResult = $service->runForOneLocation($eventId, $listId, $account, $listName, [
            'location_id' => $locationId,
            'import_ticket' => false,
            'import_form' => true,
            'attendees' => [],
            'tags' => [],
            'form_tags' => $tagBuilder->buildLocationTags($location, 'form'),
        ], 0, self::FORM_FIELD_MAPPING);

        if ($ticketResult['skipped'] && $formResult['skipped']) {
            return [
                'detail' => [
                    'location_id' => $locationId,
                    'location_name' => $location->name,
                    'event_id' => $eventId,
                    'event_name' => $eventName ?? $location->event->event_name ?? null,
                    'status' => 'skipped',
                    'reason' => 'no ticket or form data',
                ],
                'imported' => 0, 'skipped' => 1, 'new' => 0, 'updated' => 0, 'errors' => 0,
            ];
        }

        // Read back the per-source log rows this import just wrote/updated for accurate counts.
        $logs = MailchimpImportLog::where('location_id', $locationId)
            ->where('list_id', $listId)
            ->where('mailchimp_account', $account)
            ->whereIn('source', ['ticket_data', 'signup_form'])
            ->get();

        $new = (int) $logs->sum('new_contacts');
        $updated = (int) $logs->sum('updated_data');
        $errors = (int) $logs->sum('data_with_error');

        return [
            'detail' => [
                'location_id' => $locationId,
                'location_name' => $location->name,
                'event_id' => $eventId,
                'event_name' => $eventName ?? $location->event->event_name ?? null,
                'list_name' => $listName,
                'account' => $account,
                'status' => 'imported',
                'new' => $new,
                'updated' => $updated,
                'errors' => $errors,
                'sources' => $logs->pluck('source')->values()->all(),
            ],
            'imported' => 1, 'skipped' => 0, 'new' => $new, 'updated' => $updated, 'errors' => $errors,
        ];
    }
}
