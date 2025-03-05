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
    // Show the signup form page
    public function index($eventId)
    {   
        $eventId = (int) $eventId;
        // Find the signup form for the given event
        $form = SignUpForm::where('event_id', $eventId)->first();
        $eventValues = Events::where('id', $eventId)->first();
        return inertia('SignUpForm', [
            'eventId' => $eventId,
            'eventValues' => $eventValues,
            'form' => $form // ✅ Pass the form data to Vue
        ]);
    }

    public function create($eventId){
        $eventId = (int) $eventId;
        return inertia('SignUpFormCreate', ['events' => $eventId]);
    }

    // Generate a new sign up form with default questions
    public function generate(Request $request, $eventId)
    {   
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
                    case 'number':
                        $table->string($columnName)->nullable(); // Store as string to maintain format
                        if (isset($question['format'])) {
                            $table->string($columnName . '_format')->nullable(); // Store number format separately
                        }
                        break;
                    case 'dropdown':
                        $table->string($columnName)->nullable();
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
            Location::create([
                'event_id' => $eventId,
                'name' => $name
            ]);
        }

        return redirect()->route('signup.index', ['eventId' => $eventId]);
    }
    
    // Show the edit page
    public function edit($formId)
    {   
        $form = SignUpForm::findOrFail($formId);
        $events = Events::findOrFail($form->event_id);
        return inertia('SignUpFormEdit', ['form' => $form, 'events' => $events]);
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
                            case 'number':
                                $table->string($question['column_name'])->nullable(); // Store as string for format
                                if (isset($question['format'])) {
                                    $table->string($question['column_name'] . '_format')->nullable(); // Store number format
                                }
                                break;
                            case 'dropdown':
                                $table->string($question['column_name'])->nullable();
                                break;
                        }
                    }
                }
            });
        }
    
        // ✅ Extract New Locations from the "events_location" dropdown
        $newLocationOptions = [];
        foreach ($newQuestions as $question) {
            if ($question['column_name'] === 'events_location') { 
                $newLocationOptions = array_merge($newLocationOptions, $question['options']);
            }
        }
    
        // ✅ Ensure we have unique new locations
        $uniqueNewLocations = array_unique($newLocationOptions);
        
        // ✅ Fetch all existing locations
        $existingLocations = Location::where('event_id', $eventId)->get()->keyBy('name');
        
        $updatedLocations = [];
    
        foreach ($uniqueNewLocations as $newLocationName) {
            if ($existingLocations->has($newLocationName)) {
                // ✅ Location exists, just keep track
                $updatedLocations[] = $existingLocations[$newLocationName]->id;
            } else {
                // ✅ Add new location
                $newLocation = Location::create([
                    'event_id' => $eventId,
                    'name' => $newLocationName
                ]);
                $updatedLocations[] = $newLocation->id;
            }
        }
    
        // ✅ Remove old locations that are no longer in the dropdown
        Location::where('event_id', $eventId)
            ->whereNotIn('id', $updatedLocations)
            ->delete();
    
        return redirect()->route('signup.index', ['eventId' => $eventId])
            ->with('success', 'Form updated successfully. Locations updated.');
    }
    
    
    //EMBED FUNCTIONS
    public function embed($eventId)
    {
        // Fetch the form details
        $form = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $event = Events::where('id', $eventId)->first();

        return inertia('SignUpFormEmbed', [
            'form' => $form,
            'event' => $event,
        ]);
    }

    public function storeEmbeddedData(Request $request, $eventId)
    {   
        $form = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $tableName = $form->table_name; // Ensure correct table

        // Ensure table exists before inserting
        if (!Schema::hasTable($tableName)) {
            return response()->json(['error' => 'Table does not exist'], 400);
        }

        // Get the list of valid columns from the database table
        $validColumns = Schema::getColumnListing($tableName);
        // Transform request data: Normalize question text into column names
        $insertData = [
            'event_id' => $eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $selectedLocationName = null; // Placeholder for selected location name
        $questions = json_decode($form->questions, true);
        $questionMap = collect($questions)->pluck('column_name', 'text')->toArray();
        foreach ($request->except('_token') as $key => $value) {
            // Find the corresponding column name from the questions
  
            $columnName = $questionMap[$key] ?? null;
       
            $colums[] = $columnName;
            if ($columnName && in_array($columnName, $validColumns)) {
                $insertData[$columnName] = $value;
            }

            if ($columnName === 'events_location') {
                $selectedLocationName = $value;
            }
        }
   
        // ✅ Find Location ID from the Locations Table
        if ($selectedLocationName) {
            $location = Location::where('event_id', $eventId)
                                ->where('name', $selectedLocationName)
                                ->first();

            if ($location) {
                $insertData['location_id'] = $location->id;
            }
        }
        // Insert the validated data into the correct table
        DB::table($tableName)->insert($insertData);
        
        return redirect()->route('signup.embed', ['eventId' => $eventId])->with('success');
    }
}
