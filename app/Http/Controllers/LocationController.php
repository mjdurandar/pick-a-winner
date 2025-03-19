<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;    
use App\Models\Events;
use App\Models\Location;
class LocationController extends Controller
{
    public function index()
    {
        $events = Events::all();
        return Inertia::render('Locations', [
            'events' => $events
        ]);
    }

    public function locationpage($eventId)
    {
        $event = Events::findOrFail($eventId);
        $locations = Location::where('event_id', $eventId)->get();
        return Inertia::render('PickaWinnerPage', [
            'event' => $event,
            'locations' => $locations
        ]);
    }

    public function updatePassword(Request $request, Location $location)
    {
        $request->validate([
            'password' => 'required|string|min:8'
        ]);

        $location->update([
            'password' => $request->password
        ]);

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }
}
