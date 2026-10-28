<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\Films;
use App\Models\SignUpForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class EventsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $events = Events::latest()->get();

        $counts = fn (string $table) => DB::table($table)
            ->whereIn('event_id', $events->pluck('id'))
            ->selectRaw('event_id, count(*) as total')
            ->groupBy('event_id')
            ->pluck('total', 'event_id');

        $locations = $counts('locations');
        $prizes = $counts('prizes');

        return Inertia::render('Events', [
            'events' => $events->map(function (Events $event) use ($locations, $prizes) {
                // Resolved here rather than in the browser: the old modal built
                // "/storage/" . $path unencoded and without checking the file was
                // there, so a missing file or a space in the name rendered a
                // broken-image icon with no explanation.
                return $event->toArray() + [
                    'event_logo_url' => $this->publicAsset($event->event_logo),
                    'event_banner_url' => $this->publicAsset($event->event_banner),
                    'locations_count' => (int) ($locations[$event->id] ?? 0),
                    'prizes_count' => (int) ($prizes[$event->id] ?? 0),
                ];
            }),
            'films' => Films::orderBy('name')->get(),
        ]);
    }

    /**
     * One event's own settings page, replacing the fifteen-field pop-up.
     *
     * Groups what belongs to an event — what it is, who runs it, how it looks,
     * where it appears — and gathers the tools that were scattered across the
     * event card. Partners are listed but edited on the sponsor dashboard, so
     * there is only ever one place a sponsor changes.
     */
    public function show(Events $event)
    {
        return Inertia::render('EventSettings', [
            'event' => $event->only([
                'id', 'event_name', 'film_id', 'event_year', 'event_date', 'event_end_date',
                'event_country', 'instructions_password', 'event_coordinator',
                'event_coordinator_email', 'event_coordinator_phone', 'is_enabled',
                'show_all_locations', 'show_all_locations_draw', 'event_uuid',
            ]) + [
                'event_logo_url' => $this->publicAsset($event->event_logo),
                'event_banner_url' => $this->publicAsset($event->event_banner),
            ],
            'films' => Films::orderBy('name')->get(['id', 'name']),
            'counts' => [
                'locations' => DB::table('locations')->where('event_id', $event->id)->count(),
                'prizes' => DB::table('prizes')->where('event_id', $event->id)->count(),
                'winners' => DB::table('prizes')->where('event_id', $event->id)
                    ->whereNotNull('winner')->where('winner', '!=', '')->count(),
            ],
            'defaultCoordinatorPhone' => Events::DEFAULT_COORDINATOR_PHONE,
            // Partners are deliberately not loaded: the section is commented out in
            // EventSettings.vue until sponsor upload to the film sites ships, and the
            // sponsors table does not exist in every environment yet.
        ]);
    }

    /** Absolute URL for an uploaded file, or null when it is no longer on disk. */
    private function publicAsset(?string $path): ?string
    {
        if (blank($path) || ! file_exists(public_path('storage/'.$path))) {
            return null;
        }

        return url('storage/'.implode('/', array_map('rawurlencode', explode('/', $path))));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'event_name' => 'required|string|max:255',
            'event_date' => 'required|date',
            'event_end_date' => 'nullable|date|after_or_equal:event_date',
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
            if (! file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_banner');
            $filename = time().'_'.$file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $bannerPath = 'event_banners/'.$filename;
        }

        // Store logo file
        $logoPath = null;
        if ($request->hasFile('event_logo')) {
            $destinationPath = public_path('storage/event_logos');
            if (! file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_logo');
            $filename = time().'_'.$file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $logoPath = 'event_logos/'.$filename;
        }

        Events::create(array_merge(
            $request->except(['event_banner', 'event_logo']),
            [
                'event_banner' => $bannerPath,
                'event_logo' => $logoPath,
                // Blank means "no end date" — sign-ups then stay open indefinitely.
                'event_end_date' => $request->filled('event_end_date') ? $request->event_end_date : null,
                'is_enabled' => $request->has('is_enabled') ? (bool) $request->is_enabled : false,
                'show_all_locations' => $request->has('show_all_locations') ? (bool) $request->show_all_locations : false,
                'show_all_locations_draw' => $request->has('show_all_locations_draw') ? (bool) $request->show_all_locations_draw : false,
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
            'event_end_date' => 'nullable|date|after_or_equal:event_date',
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

        // Blank means "no end date" — sign-ups then stay open indefinitely.
        $updateData['event_end_date'] = $request->filled('event_end_date') ? $request->event_end_date : null;

        // Handle is_enabled checkbox (convert to boolean)
        $updateData['is_enabled'] = $request->has('is_enabled') ? (bool) $request->is_enabled : false;
        $updateData['show_all_locations'] = $request->has('show_all_locations') ? (bool) $request->show_all_locations : false;
        $updateData['show_all_locations_draw'] = $request->has('show_all_locations_draw') ? (bool) $request->show_all_locations_draw : false;

        // Handle banner upload - only update if new file is provided
        if ($request->hasFile('event_banner')) {
            $destinationPath = public_path('storage/event_banners');
            if (! file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_banner');
            $filename = time().'_'.$file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $updateData['event_banner'] = 'event_banners/'.$filename;
        }
        // If no new banner uploaded, keep existing one (don't add to updateData)

        // Handle logo upload - only update if new file is provided
        if ($request->hasFile('event_logo')) {
            $destinationPath = public_path('storage/event_logos');
            if (! file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file = $request->file('event_logo');
            $filename = time().'_'.$file->getClientOriginalName();
            $file->move($destinationPath, $filename);
            $updateData['event_logo'] = 'event_logos/'.$filename;
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
        if (! $event) {
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
