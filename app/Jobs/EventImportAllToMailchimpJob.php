<?php

namespace App\Jobs;

use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use App\Services\MailchimpLogService;
use App\Services\MailchimpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class EventImportAllToMailchimpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Job timeout in seconds (10 minutes per run; queue worker may run multiple jobs) */
    public $timeout = 600;

    public function __construct(
        public int $eventId,
        public string $listId,
        public string $mailchimpAccount,
        public ?string $listName,
        public array $locationsPayload,
        public ?int $userId
    ) {}

    public function handle(MailchimpLogService $logService): void
    {
        try {
            $mailchimpService = new MailchimpService($this->mailchimpAccount);
        } catch (\Exception $e) {
            Log::error('Event import all (job): MailchimpService init failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        foreach ($this->locationsPayload as $loc) {
            $locationId = (int) ($loc['location_id'] ?? 0);
            $attendees = $loc['attendees'] ?? [];
            $tags = $loc['tags'] ?? [];
            $formTags = $loc['form_tags'] ?? [];

            $location = Location::with('event')->find($locationId);
            if (!$location || (int) $location->event_id !== $this->eventId) {
                Log::warning('EventImportAllToMailchimpJob: location does not belong to event', [
                    'location_id' => $locationId,
                    'event_id' => $this->eventId,
                ]);
                continue;
            }

            // --- Ticket data import ---
            if (!empty($attendees) && !empty($tags)) {
                $this->importTicketData($mailchimpService, $logService, $location, $attendees, $tags);
            }

            // --- Win form (sign-up) data import ---
            if (!empty($formTags)) {
                $this->importFormData($mailchimpService, $logService, $location, $formTags);
            }
        }

        Log::info('Event import all (job) completed', [
            'event_id' => $this->eventId,
            'locations_count' => count($this->locationsPayload),
        ]);
    }

    private function importTicketData(
        MailchimpService $mailchimpService,
        MailchimpLogService $logService,
        Location $location,
        array $attendees,
        array $tags
    ): void {
        $locationId = $location->id;

        TicketAttendee::where('location_id', $locationId)->delete();
        $seenEmails = [];
        foreach ($attendees as $a) {
            $email = strtolower(trim($a['email'] ?? ''));
            if ($email === '' || isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;
            TicketAttendee::create([
                'location_id' => $locationId,
                'event_id' => $location->event_id,
                'email' => $email,
                'first_name' => $a['first_name'] ?? '',
                'last_name' => $a['last_name'] ?? '',
                'phone' => $a['phone'] ?? $a['mobile_number'] ?? '',
                'city' => $a['city'] ?? '',
                'state' => $a['state'] ?? '',
                'country' => $a['country'] ?? '',
            ]);
        }

        $subscribers = [];
        foreach ($attendees as $a) {
            $email = strtolower(trim($a['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $subscribers[] = [
                'email_address' => $email,
                'first_name' => $a['first_name'] ?? '',
                'last_name' => $a['last_name'] ?? '',
                'mobile_number' => $a['phone'] ?? $a['mobile_number'] ?? '',
            ];
        }

        $locSuccess = 0;
        $locFailed = 0;
        $locNew = 0;
        $locUpdated = 0;
        $locErrors = [];
        $listId = $this->listId;

        foreach ($subscribers as $subscriber) {
            try {
                if (empty($subscriber['email_address'])) {
                    $locFailed++;
                    $locErrors[] = 'Skipped: Missing email';
                    continue;
                }
                $result = $mailchimpService->manualImportSubscriber(
                    $listId,
                    [
                        'email_address' => $subscriber['email_address'],
                        'first_name' => $subscriber['first_name'] ?? '',
                        'last_name' => $subscriber['last_name'] ?? '',
                        'mobile_number' => $subscriber['mobile_number'] ?? '',
                    ],
                    $tags
                );
                $locSuccess++;
                if (isset($result['import_type'])) {
                    if ($result['import_type'] === 'new') {
                        $locNew++;
                    } elseif ($result['import_type'] === 'updated') {
                        $locUpdated++;
                    }
                }
                $logService->logImport($location->id, $location->name, [
                    'success' => true,
                    'email' => $subscriber['email_address'],
                    'tags' => $tags,
                    'source' => 'eventbrite',
                ]);
            } catch (\Exception $e) {
                $locFailed++;
                $locErrors[] = substr("{$subscriber['email_address']}: " . $e->getMessage(), 0, 200);
                $logService->logImport($location->id, $location->name, [
                    'success' => false,
                    'email' => $subscriber['email_address'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'tags' => $tags,
                    'source' => 'eventbrite',
                ]);
            }
        }

        $ticketLog = MailchimpImportLog::create([
            'location_id' => $locationId,
            'imported_by' => $this->userId,
            'total_data' => count($subscribers),
            'new_contacts' => $locNew,
            'updated_data' => $locUpdated,
            'data_with_error' => $locFailed,
            'errors' => array_slice($locErrors, 0, 50),
            'tags' => $tags,
            'source' => 'ticket_data',
            'mailchimp_account' => $this->mailchimpAccount,
            'list_id' => $this->listId,
            'list_name' => $this->listName,
        ]);

        $csvHeaders = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];
        $escapeCsv = function ($v) {
            $s = $v === null || $v === '' ? '' : (string) $v;
            return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $ticketRows = [];
        foreach ($attendees as $a) {
            $ticketRows[] = [
                'email_address' => $a['email'] ?? '',
                'first_name' => $a['first_name'] ?? '',
                'last_name' => $a['last_name'] ?? '',
                'mobile_number' => $a['phone'] ?? $a['mobile_number'] ?? '',
                'street_address' => $a['street_address'] ?? '',
                'street_address_2' => $a['street_address_2'] ?? '',
                'city' => $a['city'] ?? '',
                'state' => $a['state'] ?? '',
                'zip_code' => $a['zip_code'] ?? $a['postal_code'] ?? '',
                'country' => $a['country'] ?? '',
                'gender' => $a['gender'] ?? '',
                'age' => $a['age'] ?? '',
            ];
        }
        if (!empty($ticketRows)) {
            $lines = [implode(',', $csvHeaders)];
            foreach ($ticketRows as $row) {
                $lines[] = implode(',', array_map(function ($key) use ($row, $escapeCsv) {
                    return $escapeCsv($row[$key] ?? '');
                }, $csvHeaders));
            }
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines);
            Storage::disk('local')->put('mailchimp_imports/' . $ticketLog->id . '.csv', $csv);
            $ticketLog->update(['has_import_file' => true]);
        }
    }

    private function importFormData(
        MailchimpService $mailchimpService,
        MailchimpLogService $logService,
        Location $location,
        array $formTags
    ): void {
        $eventId = $this->eventId;
        $locationId = $location->id;
        $signUpForm = SignUpForm::where('event_id', $eventId)->first();

        if (!$signUpForm || !$signUpForm->table_name || !Schema::hasTable($signUpForm->table_name)) {
            return;
        }

        $signUpRows = DB::table($signUpForm->table_name)
            ->where('location_id', $locationId)
            ->where('event_id', $eventId)
            ->get();

        $locFormSuccess = 0;
        $locFormFailed = 0;
        $locFormNew = 0;
        $locFormUpdated = 0;
        $locFormErrors = [];
        $listId = $this->listId;

        foreach ($signUpRows as $row) {
            $email = trim((string) ($row->email_address ?? ''));
            if ($email === '') {
                continue;
            }
            $subscriber = [
                'email_address' => $email,
                'first_name' => $row->first_name ?? '',
                'last_name' => $row->last_name ?? '',
                'mobile_number' => $row->mobile_number ?? $row->phone ?? '',
                'street_address' => $row->street_address ?? '',
                'street_address_2' => $row->street_address_2 ?? '',
                'city' => $row->city ?? '',
                'state' => $row->state ?? '',
                'zip_code' => $row->zip_code ?? $row->postal_code ?? '',
                'country' => $row->country ?? '',
                'gender' => $row->gender ?? '',
                'age' => $row->age ?? '',
            ];
            try {
                $result = $mailchimpService->manualImportSubscriber($listId, $subscriber, $formTags);
                $locFormSuccess++;
                if (isset($result['import_type'])) {
                    if ($result['import_type'] === 'new') {
                        $locFormNew++;
                    } elseif ($result['import_type'] === 'updated') {
                        $locFormUpdated++;
                    }
                }
                $logService->logImport($location->id, $location->name, [
                    'success' => true,
                    'email' => $subscriber['email_address'],
                    'tags' => $formTags,
                    'source' => 'signup_form',
                ]);
            } catch (\Exception $e) {
                $locFormFailed++;
                $locFormErrors[] = substr("{$subscriber['email_address']}: " . $e->getMessage(), 0, 200);
                $logService->logImport($location->id, $location->name, [
                    'success' => false,
                    'email' => $subscriber['email_address'],
                    'error' => $e->getMessage(),
                    'tags' => $formTags,
                    'source' => 'signup_form',
                ]);
            }
        }

        $formLog = MailchimpImportLog::create([
            'location_id' => $locationId,
            'imported_by' => $this->userId,
            'total_data' => count($signUpRows),
            'new_contacts' => $locFormNew,
            'updated_data' => $locFormUpdated,
            'data_with_error' => $locFormFailed,
            'errors' => array_slice($locFormErrors, 0, 50),
            'tags' => $formTags,
            'source' => 'signup_form',
            'mailchimp_account' => $this->mailchimpAccount,
            'list_id' => $this->listId,
            'list_name' => $this->listName,
        ]);

        $csvHeadersForm = ['email_address', 'first_name', 'last_name', 'mobile_number', 'street_address', 'street_address_2', 'city', 'state', 'zip_code', 'country', 'gender', 'age'];
        $escapeCsvForm = function ($v) {
            $s = $v === null || $v === '' ? '' : (string) $v;
            return strpos($s, ',') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false
                ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        if (!empty($signUpRows)) {
            $linesForm = [implode(',', $csvHeadersForm)];
            foreach ($signUpRows as $row) {
                $r = [
                    'email_address' => $row->email_address ?? '',
                    'first_name' => $row->first_name ?? '',
                    'last_name' => $row->last_name ?? '',
                    'mobile_number' => $row->mobile_number ?? $row->phone ?? '',
                    'street_address' => $row->street_address ?? '',
                    'street_address_2' => $row->street_address_2 ?? '',
                    'city' => $row->city ?? '',
                    'state' => $row->state ?? '',
                    'zip_code' => $row->zip_code ?? $row->postal_code ?? '',
                    'country' => $row->country ?? '',
                    'gender' => $row->gender ?? '',
                    'age' => $row->age ?? '',
                ];
                $linesForm[] = implode(',', array_map(function ($key) use ($r, $escapeCsvForm) {
                    return $escapeCsvForm($r[$key] ?? '');
                }, $csvHeadersForm));
            }
            $csvForm = "\xEF\xBB\xBF" . implode("\r\n", $linesForm);
            Storage::disk('local')->put('mailchimp_imports/' . $formLog->id . '.csv', $csvForm);
            $formLog->update(['has_import_file' => true]);
        }
    }
}
