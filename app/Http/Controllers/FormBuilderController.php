<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Models\FormResponse;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Gender;
use Illuminate\Support\Facades\Storage;

class FormBuilderController extends Controller
{
    public function createForm(Events $event)
    {   
        $genders = Gender::all();
        
        return view('form-builder.create', compact('event','genders'));
    }

    public function storeForm(Request $request, Events $event)
    {   
        $bannerImagePath = null;

        if ($request->hasFile('banner_image')) {
            $bannerImagePath = $request->file('banner_image')->store('banners', 'public');
        }

        if($request->all_genders)
        {   
            $gendersArray = explode(',', $request->all_genders);
            foreach($gendersArray as $genders)
            {
                Gender::create([
                    'name' => $genders,
                    'event_id' => $event->id,
                ]);
            }
        }

        // Create the form and associate it with the event
        $form = Form::create([
            'event_id' => $event->id,
            'title' => $request->input('title'),
            'banner_image' => $bannerImagePath,
        ]);

        // Loop through each field and save it
        foreach ($request->input('fields') as $fieldData) {
            $form->fields()->create([
                'name' => $fieldData['name'],
                'type' => $fieldData['type'],
                'options' => $fieldData['type'] === 'dropdown' ? $fieldData['options'] : null,
            ]);
        }
    
        return redirect()->route('events.index')->with('success', 'Sign Up Form created successfully!');
    }
    
    public function viewForm($id)
    {
        $form = Form::with('fields')->findOrFail($id);
        $event = Events::find($form->event_id);
        
        return view('form-builder.view', compact('form', 'event'));
    }

    public function submitForm(Request $request, $formId)
    {
        $form = Form::findOrFail($formId);
        $eventId = $form->event_id;
        $fieldsData = $request->fields;
                
        // Extract the email from the submitted data
        $email = null;
        foreach ($fieldsData as $value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $email = $value;
                break;
            }
        }

        // Check if the email is found
        if (!$email) {
            return redirect()->back()->with('error', 'A valid email is required.');
        }

        // Validate the email uniqueness within the form
        $existingResponses = FormResponse::where('form_id', $formId)->get();
        
        foreach ($existingResponses as $response) {
            $responseData = json_decode($response->responses, true);
            foreach ($responseData as $existingValue) {
                if ($existingValue === $email) {
                    return redirect()->back()->with('error', 'This email address has already been used for registration. Please use a different email.');
                }
            }
        }

        // Proceed with saving the form response
        FormResponse::create([
            'event_id' => $eventId,
            'form_id' => $formId,
            'responses' => json_encode($fieldsData),
        ]);
    
        return redirect()->route('form-builder.view', $formId)->with('success', 'Your sign-up information has been submitted successfully!');
    }
    

    public function editForm($eventId)
    {
        $event = Events::findOrFail($eventId);
        $form = Form::where('event_id', $eventId)->first();     
        $genders = Gender::where('event_id', $eventId)->get();
    
        return view('form-builder.edit', compact('event', 'form', 'genders'));
    }

    public function updateForm(Request $request, $eventId, $formId)
    {
        // Retrieve the event and form based on the provided IDs
        $event = Events::findOrFail($eventId);
        $form = Form::findOrFail($formId);
        
        // Handle banner image upload
        if ($request->hasFile('banner_image')) {
            // Delete old banner image if a new one is uploaded
            if ($form->banner_image) {
                Storage::disk('public')->delete($form->banner_image);
            }
            $form->banner_image = $request->file('banner_image')->store('banners', 'public');
        }

        // Save other form fields
        $form->save();
        // Retrieve the form fields
        $fields = FormField::where('form_id', $formId)->get();
        
        // Validate the incoming request data
        $validatedData = $request->validate([
            'fields' => 'required|array',
            'fields.*.name' => 'required|string|max:255',
            'fields.*.type' => 'required|string|in:text,email,number,dropdown',
            'fields.*.options' => 'nullable|string', // Only required for dropdowns
        ]);
    
        // Delete existing fields that are not present in the update
        $existingFieldIds = $fields->pluck('id')->toArray();
        $updatedFieldIds = array_keys($validatedData['fields']);
        $fieldsToDelete = array_diff($existingFieldIds, $updatedFieldIds);
        if (!empty($fieldsToDelete)) {
            FormField::whereIn('id', $fieldsToDelete)->delete();
        }
    
        // Loop through the submitted fields and update or create them
        foreach ($validatedData['fields'] as $index => $fieldData) {
            FormField::updateOrCreate(
                ['id' => $index, 'form_id' => $formId],
                [
                    'name' => $fieldData['name'],
                    'type' => $fieldData['type'],
                    'options' => $fieldData['type'] === 'dropdown' ? $fieldData['options'] : null,
                    'form_id' => $formId,
                ]
            );
        }
    
        // Redirect back to the form view with a success message
        return redirect()->route('form-builder.view', $form->id)
                         ->with('success', 'Form updated successfully!');
    }
    

}
