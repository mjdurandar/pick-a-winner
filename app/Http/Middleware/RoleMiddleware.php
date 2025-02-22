<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {   
        // Ensure the user is authenticated
        if (!Auth::check()) {
            abort(403, 'Unauthorized');
        }

        // Get the user role
        $userRole = Auth::user()->role;
        
        // Debugging: Log role
        // \Log::info("User Role: " . $userRole);

        // Check if user role exists in allowed roles
        if (!in_array($userRole, $roles)) {
            abort(403, 'Unauthorized Access');
        }

        return $next($request);
    }
}
