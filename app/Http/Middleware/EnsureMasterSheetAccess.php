<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the master sheet sync screen to the account that owns it.
 *
 * The nav entry is hidden from everyone else, but a hidden link is not a
 * permission — the routes themselves have to refuse, or anyone who knows the
 * URL could connect a Google account or delete a location.
 */
class EnsureMasterSheetAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canManageMasterSheet()) {
            abort(403, 'The master sheet sync is limited to the account that owns the Google connection.');
        }

        return $next($request);
    }
}
