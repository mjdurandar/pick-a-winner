<?php

namespace App\Http\Middleware;

use App\Models\Prize;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the public Pick-a-Winner prize endpoints.
 *
 * These routes are used in two ways:
 *  1. By authenticated admin/host users from the Location screens.
 *  2. By unauthenticated hosts running a live draw, after they have entered
 *     the correct location/event password (which sets a verified session via
 *     PickaWinnerController::verify()).
 *
 * Anyone else (an anonymous internet request with no verified session) is
 * rejected, so prizes can no longer be created/deleted/reassigned at will.
 *
 * When the request targets a specific {prize}, we additionally check that the
 * prize belongs to an event the session was verified for, so one verified
 * draw cannot tamper with another event's prizes.
 */
class EnsurePickAWinnerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Authenticated admin/host users are always allowed.
        if (Auth::check()) {
            return $next($request);
        }

        $verifiedEventIds = array_filter([
            $request->session()->get('verified_event_id'),
            $request->session()->get('verified_all_locations_event_id'),
        ]);

        if (empty($verifiedEventIds)) {
            abort(403, 'You must unlock a Pick a Winner draw before managing prizes.');
        }

        $verifiedEventIds = array_map('intval', $verifiedEventIds);

        // If a specific prize is being acted on, ensure it belongs to a
        // verified event. The route parameter may be the bound model or the
        // raw id depending on middleware order, so handle both.
        $prizeParam = $request->route('prize');
        if ($prizeParam !== null) {
            $prize = $prizeParam instanceof Prize
                ? $prizeParam
                : Prize::find($prizeParam);

            if (!$prize || !in_array((int) $prize->event_id, $verifiedEventIds, true)) {
                abort(403, 'This prize does not belong to a draw you have unlocked.');
            }
        }

        // For create endpoints, ensure the target event matches a verified one.
        if ($request->filled('event_id')) {
            if (!in_array((int) $request->input('event_id'), $verifiedEventIds, true)) {
                abort(403, 'You can only manage prizes for a draw you have unlocked.');
            }
        }

        return $next($request);
    }
}
