<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NotAdminRole Middleware
 * 
 * Rejects requests from administrateur users
 * Used for CLIENT API to prevent admin access to client endpoints
 */
class NotAdminRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If no user authenticated, let next middleware handle it
        if (!$user) {
            return $next($request);
        }

        // Reject if user is administrateur
        if ($user->role === 'administrateur') {
            return response()->json([
                'success' => false,
                'message' => 'Administrator users cannot access client endpoints. Please use /api/admin instead.',
                'code' => 'ADMIN_FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
