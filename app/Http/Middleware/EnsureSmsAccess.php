<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the SMS scheduler to the one account that owns it.
 *
 * Same reasoning as EnsureMasterSheetAccess: hiding the nav link is not a
 * permission. Every button on that screen spends SMS credits, so the routes
 * refuse anyone else even if they know the URL — admin role included.
 */
class EnsureSmsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canManageSms()) {
            abort(403, 'The SMS scheduler is limited to the account that owns it.');
        }

        return $next($request);
    }
}
