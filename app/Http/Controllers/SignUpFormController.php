<?php

namespace App\Http\Controllers;

use App\Models\Events;
use Illuminate\Http\Request;
use App\Models\SignUpForm;

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
            'questions' => json_encode($defaultQuestions),
        ]);
    
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
        // Validate that questions are provided
        $request->validate([
            'questions' => 'required|json'
        ]);
    
        // Find the form based on the event ID
        $form = SignUpForm::where('event_id', $eventId)->firstOrFail();
        // Update questions
        $form->questions = $request->questions;
        $form->heading = $request->heading;
        $form->event_description = $request->event_description;
        $form->privacy_link = $request->privacy_link;
        $form->terms_link = $request->terms_link;
        $form->save();
    
        return redirect()->route('signup.index', ['eventId' => $eventId])->with('success', 'Form updated successfully!');
    }
    
}
