<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de vérification des rôles utilisateur.
 *
 * Usage dans les routes :
 *   ->middleware('role:administrateur')
 *   ->middleware('role:pasteur,administrateur')
 *
 * @package App\Http\Middleware
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string  ...$roles  Rôles autorisés (séparés par des virgules)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        if (empty($roles) || in_array($user->role, $roles, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous n\'avez pas les droits nécessaires pour cette action.',
            'your_role' => $user->role,
            'required_roles' => $roles,
        ], 403);
    }
}
