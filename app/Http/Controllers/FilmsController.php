<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Films;
use Inertia\Inertia;

class FilmsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() {
        return Inertia::render('Films', [
            'films' => Films::withCount('events')->orderBy('name')->get(),
        ]);
    }
    
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {
        $request->validate([
            'name' => 'required|string|max:255|unique:films,name',
            'country' => 'nullable|string|max:255',
        ]);
    
        Films::create([
            'name' => $request->name,
            'country' => $request->country,
        ]);
    
        return redirect()->route('films.index')->with('success', 'Film created successfully.');
    }
    
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Films $film)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:films,name,' . $film->id,
            'country' => 'nullable|string|max:255',
        ]);

        $film->update([
            'name' => $request->name,
            'country' => $request->country,
        ]);

        return redirect()->route('films.index')->with('success', 'Film updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Films $film)
    {
        if (!$film) {
            return response()->json(['error' => 'Film not found.'], 404);
        }
    
        // Check if film has associated events
        if ($film->events()->count() > 0) {
            return redirect()->route('films.index')->with('error', 'Cannot delete film with associated events.');
        }
    
        $film->delete();
    
        return redirect()->route('films.index')->with('success', 'Film deleted successfully.');
    }
}
