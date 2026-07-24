<?php

namespace App\Http\Controllers;

use App\Models\Prize;
use Illuminate\Http\Request;

class PrizeController extends Controller
{
    public function addPrize(Request $request)
    {

        $request->validate([
            'event_id' => 'required|exists:events,id',
            'prize_name' => 'nullable|string|max:255',
            'client_uuid' => 'nullable|string|max:64',
        ]);

        if ($request->filled('client_uuid')) {
            // ✅ Idempotent upsert keyed by the client-generated uuid so the
            // offline sync queue can safely retry without duplicating winners.
            $prize = Prize::firstOrNew(['client_uuid' => $request->client_uuid]);
            $prize->event_id = $request->event_id;
            if ($request->filled('location_id')) {
                $prize->location_id = $request->location_id;
            }
            if ($request->has('prize_name')) {
                $prize->prize_name = $request->prize_name;
            }
            if ($request->filled('winner_name')) {
                $prize->winner = $request->winner_name;
                $prize->winner_email = $request->winner_email ?? 'No Winner Yet';
                $prize->winner_mobile_number = $request->winner_mobile_number ?? 'No Winner Yet';
            } elseif (! $prize->exists) {
                $prize->winner = 'No Winner Yet';
                $prize->winner_email = 'No Winner Yet';
                $prize->winner_mobile_number = 'No Winner Yet';
            }
            $prize->save();
        } else {
            // ✅ Create the new prize (legacy path without idempotency key)
            $prize = Prize::create([
                'event_id' => $request->event_id,
                'location_id' => $request->location_id,
                'prize_name' => $request->prize_name,
                'winner' => $request->winner_name ?? 'No Winner Yet',
                'winner_email' => $request->winner_email ?? 'No Winner Yet',
                'winner_mobile_number' => $request->winner_mobile_number ?? 'No Winner Yet',
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'prize' => $prize]);
        }

        return redirect()->back()->with('success', 'Prize created successfully.');
    }

    public function addPrizeAllLocation(Request $request)
    {

        $request->validate([
            'event_id' => 'required|exists:events,id',
        ]);

        // ✅ Create the new prize
        $prize = Prize::create([
            'event_id' => $request->event_id,
            'location_id' => $request->location_id,
            'prize_name' => $request->prize_name,
            'winner' => $request->winner_name ?? 'No Winner Yet',
            'winner_email' => $request->winner_email ?? 'No Winner Yet',
            'winner_mobile_number' => $request->winner_mobile_number ?? 'No Winner Yet',
        ]);

        return redirect()->back()->with('success', 'Prize created successfully.');
    }

    public function addWinner(Request $request, Prize $prize)
    {
        // ✅ Update the prize
        $prize->update([
            'winner' => $request->winner_name,
            'winner_email' => $request->winner_email,
            'winner_mobile_number' => $request->winner_mobile_number,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'prize' => $prize]);
        }

        return redirect()->back()->with('success', 'Winner assigned successfully.');
    }

    public function update(Request $request, Prize $prize)
    {

        $request->validate([
            'prize_name' => 'required|string|max:255',
        ]);

        // ✅ Update the prize
        $prize->update([
            'prize_name' => $request->prize_name,
        ]);

        return redirect()->back()->with('success', 'Prize updated successfully.');
    }

    public function destroy(Prize $prize)
    {
        // ✅ Delete the prize
        $prize->delete();

        return redirect()->back()->with('success', 'Prize deleted successfully.');
    }

    /**
     * ✅ Idempotent delete used by the offline sync queue. Targets a prize by
     * id and/or client_uuid within the verified event; deleting a prize that
     * no longer exists (or never synced) succeeds as a no-op so retries and
     * mid-sync races resolve cleanly.
     */
    public function destroyQueued(Request $request)
    {
        $request->validate([
            'event_id' => 'required|exists:events,id',
            'prize_id' => 'nullable|integer',
            'client_uuid' => 'nullable|string|max:64',
        ]);

        if (! $request->filled('prize_id') && ! $request->filled('client_uuid')) {
            return response()->json(['success' => false, 'message' => 'prize_id or client_uuid required.'], 422);
        }

        Prize::where('event_id', $request->event_id)
            ->where(function ($query) use ($request) {
                if ($request->filled('prize_id')) {
                    $query->orWhere('id', $request->prize_id);
                }
                if ($request->filled('client_uuid')) {
                    $query->orWhere('client_uuid', $request->client_uuid);
                }
            })
            ->delete();

        return response()->json(['success' => true]);
    }

    public function storeMultiple(Request $request)
    {
        $request->validate([
            'prizes' => 'required|string',
            'event_id' => 'required|exists:events,id',
            'location_id' => 'required|exists:locations,id',
        ]);

        $prizes = json_decode($request->prizes, true);

        if (! is_array($prizes)) {
            return back()->withErrors(['prizes' => 'Invalid prizes data']);
        }

        $createdPrizes = [];
        foreach ($prizes as $prizeName) {
            $prize = Prize::create([
                'event_id' => $request->event_id,
                'location_id' => $request->location_id,
                'prize_name' => $prizeName,
                'winner' => 'No Winner Yet',
            ]);
            $createdPrizes[] = $prize;
        }

        return back()->with('success', count($createdPrizes).' prizes created successfully');
    }
}
