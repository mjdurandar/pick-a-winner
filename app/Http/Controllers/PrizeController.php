<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Prize;

class PrizeController extends Controller
{
    public function addPrize(Request $request) {

        $request->validate([
            'event_id' => 'required|exists:events,id',
        ]);
    
        // ✅ Create the new prize
        $prize = Prize::create([
            'event_id' => $request->event_id,
            'location_id' => $request->location_id,
            'prize_name' => 'Prize Name',
            'winner' => $request->winner_name ?? "No Winner Yet",
            'winner_email' => $request->winner_email ?? "No Winner Yet",
            'winner_mobile_number' => $request->winner_mobile_number ?? "No Winner Yet",
        ]);
    
        return redirect()->back()->with('success', 'Prize created successfully.');
    }

    public function addPrizeAllLocation(Request $request) {

        $request->validate([
            'event_id' => 'required|exists:events,id',
        ]);
    
        // ✅ Create the new prize
        $prize = Prize::create([
            'event_id' => $request->event_id,
            'location_id' => $request->location_id,
            'prize_name' => $request->prize_name,
            'winner' => $request->winner_name ?? "No Winner Yet",
            'winner_email' => $request->winner_email ?? "No Winner Yet",
            'winner_mobile_number' => $request->winner_mobile_number ?? "No Winner Yet",
        ]);
    
        return redirect()->back()->with('success', 'Prize created successfully.');
    }

    public function addWinner(Request $request, Prize $prize) {
        // ✅ Update the prize
        $prize->update([
            'winner' => $request->winner_name,
            'winner_email' => $request->winner_email,
            'winner_mobile_number' => $request->winner_mobile_number,
        ]);
    
        return redirect()->back()->with('success', 'Winner assigned successfully.');
    } 
    
    public function update(Request $request, Prize $prize) {
     
        $request->validate([
            'prize_name' => 'required|string|max:255',
        ]);
    
        // ✅ Update the prize
        $prize->update([
            'prize_name' => $request->prize_name,
        ]);
    
        return redirect()->back()->with('success', 'Prize updated successfully.');
    }

    public function destroy(Prize $prize) {
        // ✅ Delete the prize
        $prize->delete();
    
        return redirect()->back()->with('success', 'Prize deleted successfully.');
    }

    public function storeMultiple(Request $request)
    {
        $request->validate([
            'prizes' => 'required|string',
            'event_id' => 'required|exists:events,id',
            'location_id' => 'required|exists:locations,id',
        ]);

        $prizes = json_decode($request->prizes, true);
        
        if (!is_array($prizes)) {
            return back()->withErrors(['prizes' => 'Invalid prizes data']);
        }

        $createdPrizes = [];
        foreach ($prizes as $prizeName) {
            $prize = Prize::create([
                'event_id' => $request->event_id,
                'location_id' => $request->location_id,
                'prize_name' => $prizeName,
                'winner' => "No Winner Yet",
            ]);
            $createdPrizes[] = $prize;
        }

        return back()->with('success', count($createdPrizes) . ' prizes created successfully');
    }

}
