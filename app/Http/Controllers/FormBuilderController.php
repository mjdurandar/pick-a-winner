<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Models\FormResponse;
use App\Models\Form;

class FormBuilderController extends Controller
{
    public function createForm(Events $event)
    {
        return view('form-builder.create', compact('event'));
    }

    public function storeForm(Request $request, Events $event)
    {
        // Create the form and associate it with the event
        $form = Form::create([
            'event_id' => $event->id,
            'title' => $request->input('title'),
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
        
        FormResponse::create([
            'event_id' => $eventId,
            'form_id' => $formId,
            'responses' => json_encode($fieldsData),
        ]);    

        return redirect()->route('form-builder.view', $formId)->with('success', 'Your sign-up information has been submitted successfully!');
    }
    

}
