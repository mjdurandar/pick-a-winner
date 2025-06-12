<?php

namespace App\Http\Controllers;

use App\Models\Events;
use Illuminate\Http\Request;
use App\Models\SignUpForm;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Location;

class SignUpFormController extends Controller
{
    protected $autoMailchimpService;

    public function __construct(\App\Services\AutoMailchimpService $autoMailchimpService)
    {
        $this->autoMailchimpService = $autoMailchimpService;
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
            'locations' => $locations // ✅ Pass locations to Vue
        ]);
    }

    public function create($eventId){
        $eventId = (int) $eventId;
        $event = Events::where('id', $eventId)->first();
        $locations = Location::where('event_id', $eventId)->get();
        return inertia('SignUpFormCreate', ['events' => $eventId , 'locations' => $locations, 'eventValues' => $event]);
    }

    // Generate a new sign up form with default questions
    public function generate(Request $request, $eventId)
    {   
        // dd($request->all());
        // Fetch event details
        $event = DB::table('events')->where('id', $eventId)->first();
        // Format the table name: `event_name_date_created`
        $eventName = Str::slug($event->event_name, '_');
        $tableName = $eventName . "_" . now()->format('Y_m_d');

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
            'questions' => json_encode($questions)
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
                'password' => $password
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
            'questions' => json_encode($request->questions), 
        ]);
    
        // ✅ Extract old and new column names
        $oldColumns = collect($oldQuestions)->pluck('column_name')->toArray();
        $newColumns = collect($newQuestions)->pluck('column_name')->toArray();
    
        // ✅ Find new questions that were added
        $columnsToAdd = array_diff($newColumns, $oldColumns);
    
        // ✅ Add new columns to the database table
        if (!empty($columnsToAdd)) {
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
    
    
    //EMBED FUNCTIONS
    public function embed($event_uuid)
    {
        // Fetch the form details
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();
        $form = SignUpForm::where('event_id', $event->id)->firstOrFail();
        $locations = Location::where('event_id', $event->id)->get();
        
        return inertia('SignUpFormEmbed', [
            'form' => $form,
            'event' => $event,
            'locations' => $locations
        ]);
    }

    public function storeEmbeddedData(Request $request, $event_uuid)
    {   
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();
        $form = SignUpForm::where('event_id', $event->id)->firstOrFail();
        $tableName = $form->table_name; // Ensure correct table
        // Get Email Address from Request
        $email = $request->input('Email Address'); // Make sure this matches the form input name
 
        if ($email) {
            $emailExists = DB::table($tableName)
                ->where('email_address', $email)
                ->exists();

            if ($emailExists) {
              return back()->withErrors(['email' => 'This email has already been submitted!']);
            }
        }

        // Ensure table exists before inserting
        if (!Schema::hasTable($tableName)) {
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
   
        // Insert the validated data into the correct table
        $id = DB::table($tableName)->insertGetId($insertData);
        
        // Get the inserted record for Mailchimp sync
        $subscriber = DB::table($tableName)->where('id', $id)->first();
        
        // Try to auto-sync the new subscriber
        if (isset($insertData['location_id'])) {
            \Illuminate\Support\Facades\Log::info('New subscriber added, attempting auto-sync', [
                'email' => $subscriber->email_address ?? 'no email',
                'location_id' => $insertData['location_id']
            ]);
            
            $this->autoMailchimpService->syncSubscriber($subscriber, $insertData['location_id']);
        }
        
        return redirect()->route('signup.embed', ['event_uuid' => $event_uuid])->with('success');
    }
}
