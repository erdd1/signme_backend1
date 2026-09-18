<?php

namespace App\Http\Middleware;

use App\Core\Services\AdminResourceRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie qu'un admin a le droit d'effectuer l'action demandée sur un
 * module donné. L'action est déduite automatiquement du verbe HTTP — un
 * seul `->middleware('permission:<resource>')` sur tout un groupe de
 * routes suffit, pas besoin de préciser l'action par route.
 *
 * Un super admin passe toujours (voir User::hasPermission()). Un
 * mini-admin sans permission explicite pour ce module est bloqué (403),
 * quelle que soit l'action.
 *
 * Usage dans les routes :
 *   ->middleware('permission:publications')
 */
class CheckAdminPermission
{
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        if (!AdminResourceRegistry::isValid($resource)) {
            // Erreur de configuration côté route, pas côté utilisateur —
            // ne doit jamais arriver en usage normal.
            abort(500, "Module admin inconnu : {$resource}");
        }

        $action = match ($request->method()) {
            'GET', 'HEAD' => 'view',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'view',
        };

        if ($user->hasPermission($resource, $action)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => "Vous n'avez pas la permission nécessaire pour cette action.",
            'resource' => $resource,
            'action' => $action,
        ], 403);
    }
}
