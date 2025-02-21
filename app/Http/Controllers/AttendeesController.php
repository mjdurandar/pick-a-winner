<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\SignUpForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AttendeesController extends Controller
{
    public function index($eventId)
    {
        // ✅ Fetch the event object
        $event = Events::findOrFail($eventId);
    
        // ✅ Get the signup form for the event
        $signupForm = SignUpForm::where('event_id', $eventId)->firstOrFail();
        $tableName = $signupForm->table_name;
    
        // ✅ Fetch attendees from the dynamic table
        $attendees = DB::table($tableName)
            ->where('event_id', $eventId)
            ->get();
        
        return Inertia::render('Attendees', [
            'event' => $event,  // ✅ Pass the full event object instead of just ID
            'attendees' => $attendees
        ]);
    }
    
}
