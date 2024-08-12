<?php

namespace App\Http\Controllers;

use App\Models\Events;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    public function index()
    {   
        $events = Events::all();
        return view('events-page',compact('events'));
    }

    public function store(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'date' => 'required|date',
            'description' => 'nullable|string',
        ]);

        // Handle the image upload
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('event_images', 'public');
            $validatedData['image'] = $imagePath;
        }

        // Create and save the event
        Events::create($validatedData);

        return redirect()->back()->with('success', 'Event created successfully!');
    }

    public function createForm(Events $event)
    {
        return view('form-builder.create', compact('event'));
    }

    public function storeForm(Request $request)
    {   
        dd($request->all());
        
        // Validate and store the form fields
        $validatedData = $request->validate([
            'event_id' => 'required|integer',
            'field_name' => 'required|string|max:255',
            'field_type' => 'required|string',
            'field_options' => 'nullable|string',
        ]);

        // Save the form field to the database or process as needed
        // This is where you would save the form field to a table, related to the event

        return redirect()->route('events-page')->with('success', 'Sign Up Form created successfully!');
    }



}
