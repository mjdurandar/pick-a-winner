<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\FormField;
use App\Models\Form;
use App\Models\FormResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AttendeesController extends Controller
{
    public function index()
    {   
        $events = Events::all();
        return view('attendees.index', compact('events'));
    }

    public function show($eventId)
    {   
        try {
            $eventName = Events::where('id', $eventId)->firstOrFail();
            $form = Form::where('event_id', $eventId)->firstOrFail();
            $formFields = FormField::where('form_id', $form->id)->get();
            $formResponses = FormResponse::where('form_id', $form->id)->get();
    
            return view('attendees.show', compact('eventName', 'form', 'formFields', 'formResponses'));
        } catch (ModelNotFoundException $e) {
            // You can either return a view with a "No data found" message or redirect to another page
            return view('attendees.no_data'); // assuming you have a 'no_data' view to show the message
        }
    }

}
