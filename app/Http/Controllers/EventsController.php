<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use Inertia\Inertia;

class EventsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() {
        return Inertia::render('Events', [
            'events' => Events::latest()->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {
        $request->validate([
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
            'event_date' => 'required|date',
            'event_banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', 
            'event_coordinator' => 'required|string',
            'event_coordinator_email' => 'required|email',
            'event_country' => 'required|string',
        ]);
    
        // Store file
        $path = $request->file('event_banner')->store('event_banners', 'public');
    
        Events::create(array_merge($request->except('event_banner'), ['event_banner' => $path]));
    
        return redirect()->route('events.index');
    }
    

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Events $event)
    {
        // Validate input
        $request->validate([
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
            'event_date' => 'required|date',
            'event_banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Ensure file is an image
            'event_coordinator' => 'required|string',
            'event_coordinator_email' => 'required|email',
            'event_country' => 'required|string',
        ]);
    
        // If a new file is uploaded, store it
        if ($request->hasFile('event_banner')) {
            $path = $request->file('event_banner')->store('event_banners', 'public');
            $event->event_banner = $path; // Update banner
        }
    
        // Update event with new data
        $event->update($request->except(['event_banner'])); 
    
        return redirect()->route('events.index');
    }
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Events $event)
    {
        if (!$event) {
            return response()->json(['error' => 'Event not found.'], 404);
        }
    
        $event->delete();
    
        return redirect()->route('events.index')->with('success', 'Event deleted.');
    }
    
}
