<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de vérification d'activité du compte utilisateur.
 *
 * Bloque les utilisateurs dont le compte a été désactivé par un admin/pasteur
 * même s'ils possèdent un token Sanctum valide.
 *
 * @package App\Http\Middleware
 */
class CheckActif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->actif) {
            // Révoque tous les tokens du compte désactivé
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'message' => 'Votre compte a été désactivé. Veuillez contacter votre pasteur ou l\'administrateur.',
            ], 403);
        }

        return $next($request);
    }
}
