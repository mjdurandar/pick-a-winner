<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\Location;
use App\Models\MailchimpImportLog;
use App\Models\NewsletterResubscribeAttempt;
use App\Models\SignUpForm;
use App\Services\MailchimpHostedForm;
use App\Services\MailchimpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignUpFormController extends Controller
{
    protected $autoMailchimpService;

    protected MailchimpHostedForm $hostedForm;

    public function __construct(
        \App\Services\AutoMailchimpService $autoMailchimpService,
        MailchimpHostedForm $hostedForm,
    ) {
        $this->autoMailchimpService = $autoMailchimpService;
        $this->hostedForm = $hostedForm;
    }

    // Newsletter audience names per MC account
    const ANZ_NEWSLETTER_AUDIENCE = 'Adventure Entertainment Newsletter ANZ';

    const USA_NEWSLETTER_AUDIENCE = 'Fly Fishing Film Tour';

    // The hosted form each account falls back to now lives in
    // config/services.php under mailchimp.hosted_form, so the link shown to a
    // compliance-locked contact and the form the server relays to cannot drift apart.

    /**
     * Determine the Mailchimp account based on event country.
     * ANZ countries → 'anz', USA countries → 'usa'
     */
    private function getMailchimpAccountForEvent(Events $event): string
    {
        $usaCountries = ['USA', 'USA & CANADA', 'Canada'];

        return in_array($event->event_country, $usaCountries) ? 'usa' : 'anz';
    }

    /**
     * Get the newsletter audience name for a given MC account.
     */
    private function getNewsletterAudienceName(string $account): string
    {
        return $account === 'usa' ? self::USA_NEWSLETTER_AUDIENCE : self::ANZ_NEWSLETTER_AUDIENCE;
    }

    /**
     * Cache key holding pending newsletter resubscribes for an email until the
     * user submits the form and we know which location they picked.
     */
    private function pendingResubCacheKey(int $eventId, string $email): string
    {
        return 'newsletter_resub_pending:'.$eventId.':'.md5(strtolower(trim($email)));
    }

    /**
     * Record what happened to one contact we tried to bring back, successes and
     * refusals alike.
     *
     * The per-location counters below only ever counted successes, so a refused
     * contact left no trace at all. This is what makes a "could not resubscribe"
     * report possible, per event and so per film.
     */
    private function recordResubscribeAttempt(
        Events $event,
        string $email,
        string $outcome,
        array $info,
        ?string $detail = null,
        ?int $locationId = null,
    ): void {
        try {
            NewsletterResubscribeAttempt::create([
                'event_id' => $event->id,
                'location_id' => $locationId,
                'email' => $email,
                'mailchimp_account' => $info['account'] ?? null,
                'list_id' => $info['list_id'] ?? null,
                'list_name' => $info['list_name'] ?? null,
                'outcome' => $outcome,
                'detail' => $detail,
            ]);
        } catch (\Exception $e) {
            // Reporting must never cost someone their entry to the draw.
            \Illuminate\Support\Facades\Log::error('Could not record newsletter resubscribe attempt', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How the hosted form's answer is recorded in the report.
     *
     * Only Mailchimp saying the contact is on the list counts as a resubscribe. A
     * confirmation email is a step towards one, not one — the contact still has to
     * click it — and reporting it as success would overstate what happened.
     */
    private function outcomeForHostedForm(string $status): string
    {
        return match ($status) {
            MailchimpHostedForm::ACCEPTED,
            MailchimpHostedForm::ALREADY_SUBSCRIBED => NewsletterResubscribeAttempt::RESUBSCRIBED,
            MailchimpHostedForm::CONFIRMATION_SENT => NewsletterResubscribeAttempt::CONFIRMATION_SENT,
            // The form was busy, which says nothing about this contact. Filing it as
            // blocked would write off someone who just opted in at a venue — and on a
            // busy night that is most of the queue, since the form starts refusing
            // after a couple of dozen. Deferred instead, and the drip retries it.
            MailchimpHostedForm::THROTTLED => NewsletterResubscribeAttempt::DEFERRED,
            // Rejected or not configured: the contact is still stuck where they were,
            // so the report must keep saying so.
            default => NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
        };
    }

    /**
     * Increment a per-location, per-day Mailchimp import log row tracking
     * how many newsletter resubscribes happened on the signup form.
     */
    private function recordNewsletterResubscribe(?int $locationId, array $info): void
    {
        if (! $locationId) {
            return;
        }
        $log = MailchimpImportLog::where('location_id', $locationId)
            ->where('source', 'signup_form_resub')
            ->where('list_id', $info['list_id'] ?? null)
            ->whereDate('created_at', now()->toDateString())
            ->first();
        if ($log) {
            $log->increment('total_resubscribed');
            $log->increment('total_data');

            return;
        }
        MailchimpImportLog::create([
            'location_id' => $locationId,
            'source' => 'signup_form_resub',
            'mailchimp_account' => $info['account'] ?? null,
            'list_id' => $info['list_id'] ?? null,
            'list_name' => $info['list_name'] ?? null,
            'status' => 'import',
            'total_data' => 1,
            'new_contacts' => 0,
            'updated_data' => 0,
            'data_with_error' => 0,
            'total_resubscribed' => 1,
        ]);
    }

    /**
     * Find the newsletter audience list ID by name from Mailchimp.
     *
     * The audience → list ID mapping is effectively static, but this runs on the
     * public checkSubscription endpoint (fired repeatedly as users type), so calling
     * getLists() every time floods Mailchimp's 10-concurrent-connection limit and
     * triggers 429s. Cache the resolved ID so bursts of checks reuse one API call.
     */
    private function findNewsletterListId(MailchimpService $mailchimpService, string $audienceName): ?string
    {
        $cacheKey = 'mailchimp_newsletter_list_id:'.md5($audienceName);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $lists = $mailchimpService->getLists();
        foreach ($lists as $list) {
            if ($list['name'] === $audienceName) {
                Cache::put($cacheKey, $list['id'], now()->addHours(6));

                return $list['id'];
            }
        }

        return null;
    }

    /**
     * Check if an email is subscribed to the newsletter audience.
     * Uses event_country to determine which MC account and audience to check:
     *   ANZ → "Adventure Entertainment Newsletter ANZ"
     *   USA → "Fly Fishing Film Tour"
     *
     * Logic:
     *   - Email exists + subscribed → allow (subscribed: true)
     *   - Email exists + unsubscribed/cleaned/etc → block (subscribed: false, needs resub)
     *   - Email not found → new user, allow through (subscribed: true)
     */
    public function checkSubscription(Request $request, $event_uuid)
    {
        $request->validate(['email' => 'required|email']);

        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();

        $account = $this->getMailchimpAccountForEvent($event);
        $audienceName = $this->getNewsletterAudienceName($account);
        $mailchimpService = new MailchimpService($account);

        try {
            $listId = $this->findNewsletterListId($mailchimpService, $audienceName);

            if (! $listId) {
                \Illuminate\Support\Facades\Log::warning('Newsletter audience not found', [
                    'account' => $account,
                    'audience_name' => $audienceName,
                ]);

                return response()->json([
                    'subscribed' => true,
                    'status' => 'audience_not_found',
                ]);
            }

            $result = $mailchimpService->getSubscriberStatus($listId, $request->email);

            // Email not in the audience at all → new user, allow through
            if (! $result['exists']) {
                return response()->json([
                    'subscribed' => true,
                    'status' => 'new_user',
                ]);
            }

            // Exists and subscribed → all good
            if ($result['status'] === 'subscribed') {
                return response()->json([
                    'subscribed' => true,
                    'status' => 'subscribed',
                ]);
            }

            // Try to resubscribe now to detect compliance state early
            $isCompliance = false;
            $resubSucceeded = false;
            $listInfo = ['account' => $account, 'list_id' => $listId, 'list_name' => $audienceName];

            try {
                $resubResult = $mailchimpService->resubscribe($listId, $request->email);
                if (is_array($resubResult) && ($resubResult['status'] ?? null) === 'compliance_skipped') {
                    $isCompliance = true;
                    $this->recordResubscribeAttempt(
                        $event,
                        $request->email,
                        NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
                        $listInfo,
                        $resubResult['detail'] ?? null,
                    );
                } else {
                    $resubSucceeded = true;
                    $this->recordResubscribeAttempt(
                        $event,
                        $request->email,
                        NewsletterResubscribeAttempt::RESUBSCRIBED,
                        $listInfo,
                    );
                }
            } catch (\Exception $e) {
                // Resubscribe failed for other reasons — not compliance. Recorded
                // rather than discarded, so a run of these is visible instead of
                // looking like nobody ever needed bringing back.
                $this->recordResubscribeAttempt(
                    $event,
                    $request->email,
                    NewsletterResubscribeAttempt::FAILED,
                    $listInfo,
                    $e->getMessage(),
                );
            }

            // Stash a pending resub so storeEmbeddedData can attribute it to the
            // location the user picks before they submit the form.
            if ($resubSucceeded) {
                Cache::put(
                    $this->pendingResubCacheKey($event->id, $request->email),
                    [
                        'account' => $account,
                        'list_id' => $listId,
                        'list_name' => $audienceName,
                    ],
                    now()->addMinutes(60)
                );
            }

            // Get the Mailchimp signup URL for compliance state members
            $mailchimpSignupUrl = null;
            if ($isCompliance) {
                // First check if manually set on the form
                $form = SignUpForm::where('event_id', $event->id)->first();
                $mailchimpSignupUrl = $form->mailchimp_signup_url ?? null;

                // Otherwise the configured hosted form for this account — the same one
                // the server relays to on submit — and only if that is missing does it
                // fall back to whatever Mailchimp reports for the audience.
                if (! $mailchimpSignupUrl) {
                    $mailchimpSignupUrl = $this->hostedForm->subscribeUrl($account)
                        ?: $mailchimpService->getListSignupUrl($listId);
                }
            }

            // Exists but unsubscribed/cleaned/archived
            // If compliance state, they must self-subscribe via Mailchimp form
            // Otherwise, they were auto-resubscribed just now
            return response()->json([
                'subscribed' => ! $isCompliance,
                'status' => $isCompliance ? 'compliance' : 'resubscribed',
                'audience' => $audienceName,
                'compliance_state' => $isCompliance,
                'mailchimp_signup_url' => $mailchimpSignupUrl,
                'mailchimp_account' => $account,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Mailchimp subscription check failed', [
                'email' => $request->email,
                'account' => $account,
                'audience' => $audienceName,
                'error' => $e->getMessage(),
            ]);

            // On error, allow entry (don't block users due to API issues)
            return response()->json([
                'subscribed' => true,
                'status' => 'check_failed',
            ]);
        }
    }

    // Show the signup form page
    public function index($eventId)
    {
        $eventId = (int) $eventId;
        // Find the signup form for the given event
        $form = SignUpForm::where('event_id', $eventId)->first();
        $eventValues = Events::where('id', $eventId)->first();
        $locations = Location::where('event_id', $eventId)->get();

        return inertia('SignUpForm', [
            'eventId' => $eventId,
            'eventValues' => $eventValues,
            'form' => $form, // ✅ Pass the form data to Vue
            'locations' => $locations, // ✅ Pass locations to Vue
        ]);
    }

    public function create($eventId)
    {
        $eventId = (int) $eventId;
        $event = Events::where('id', $eventId)->first();
        $locations = Location::where('event_id', $eventId)->get();

        return inertia('SignUpFormCreate', ['events' => $eventId, 'locations' => $locations, 'eventValues' => $event]);
    }

    // Generate a new sign up form with default questions
    public function generate(Request $request, $eventId)
    {
        // dd($request->all());
        // Fetch event details
        $event = DB::table('events')->where('id', $eventId)->first();
        // Format the table name: `event_name_date_created`
        $eventName = Str::slug($event->event_name, '_');
        $tableName = $eventName.'_'.now()->format('Y_m_d');

        $questions = $request->questions;

        // ✅ CREATE TABLE BASED ON COLUMN NAMES FROM QUESTIONS
        Schema::create($tableName, function (Blueprint $table) use ($questions) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->timestamps(); // ✅ Add timestamps

            // ✅ Loop through questions and create columns
            foreach ($questions as $question) {
                $columnName = $question['column_name']; // Use provided column name

                // ✅ Define column type based on question type
                switch ($question['type']) {
                    case 'text':
                    case 'email':
                        $table->string($columnName)->nullable();
                        break;
                    case 'textarea':
                        $table->longText($columnName)->nullable();
                        break;
                    case 'number':
                        $table->string($columnName)->nullable(); // Store as string to maintain format
                        break;
                    case 'date':
                        $table->date($columnName)->nullable();
                        break;
                    case 'dropdown':
                        if (isset($question['allowMultiple']) && $question['allowMultiple']) {
                            $table->longText($columnName)->nullable();
                        } else {
                            $table->string($columnName)->nullable();
                        }
                        break;
                }
            }
        });

        // Create the signup form
        SignUpForm::create([
            'event_id' => $eventId,
            'event_description' => $request->descriptionText,
            'privacy_link' => $request->policyLink,
            'terms_link' => $request->termsLink,
            'heading' => $request->headerText,
            'table_name' => $tableName,
            'questions' => json_encode($questions),
            'mailchimp_signup_url' => $request->mailchimpSignupUrl,
        ]);

        // ✅ Extract Location Names from the form (if any)
        $locationNames = [];
        foreach ($questions as $question) {
            if (stripos($question['column_name'], 'events_location') !== false && $question['type'] === 'dropdown') {
                $locationNames = array_merge($locationNames, $question['options']);
            }
        }

        // ✅ Store unique locations in the `locations` table
        $locationNames = array_unique($locationNames);

        foreach ($locationNames as $name) {
            $password = Str::random(10);
            Location::create([
                'event_id' => $eventId,
                'name' => $name,
                'password' => $password,
            ]);
        }

        return redirect()->route('signup.index', ['eventId' => $eventId]);
    }

    // Show the edit page
    public function edit($formId)
    {
        $form = SignUpForm::findOrFail($formId);
        $events = Events::findOrFail($form->event_id);
        $locations = Location::where('event_id', $form->event_id)->get();

        return inertia('SignUpFormEdit', ['form' => $form, 'events' => $events, 'locations' => $locations]);
    }

    // Update form
    public function update(Request $request, $eventId)
    {
        $eventId = (int) $eventId;
        $form = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $tableName = $form->table_name;

        // ✅ Decode existing questions from database
        $oldQuestions = json_decode($form->questions, true);
        $newQuestions = $request->questions;

        $form->update([
            'heading' => $request->heading,
            'event_description' => $request->event_description,
            'privacy_link' => $request->privacy_link,
            'terms_link' => $request->terms_link,
            'mailchimp_signup_url' => $request->mailchimp_signup_url,
            'questions' => json_encode($request->questions),
        ]);

        // ✅ Extract old and new column names
        $oldColumns = collect($oldQuestions)->pluck('column_name')->toArray();
        $newColumns = collect($newQuestions)->pluck('column_name')->toArray();

        // ✅ Find new questions that were added
        $columnsToAdd = array_diff($newColumns, $oldColumns);

        // ✅ Add new columns to the database table
        if (! empty($columnsToAdd)) {
            Schema::table($tableName, function (Blueprint $table) use ($columnsToAdd, $newQuestions) {
                foreach ($newQuestions as $question) {
                    if (in_array($question['column_name'], $columnsToAdd)) {
                        // ✅ Define column type based on question type
                        switch ($question['type']) {
                            case 'text':
                            case 'email':
                                $table->string($question['column_name'])->nullable();
                                break;
                            case 'textarea':
                                $table->longText($question['column_name'])->nullable();
                                break;
                            case 'number':
                                $table->string($question['column_name'])->nullable(); // Store as string for format
                                // if (isset($question['format'])) {
                                //     $table->string($question['column_name'] . '_format')->nullable(); // Store number format
                                // }
                                break;
                            case 'date':
                                $table->date($question['column_name'])->nullable();
                                break;
                            case 'dropdown':
                                if (isset($question['allowMultiple']) && $question['allowMultiple']) {
                                    $table->longText($question['column_name'])->nullable();
                                } else {
                                    $table->string($question['column_name'])->nullable();
                                }
                                break;
                        }
                    }
                }
            });
        }

        return redirect()->route('signup.index', ['eventId' => $eventId])
            ->with('success', 'Form updated successfully. Locations updated.');
    }

    // EMBED FUNCTIONS
    public function embed($event_uuid)
    {
        // Fetch the form details
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();
        $form = SignUpForm::where('event_id', $event->id)->firstOrFail();
        // Remove a location from the signup dropdown 2 days after its date —
        // unless "show all locations" is enabled on the event, in which case we
        // return every location (including finished ones) so the dropdown stays.
        $locationsQuery = Location::where('event_id', $event->id);
        if (! $event->show_all_locations) {
            $locationsQuery->where('date', '>=', now()->subDays(2)->format('Y-m-d'));
        }
        $locations = $locationsQuery->get();

        return inertia('SignUpFormEmbed', [
            'form' => $form,
            'event' => $event,
            'locations' => $locations,
        ]);
    }

    public function storeEmbeddedData(Request $request, $event_uuid)
    {
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();

        // The page hides the form past the end date, but a direct POST would otherwise
        // still land in the table, so the close has to be enforced here too.
        if ($event->is_signup_closed) {
            throw ValidationException::withMessages([
                'signup_closed' => 'Sign-ups for this event have closed.',
            ]);
        }

        $form = SignUpForm::where('event_id', $event->id)->firstOrFail();
        $tableName = $form->table_name; // Ensure correct table

        // Form posts each answer under the question.text key, not the column_name. Look up
        // the email question's text so this still works when admins rename "Email Address"
        // (a stale hardcoded key was silently nulling $email and skipping the resub log).
        $formQuestions = json_decode($form->questions, true) ?: [];
        $emailQuestion = collect($formQuestions)->first(function ($q) {
            return ($q['column_name'] ?? null) === 'email_address' || ($q['type'] ?? null) === 'email';
        });
        $emailKey = $emailQuestion['text'] ?? 'Email Address';
        $email = $request->input($emailKey);
        if (! $email) {
            // Fallback: scan request for the first valid-looking email
            foreach ($request->except(['_token', 'events_location']) as $value) {
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $email = $value;
                    break;
                }
            }
        }

        // If email already exists in the form table, update the existing row instead of blocking
        $existingEntry = null;
        if ($email) {
            $existingEntry = DB::table($tableName)
                ->where('email_address', $email)
                ->first();
        }

        // Ensure table exists before inserting
        if (! Schema::hasTable($tableName)) {
            return response()->json(['error' => 'Table does not exist'], 400);
        }

        // Get the list of valid columns from the database table
        $validColumns = Schema::getColumnListing($tableName);
        // Transform request data: Normalize question text into column names
        $insertData = [
            'event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Handle location_id directly from the request
        if ($request->has('events_location')) {
            $insertData['location_id'] = $request->input('events_location');
        }

        $questions = json_decode($form->questions, true);
        $questionMap = collect($questions)->pluck('column_name', 'text')->toArray();

        foreach ($request->except(['_token', 'events_location']) as $key => $value) {
            // Find the corresponding column name from the questions
            $columnName = $questionMap[$key] ?? null;

            if ($columnName && in_array($columnName, $validColumns)) {
                $insertData[$columnName] = $value;
            }
        }

        // Insert or update the data in the form table
        if ($existingEntry) {
            $insertData['updated_at'] = now();
            unset($insertData['created_at']);
            DB::table($tableName)->where('id', $existingEntry->id)->update($insertData);
            $id = $existingEntry->id;
        } else {
            $id = DB::table($tableName)->insertGetId($insertData);
        }

        // Get the record for Mailchimp sync
        $subscriber = DB::table($tableName)->where('id', $id)->first();

        // Try to auto-sync the new subscriber
        if (isset($insertData['location_id'])) {
            \Illuminate\Support\Facades\Log::info('New subscriber added, attempting auto-sync', [
                'email' => $subscriber->email_address ?? 'no email',
                'location_id' => $insertData['location_id'],
            ]);

            $this->autoMailchimpService->syncSubscriber($subscriber, $insertData['location_id']);
        }

        // Auto-resubscribe to newsletter if email exists but is unsubscribed
        if ($email) {
            $locationId = $insertData['location_id'] ?? null;

            // If checkSubscription already resubbed this email, attribute it to the picked location
            $pendingKey = $this->pendingResubCacheKey($event->id, $email);
            if (Cache::has($pendingKey)) {
                $pendingInfo = Cache::pull($pendingKey);
                $this->recordNewsletterResubscribe($locationId, $pendingInfo);
            }

            try {
                $account = $this->getMailchimpAccountForEvent($event);
                $audienceName = $this->getNewsletterAudienceName($account);
                $mailchimpService = new MailchimpService($account);
                $listId = $this->findNewsletterListId($mailchimpService, $audienceName);

                if ($listId) {
                    $listInfo = ['account' => $account, 'list_id' => $listId, 'list_name' => $audienceName];
                    $status = $mailchimpService->getSubscriberStatus($listId, $email);
                    if ($status['exists'] && $status['status'] !== 'subscribed') {
                        $resubResult = $mailchimpService->resubscribe($listId, $email);
                        if (is_array($resubResult) && ($resubResult['status'] ?? null) === 'compliance_skipped') {
                            // The API will not take this contact back at any privilege
                            // level. They have just filled in this form and pressed
                            // submit, so their own sign-up is relayed to the audience's
                            // hosted form — the one route Mailchimp accepts, because it
                            // treats that as the contact opting in themselves.
                            $hosted = $this->hostedForm->submit($account, $email);

                            $this->recordResubscribeAttempt(
                                $event,
                                $email,
                                $this->outcomeForHostedForm($hosted['status']),
                                $listInfo,
                                // Mailchimp's refusal and its answer to the form are
                                // both worth keeping — the second explains the first.
                                trim(($resubResult['detail'] ?? '').' → '.($hosted['detail'] ?? $hosted['status']), ' →'),
                                $locationId,
                            );

                            \Illuminate\Support\Facades\Log::info('Compliance-state contact relayed to the hosted Mailchimp form', [
                                'email' => $email,
                                'account' => $account,
                                'audience' => $audienceName,
                                'hosted_form_status' => $hosted['status'],
                            ]);
                        } else {
                            $this->recordNewsletterResubscribe($locationId, $listInfo);
                            $this->recordResubscribeAttempt(
                                $event,
                                $email,
                                NewsletterResubscribeAttempt::RESUBSCRIBED,
                                $listInfo,
                                null,
                                $locationId,
                            );
                            \Illuminate\Support\Facades\Log::info('Auto-resubscribed to newsletter on form submit', [
                                'email' => $email,
                                'account' => $account,
                                'audience' => $audienceName,
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Don't block form submission if resubscribe fails
                $this->recordResubscribeAttempt(
                    $event,
                    $email,
                    NewsletterResubscribeAttempt::FAILED,
                    ['account' => $account ?? null, 'list_id' => $listId ?? null, 'list_name' => $audienceName ?? null],
                    $e->getMessage(),
                    $locationId,
                );
                \Illuminate\Support\Facades\Log::error('Auto-resubscribe on form submit failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('signup.embed', ['event_uuid' => $event_uuid])->with('success');
    }
}
