<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Events;
use App\Models\Films;
use Inertia\Inertia;
use Illuminate\Support\Facades\Schema;
use App\Models\SignUpForm;

class EventsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() {
        return Inertia::render('Events', [
            'events' => Events::latest()->get(),
            'films' => Films::orderBy('name')->get(),
        ]);
    }
    
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {
        $request->validate([
            'event_name' => 'required|string|max:255',
            'event_date' => 'required|date',
            'event_year' => 'required|integer',
            'event_banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'event_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'event_coordinator' => 'required|string',
            'event_coordinator_email' => 'required|email',
            'event_coordinator_phone' => 'nullable|string|max:50',
            'event_country' => 'required|string',
            'film_id' => 'required|exists:films,id',
            'instructions_password' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean',
            'show_all_locations' => 'nullable|boolean',
            'show_all_locations_draw' => 'nullable|boolean',
        ]);

        // Store banner file
        $bannerPath = null;
        if ($request->hasFile('event_banner')) {
            $destinationPath = public_path('storage/event_banners');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_banner');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $bannerPath = 'event_banners/' . $filename;
        }

        // Store logo file
        $logoPath = null;
        if ($request->hasFile('event_logo')) {
            $destinationPath = public_path('storage/event_logos');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_logo');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $logoPath = 'event_logos/' . $filename;
        }
    
        Events::create(array_merge(
            $request->except(['event_banner', 'event_logo']),
            [
                'event_banner' => $bannerPath,
                'event_logo' => $logoPath,
                'is_enabled' => $request->has('is_enabled') ? (bool)$request->is_enabled : false,
                'show_all_locations' => $request->has('show_all_locations') ? (bool)$request->show_all_locations : false,
                'show_all_locations_draw' => $request->has('show_all_locations_draw') ? (bool)$request->show_all_locations_draw : false,
            ]
        ));
    
        return redirect()->route('events.index');
    }
    
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Events $event)
    {
        $request->validate([
            'event_name' => 'required|string|max:255',
            'event_date' => 'required|date',
            'event_year' => 'required|integer',
            'event_banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'event_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'event_coordinator' => 'required|string',
            'event_coordinator_email' => 'required|email',
            'event_coordinator_phone' => 'nullable|string|max:50',
            'event_country' => 'required|string',
            'film_id' => 'required|exists:films,id',
            'instructions_password' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean',
            'show_all_locations' => 'nullable|boolean',
            'show_all_locations_draw' => 'nullable|boolean',
        ]);

        // Prepare update data
        $updateData = $request->except(['event_banner', 'event_logo']);
        
        // Handle is_enabled checkbox (convert to boolean)
        $updateData['is_enabled'] = $request->has('is_enabled') ? (bool)$request->is_enabled : false;
        $updateData['show_all_locations'] = $request->has('show_all_locations') ? (bool)$request->show_all_locations : false;
        $updateData['show_all_locations_draw'] = $request->has('show_all_locations_draw') ? (bool)$request->show_all_locations_draw : false;

        // Handle banner upload - only update if new file is provided
        if ($request->hasFile('event_banner')) {
            $destinationPath = public_path('storage/event_banners');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_banner');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $updateData['event_banner'] = 'event_banners/' . $filename;
        }
        // If no new banner uploaded, keep existing one (don't add to updateData)

        // Handle logo upload - only update if new file is provided
        if ($request->hasFile('event_logo')) {
            $destinationPath = public_path('storage/event_logos');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_logo');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $updateData['event_logo'] = 'event_logos/' . $filename;
        }
        // If no new logo uploaded, keep existing one (don't add to updateData)
    
        $event->update($updateData);
    
        return redirect()->route('events.index');
    }
    
    // public function updatePassword(Request $request, Events $event)
    // {
    //     $request->validate([
    //         'password' => 'required|string|min:8'
    //     ]);

    //     $event->update([
    //         'password' => $request->password
    //     ]);

    //     return back()->with('success', 'Event password updated successfully');
    // }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Events $event)
    {
        if (!$event) {
            return response()->json(['error' => 'Event not found.'], 404);
        }
    
        // ✅ Find the associated signup form
        $signupForm = SignUpForm::where('event_id', $event->id)->first();
    
        if ($signupForm) {
            $tableName = $signupForm->table_name; // Assuming `table_name` holds the dynamic table name
    
            // ✅ Drop the table if it exists
            if ($tableName && Schema::hasTable($tableName)) {
                Schema::dropIfExists($tableName);
            }
    
            // ✅ Delete the form entry from the database
            $signupForm->delete();
        }
    
        // ✅ Now delete the event
        $event->delete();
    
        return redirect()->route('events.index')->with('success', 'Event and associated sign-up form deleted along with its table.');
    }
    
}

