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
        // Find the signup form for the given event
        $form = SignUpForm::where('event_id', $eventId)->first();
        $eventValues = Events::where('id', $eventId)->first();
        return inertia('SignUpForm', [
            'eventId' => $eventId,
            'eventValues' => $eventValues,
            'form' => $form // ✅ Pass the form data to Vue
        ]);
    }

    // Generate a new sign up form with default questions
    public function generate(Request $request)
    {   
        // Fetch event details
        $event = DB::table('events')->where('id', $request->event_id)->first();
        if (!$event) {
            return redirect()->back()->withErrors('Event not found.');
        }

        // Format the table name: `event_name_date_created`
        $eventName = Str::slug($event->event_name, '_');
        $tableName = $eventName . "_" . now()->format('Y_m_d');
        // Default Questions as per your specifications
        $defaultQuestions = [
            ['text' => 'Events Location', 'type' => 'dropdown', 'options' => ['Option 1', 'Option 2']], // User will input locations
            ['text' => 'Email Address', 'type' => 'text', 'options' => []],
            ['text' => 'First Name', 'type' => 'text', 'options' => []],
            ['text' => 'Last Name', 'type' => 'text', 'options' => []],
            ['text' => 'Mobile Number', 'type' => 'number', 'options' => []],
            ['text' => 'Age', 'type' => 'dropdown', 'options' => ['Under 21', '22-44', '45+']],
            ['text' => 'Gender', 'type' => 'dropdown', 'options' => ['Female', 'Male', 'Nonbinary/Other']],
            ['text' => 'Combined Household Income?', 'type' => 'dropdown', 'options' => [
                '>$150,000', '$100,000-$150,000', '$66,000-$99,000', '<$66,000', 'Prefer not to say'
            ]],
            ['text' => 'Where did you hear about this event?', 'type' => 'dropdown', 'options' => [
                'FB/IG', 'Poster in store', 'Email', 'Word of mouth', 'Other'
            ]],
            ['text' => 'Favorite adventure sport?', 'type' => 'dropdown', 'options' => [
                'Snow Sports (Skiing, Snowboarding, Snowshoeing)',
                'Climbing (Indoor, Outdoor, Bouldering, Slacklining)',
                'Trail Sports (Trail Running, Trail Walking)',
                'Skate Sports (Skateboarding, Rollerblading)',
                'Cycling (Mountain Biking, Road Cycling, BMX)',
                'Water Sports (Kayaking, Canoeing, Surfing, Windsurfing, Fly Fishing, Scuba Diving, Paddleboarding)',
                'Outdoor Activities (Hiking, Camping)',
                'Aerial Sports (Paragliding, Hang Gliding)',
                'Extreme Sports (Bungee Jumping, BASE Jumping)',
                'Other'
            ]],
            ['text' => 'How much would you spend on equipment?', 'type' => 'dropdown', 'options' => [
                'Less than $500', '$500-$1,000', 'More than $1,000'
            ]],
            ['text' => 'How often do you climb? (Specify type)', 'type' => 'dropdown', 'options' => [
                'More than once a year', 'Once a year', 'Once every 2 years', 'Never'
            ]],
            ['text' => 'How often do you climb overseas? (Specify type)', 'type' => 'dropdown', 'options' => [
                'More than once a year', 'Once a year', 'Once every 2 years', 'Never'
            ]],
            ['text' => 'How many days per year do you climb? (Specify type)', 'type' => 'dropdown', 'options' => [
                '1-4 days', '5-10 days', '11-19 days', '20+ days', 'Never'
            ]]
        ];
        
        // Create the signup form
        SignUpForm::create([
            'event_id' => $request->event_id,
            'event_description' => '*By entering the competition you accept the competition terms and conditions and consent to receiving marketing materials related to the offerings of Adventure Entertainment and our partners.',
            'privacy_link' => '#',
            'terms_link' => '#',
            'heading' => 'GET A CHANCE TO WIN AMAZING PRIZES!',
            'table_name' => $tableName,
            'questions' => json_encode($defaultQuestions),
        ]);

        // ✅ CREATE TABLE BASED ON QUESTIONS
        Schema::create($tableName, function (Blueprint $table) use ($defaultQuestions) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->timestamps(); // Add timestamps
            
            foreach ($defaultQuestions as $question) {
                $columnName = Str::slug($question['text'], '_'); // Convert question text to column name
                if ($question['type'] === 'text' || $question['type'] === 'number') {
                    $table->string($columnName)->nullable();
                } elseif ($question['type'] === 'dropdown') {
                    $table->string($columnName)->nullable();
                }
            }
        });

        return redirect()->route('signup.index', ['eventId' => $request->event_id]);
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
        $form = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $oldQuestions = json_decode($form->questions, true);
        $newQuestions = json_decode($request->questions, true);
        $oldTableName = $form->table_name;
    
        // ✅ Ensure old table name exists
        if (empty($oldTableName) || !Schema::hasTable($oldTableName)) {
            return redirect()->back()->withErrors("Error: The existing table does not exist.");
        }
    
        // ✅ Modify Table Columns
        Schema::table($oldTableName, function (Blueprint $table) use ($oldQuestions, $newQuestions) {
            foreach ($oldQuestions as $question) {
                $oldColumn = Str::slug($question['text'], '_');
                if (!Schema::hasColumn($table->getTable(), $oldColumn)) continue;
                
                // Remove old columns that no longer exist
                if (!in_array($oldColumn, array_map(fn($q) => Str::slug($q['text'], '_'), $newQuestions))) {
                    $table->dropColumn($oldColumn);
                }
            }
    
            foreach ($newQuestions as $question) {
                $newColumn = Str::slug($question['text'], '_');
                if (!Schema::hasColumn($table->getTable(), $newColumn)) {
                    $table->string($newColumn)->nullable();
                }
            }
        });
    
        // ✅ Extract and Save Unique Locations
        $newLocationOptions = [];
    
        foreach ($newQuestions as $question) {
            if (isset($question['text']) && stripos($question['text'], 'location') !== false && $question['type'] === 'dropdown') {
                $newLocationOptions = array_merge($newLocationOptions, $question['options']);
            }
        }
    
        $uniqueNewLocations = array_unique($newLocationOptions);
    
        // ✅ Get all existing locations for this event
        $existingLocations = Location::where('event_id', $eventId)->pluck('name')->toArray();
    
        // ✅ Find locations that were removed
        $deletedLocations = array_diff($existingLocations, $uniqueNewLocations);
    
        // ✅ Delete removed locations from the database
        Location::where('event_id', $eventId)->whereIn('name', $deletedLocations)->delete();
    
        // ✅ Add new locations if they don't already exist
        foreach ($uniqueNewLocations as $locationName) {
            Location::updateOrCreate(
                ['event_id' => $eventId, 'name' => $locationName], 
                ['event_id' => $eventId]
            );
        }
    
        // ✅ Update Form in Database
        $form->update([
            'heading' => $request->heading,
            'event_description' => $request->event_description,
            'privacy_link' => $request->privacy_link,
            'terms_link' => $request->terms_link,
            'questions' => json_encode($newQuestions),
        ]);
    
        return redirect()->route('signup.index', ['eventId' => $eventId])->with('success', 'Form updated successfully. Locations updated.');
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
    
        foreach ($request->except('_token') as $key => $value) {
            $columnName = Str::slug($key, '_'); // Convert spaces to underscores
            if (in_array($columnName, $validColumns)) { // ✅ Ensure column exists before inserting
                $insertData[$columnName] = $value;
            }
        }
    
        // Insert the validated data into the correct table
        DB::table($tableName)->insert($insertData);
        // dd($insertData);
        return redirect()->route('signup.embed', ['eventId' => $eventId])->with('success');
    }
    
    


    
    
    
}
