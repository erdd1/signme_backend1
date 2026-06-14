<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour vérifier qu'un ancien est bien "de service".
 *
 * Appliqué uniquement sur les routes de signature présentielle.
 * Un ancien doit avoir est_de_service = true pour signer en présentiel.
 * Les pasteurs et admins sont toujours autorisés.
 *
 * @package App\Http\Middleware
 */
class CheckAncienDeService
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        // Pasteurs, secrétaires et admins ont toujours accès
        if (in_array($user->role, ['administrateur', 'pasteur', 'secretaire'], true)) {
            return $next($request);
        }

        // Pour les anciens : vérifier est_de_service
        if ($user->role === 'ancien' && $user->est_de_service) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous n\'êtes pas désigné ancien de service pour ce mois.',
        ], 403);
    }
}
