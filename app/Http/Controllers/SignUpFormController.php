<?php

namespace App\Http\Controllers;

use App\Jobs\ResubscribeSignupContactJob;
use App\Models\Events;
use App\Models\Location;
use App\Models\NewsletterResubscribeAttempt;
use App\Models\SignUpForm;
use App\Services\MailchimpHostedForm;
use App\Services\MailchimpService;
use App\Services\NewsletterResubscriber;
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

    protected NewsletterResubscriber $resubscriber;

    public function __construct(
        \App\Services\AutoMailchimpService $autoMailchimpService,
        MailchimpHostedForm $hostedForm,
        NewsletterResubscriber $resubscriber,
    ) {
        $this->autoMailchimpService = $autoMailchimpService;
        $this->hostedForm = $hostedForm;
        $this->resubscriber = $resubscriber;
    }

    // Audience names, account resolution, attempt logging and the resubscribe itself
    // all live in NewsletterResubscriber now, shared with the queued job that runs
    // after a sign-up is saved. The hosted form each account falls back to lives in
    // config/services.php under mailchimp.hosted_form, so the link shown to a
    // compliance-locked contact and the form the server relays to cannot drift apart.

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

        $account = $this->resubscriber->accountForEvent($event);
        $audienceName = $this->resubscriber->audienceName($account);
        $mailchimpService = new MailchimpService($account);

        try {
            $listId = $this->resubscriber->findListId($mailchimpService, $audienceName);

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
                // Archived contacts need the upsert endpoint rather than a patch, so
                // the route is chosen from the status we just read.
                $resubResult = $this->resubscriber->bringBack(
                    $mailchimpService,
                    $listId,
                    $request->email,
                    $result['status'],
                );
                if (is_array($resubResult) && ($resubResult['status'] ?? null) === 'compliance_skipped') {
                    $isCompliance = true;
                    $this->resubscriber->recordAttempt(
                        $event,
                        $request->email,
                        NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
                        $listInfo,
                        $resubResult['detail'] ?? null,
                    );
                } else {
                    $resubSucceeded = true;
                    $this->resubscriber->recordAttempt(
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
                $this->resubscriber->recordAttempt(
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
                    $this->resubscriber->pendingCacheKey($event->id, $request->email),
                    [
                        'account' => $account,
                        'list_id' => $listId,
                        'list_name' => $audienceName,
                    ],
                    now()->addMinutes(60)
                );
            }

            // Get the Mailchimp signup URL for compliance state members: the configured
            // hosted form for this account — the same one the server relays to on submit
            // — and only if that is missing does it fall back to whatever Mailchimp
            // reports for the audience.
            $mailchimpSignupUrl = null;
            if ($isCompliance) {
                $mailchimpSignupUrl = $this->hostedForm->subscribeUrl($account)
                    ?: $mailchimpService->getListSignupUrl($listId);
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

    // Columns the form table owns itself — a question may never rename or drop these.
    const RESERVED_FORM_COLUMNS = ['id', 'event_id', 'location_id', 'created_at', 'updated_at'];

    // Show the edit page
    public function edit($formId)
    {
        $form = SignUpForm::findOrFail($formId);
        $events = Events::findOrFail($form->event_id);
        $locations = Location::where('event_id', $form->event_id)->get();

        return inertia('SignUpFormEdit', [
            'form' => $form,
            'events' => $events,
            'locations' => $locations,
            // How many answers each column already holds, so someone removing a
            // question can see whether dropping it throws away real data.
            'columnStats' => $this->answerCountsPerColumn($form),
            // Columns an earlier edit left behind: still in the table, used by no
            // question. Nothing on the questionnaire can reach them, so the edit page
            // lists them separately or they stay forever.
            'orphanColumns' => $this->orphanColumns($form),
        ]);
    }

    /**
     * Table columns that no question uses.
     */
    private function orphanColumns(SignUpForm $form): array
    {
        $tableName = $form->table_name;

        if (! $tableName || ! Schema::hasTable($tableName)) {
            return [];
        }

        $used = collect(json_decode($form->questions, true) ?: [])
            ->pluck('column_name')
            ->filter()
            ->all();

        return array_values(array_diff(
            Schema::getColumnListing($tableName),
            $used,
            self::RESERVED_FORM_COLUMNS,
        ));
    }

    /**
     * Number of non-empty answers stored per column of the form's table.
     *
     * Covers every column the form owns, questions and leftovers alike, so the edit
     * page can say what dropping any of them would cost.
     */
    private function answerCountsPerColumn(SignUpForm $form): array
    {
        $tableName = $form->table_name;

        if (! $tableName || ! Schema::hasTable($tableName)) {
            return [];
        }

        $columns = collect(Schema::getColumnListing($tableName))
            ->reject(fn ($column) => in_array($column, self::RESERVED_FORM_COLUMNS, true))
            ->unique()
            ->values();

        if ($columns->isEmpty()) {
            return [];
        }

        $grammar = DB::connection()->getQueryGrammar();

        // Aliased by position rather than by column name: a question column can be
        // named anything an admin types, and that should never reach the alias.
        $selects = $columns
            ->map(function ($column, $index) use ($grammar) {
                $wrapped = $grammar->wrap($column);

                return "count(case when {$wrapped} is not null and {$wrapped} <> '' then 1 end) as c{$index}";
            })
            ->implode(', ');

        try {
            $row = (array) DB::table($tableName)->selectRaw($selects)->first();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not count sign-up form answers', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $counts = [];
        foreach ($columns as $index => $column) {
            $counts[$column] = (int) ($row['c'.$index] ?? 0);
        }

        return $counts;
    }

    /**
     * Renames the edit page asked for, reduced to the ones that are safe to run.
     *
     * A rename keeps the answers already collected under the question's new column
     * name; without one, the old column is orphaned and a fresh empty one appears.
     */
    private function resolveRenames(array $requested, string $tableName, array $oldColumns, array $newColumns): array
    {
        $existing = Schema::getColumnListing($tableName);
        $renames = [];

        foreach ($requested as $rename) {
            $from = trim((string) ($rename['from'] ?? ''));
            $to = trim((string) ($rename['to'] ?? ''));

            $isSafe = $from !== ''
                && $to !== ''
                && $from !== $to
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $to)
                // The old name must be leaving the questionnaire and the new one arriving,
                // so a rename can never touch a column another question still uses.
                && in_array($from, $oldColumns, true)
                && ! in_array($from, $newColumns, true)
                && in_array($to, $newColumns, true)
                && ! in_array($from, self::RESERVED_FORM_COLUMNS, true)
                && ! in_array($to, self::RESERVED_FORM_COLUMNS, true)
                && in_array($from, $existing, true)
                && ! in_array($to, $existing, true);

            if ($isSafe) {
                $renames[$from] = $to;
                $existing[] = $to;
            }
        }

        return $renames;
    }

    /**
     * The column type a question's answers are stored as.
     *
     * Both the "add a column" and "change a column" paths read this, so a question
     * type can never mean one thing when it is created and another when it is edited.
     */
    private function questionColumnType(array $question): ?string
    {
        return match ($question['type'] ?? null) {
            // Numbers keep their entered format (leading zeros, spacing), so string.
            'text', 'email', 'number' => 'string',
            'textarea' => 'longText',
            'date' => 'date',
            'dropdown' => empty($question['allowMultiple']) ? 'string' : 'longText',
            default => null,
        };
    }

    private function defineColumn(Blueprint $table, string $name, string $type): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = match ($type) {
            'longText' => $table->longText($name),
            'date' => $table->date($name),
            default => $table->string($name),
        };

        return $column->nullable();
    }

    /**
     * Type changes the edit page approved, reduced to the ones worth running.
     *
     * The page only sends column names; the type itself is read from the saved
     * question, so a request can never ask for a type the questionnaire doesn't use.
     */
    private function resolveTypeChanges(array $approved, string $tableName, array $newQuestions, array $oldQuestions, array $renames): array
    {
        $approved = array_map(fn ($column) => trim((string) $column), $approved);

        $oldTypes = [];
        foreach ($oldQuestions as $question) {
            $name = $question['column_name'] ?? null;
            if ($name) {
                $oldTypes[$renames[$name] ?? $name] = $this->questionColumnType($question);
            }
        }

        $changes = [];
        foreach ($newQuestions as $question) {
            $name = trim((string) ($question['column_name'] ?? ''));
            $type = $this->questionColumnType($question);

            $isChange = $name !== ''
                && $type !== null
                && in_array($name, $approved, true)
                && ! in_array($name, self::RESERVED_FORM_COLUMNS, true)
                // Must be a question that already existed and is genuinely changing.
                && array_key_exists($name, $oldTypes)
                && $oldTypes[$name] !== $type
                && Schema::hasColumn($tableName, $name);

            if ($isChange) {
                $changes[$name] = $type;
            }
        }

        return $changes;
    }

    // Update form
    public function update(Request $request, $eventId)
    {
        $eventId = (int) $eventId;
        $form = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $tableName = $form->table_name;

        // ✅ Decode existing questions from database
        $oldQuestions = json_decode($form->questions, true) ?: [];
        $newQuestions = (array) $request->input('questions', []);

        // Form edits are rare and their effects are hard to undo, so every one leaves
        // a record of what was asked for.
        \Illuminate\Support\Facades\Log::info('Sign-up form update received', [
            'event_id' => $eventId,
            'table' => $tableName,
            'questions' => count($newQuestions),
            'drop_columns' => $request->input('drop_columns', []),
            'column_renames' => $request->input('column_renames', []),
            'column_type_changes' => $request->input('column_type_changes', []),
        ]);

        // The questions are saved at the end of this method rather than here: a type
        // change that the database refuses is reverted in the questionnaire too, so
        // the form never claims a column holds something it does not.

        // ✅ Extract old and new column names
        $oldColumns = collect($oldQuestions)->pluck('column_name')->toArray();
        $newColumns = collect($newQuestions)->pluck('column_name')->toArray();

        // Renamed questions first: moving the column keeps the answers already
        // collected, so the rest of this method sees the new name as pre-existing
        // rather than as an addition next to an orphaned old column.
        $renames = $this->resolveRenames(
            (array) $request->input('column_renames', []),
            $tableName,
            $oldColumns,
            $newColumns,
        );

        foreach ($renames as $from => $to) {
            Schema::table($tableName, function (Blueprint $table) use ($from, $to) {
                $table->renameColumn($from, $to);
            });

            \Illuminate\Support\Facades\Log::info('Sign-up form column renamed', [
                'event_id' => $eventId,
                'table' => $tableName,
                'from' => $from,
                'to' => $to,
            ]);
        }

        if (! empty($renames)) {
            $oldColumns = array_map(fn ($column) => $renames[$column] ?? $column, $oldColumns);
        }

        // ✅ Find new questions that were added
        // A question whose column was removed from the form but kept in the table can
        // be added back later; it reconnects to the column it already has rather than
        // trying to create a duplicate.
        $existingColumns = Schema::getColumnListing($tableName);
        $columnsToAdd = array_diff($newColumns, $oldColumns, $existingColumns);

        // ✅ Add new columns to the database table
        if (! empty($columnsToAdd)) {
            Schema::table($tableName, function (Blueprint $table) use ($columnsToAdd, $newQuestions) {
                foreach ($newQuestions as $question) {
                    $type = $this->questionColumnType($question);
                    if ($type && in_array($question['column_name'], $columnsToAdd)) {
                        $this->defineColumn($table, $question['column_name'], $type);
                    }
                }
            });
        }

        // Field type changes the edit page approved. Converting a column can be
        // rejected by the database — free text that is not a date, an answer too long
        // for the narrower type — so each one runs on its own and a failure leaves the
        // column, and the question, exactly as they were.
        $typeChanges = $this->resolveTypeChanges(
            (array) $request->input('column_type_changes', []),
            $tableName,
            $newQuestions,
            $oldQuestions,
            $renames,
        );

        $failedTypeChanges = [];
        foreach ($typeChanges as $column => $type) {
            try {
                Schema::table($tableName, function (Blueprint $table) use ($column, $type) {
                    $this->defineColumn($table, $column, $type)->change();
                });

                \Illuminate\Support\Facades\Log::info('Sign-up form column type changed', [
                    'event_id' => $eventId,
                    'table' => $tableName,
                    'column' => $column,
                    'type' => $type,
                ]);
            } catch (\Exception $e) {
                $failedTypeChanges[] = $column;

                // Put the question back on the type its column actually has.
                foreach ($newQuestions as $index => $question) {
                    if (($question['column_name'] ?? null) === $column) {
                        $original = collect($oldQuestions)->first(
                            fn ($old) => ($renames[$old['column_name'] ?? ''] ?? ($old['column_name'] ?? null)) === $column
                        );
                        $newQuestions[$index]['type'] = $original['type'] ?? $question['type'];
                        $newQuestions[$index]['allowMultiple'] = $original['allowMultiple'] ?? false;
                    }
                }

                \Illuminate\Support\Facades\Log::error('Sign-up form column type change failed', [
                    'event_id' => $eventId,
                    'table' => $tableName,
                    'column' => $column,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Questions taken off the form keep their column — and so their answers —
        // unless the edit page explicitly asked for the column to be dropped.
        $removedColumns = array_diff($oldColumns, $newColumns);
        $requestedDrops = array_map(fn ($column) => trim((string) $column), (array) $request->input('drop_columns', []));

        // Droppable: what this save removes, plus what earlier saves left behind. A
        // column any question still uses is never in either list.
        $droppableColumns = array_unique(array_merge(
            $removedColumns,
            array_diff(Schema::getColumnListing($tableName), $oldColumns, $newColumns, self::RESERVED_FORM_COLUMNS),
        ));

        $columnsToDrop = array_values(array_filter(
            $droppableColumns,
            fn ($column) => in_array($column, $requestedDrops, true)
                && ! in_array($column, self::RESERVED_FORM_COLUMNS, true)
                && Schema::hasColumn($tableName, $column)
        ));

        if (! empty($columnsToDrop)) {
            Schema::table($tableName, function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });

            \Illuminate\Support\Facades\Log::warning('Sign-up form columns dropped', [
                'event_id' => $eventId,
                'table' => $tableName,
                'columns' => $columnsToDrop,
            ]);
        }

        $keptColumns = array_values(array_diff($removedColumns, $columnsToDrop));

        // Saved last, so any type change the database refused is written back as the
        // type the column still has.
        $form->update([
            'heading' => $request->heading,
            'event_description' => $request->event_description,
            'privacy_link' => $request->privacy_link,
            'terms_link' => $request->terms_link,
            'questions' => json_encode($newQuestions),
        ]);

        $message = 'Form updated successfully. Locations updated.';
        if (! empty($columnsToDrop)) {
            $message .= ' Deleted column(s): '.implode(', ', $columnsToDrop).'.';
        }
        if (! empty($keptColumns)) {
            $message .= ' Kept stored data for: '.implode(', ', $keptColumns).'.';
        }

        $redirect = redirect()->route('signup.index', ['eventId' => $eventId])
            ->with('success', $message);

        if (! empty($failedTypeChanges)) {
            $redirect->with('error', 'The field type could not be changed for: '
                .implode(', ', $failedTypeChanges)
                .'. The existing answers do not fit the new type, so those questions were left as they were.');
        }

        return $redirect;
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

        // Bring the contact back onto the newsletter audience if they have lapsed —
        // on the queue, not here. Their entry is already in the table above, so a slow
        // or rate-limiting Mailchimp can no longer hold up the person at the venue,
        // and a failure retries instead of being lost with the response.
        if ($email) {
            $locationId = $insertData['location_id'] ?? null;

            ResubscribeSignupContactJob::dispatch($event->id, $email, $locationId);

            \Illuminate\Support\Facades\Log::info('Queued newsletter resubscribe for sign-up', [
                'event_id' => $event->id,
                'location_id' => $locationId,
            ]);
        }

        return redirect()->route('signup.embed', ['event_uuid' => $event_uuid])->with('success');
    }
}
