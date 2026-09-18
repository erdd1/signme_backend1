<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route au super admin exclusivement — utilisé pour le module
 * Système (sauvegardes, restauration, mode maintenance, logs) et la
 * gestion des mini-admins eux-mêmes, jamais délégable via
 * admin_permissions.
 *
 * Usage dans les routes :
 *   ->middleware('super.admin')
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Action réservée au super administrateur.',
            ], 403);
        }

        return $next($request);
    }
}
