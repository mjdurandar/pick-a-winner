<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\Form;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    public function index()
    {   
        $events = Events::with('forms')->get();
        return view('events.index', compact('events'));
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

    public function edit(Events $event)
    {   
        return view('events.edit', compact('event'));
    }

    public function update(Request $request, Events $event)
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

        // Update the event
        $event->update($validatedData);

        return redirect()->back()->with('success', 'Event updated successfully!');
    }

}
