<?php

namespace App\Http\Controllers;

use App\Models\Events;
use App\Models\Location;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterSheetController extends Controller
{
    public function index(Request $request)
    {
        // Get available years from events
        $years = Events::selectRaw('DISTINCT event_year')
            ->whereNotNull('event_year')
            ->orderBy('event_year', 'desc')
            ->pluck('event_year')
            ->toArray();

        $selectedYear = $request->query('year', $years[0] ?? date('Y'));

        // Get events for the selected year (these become the tabs)
        $events = Events::with('film')
            ->where('event_year', $selectedYear)
            ->orderBy('event_name')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'event_name' => $e->event_name,
                'film_name' => $e->film?->name ?? '',
            ]);

        // Get all locations for events in this year
        $locations = Location::with(['event.film'])
            ->where('is_hidden', false)
            ->whereHas('event', function ($q) use ($selectedYear) {
                $q->where('event_year', $selectedYear);
            })
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($location) {
                $name = $location->name ?? '';
                $locationName = '';
                $cinema = '';

                if (strpos($name, ' - ') !== false) {
                    $parts = explode(' - ', $name, 2);
                    $locationName = trim($parts[0]);
                    $cinema = trim($parts[1] ?? '');
                } else {
                    $locationName = $name;
                }

                return [
                    'id' => $location->id,
                    'event_id' => $location->event_id,
                    'film' => $location->event?->film?->name ?? '',
                    'event_name' => $location->event?->event_name ?? '',
                    'location' => $locationName,
                    'cinema' => $cinema,
                    'country' => $location->country ?? '',
                    'date' => $location->date,
                    'time' => $location->time,
                    'number_of_screenings' => $location->number_of_screenings,
                    'show_type' => $location->category ?? '',
                    'status' => $location->status ?? '',
                    'ticketing_type' => $location->ticketing_type ?? '',
                    'booked_by' => $location->booked_by ?? '',
                    'date_booking_confirmed' => $location->date_booking_confirmed,
                    'film_format' => $location->film_format ?? '',
                    'cinema_contact' => $location->cinema_contact ?? '',
                    'dcp_trailer_sent' => (bool) $location->dcp_trailer_sent,
                    'media_kit_sent' => (bool) $location->media_kit_sent,
                    'dcp_sent' => (bool) $location->dcp_sent,
                    'specific_deliverable_requests' => $location->specific_deliverable_requests ?? '',
                ];
            });

        return Inertia::render('MasterSheet', [
            'locations' => $locations,
            'events' => $events,
            'years' => $years,
            'filters' => [
                'year' => (int) $selectedYear,
            ],
        ]);
    }

    public function update(Request $request, Location $location)
    {
        $validated = $request->validate([
            'cinema_contact' => 'nullable|string|max:255',
            'number_of_screenings' => 'nullable|integer|min:0',
            'status' => 'nullable|string|max:255',
            'ticketing_type' => 'nullable|string|max:255',
            'booked_by' => 'nullable|string|max:255',
            'date_booking_confirmed' => 'nullable|date',
            'film_format' => 'nullable|string|max:255',
            'dcp_trailer_sent' => 'nullable|boolean',
            'media_kit_sent' => 'nullable|boolean',
            'dcp_sent' => 'nullable|boolean',
            'specific_deliverable_requests' => 'nullable|string',
        ]);

        $location->update($validated);

        return back()->with('success', 'Location updated successfully.');
    }
}
