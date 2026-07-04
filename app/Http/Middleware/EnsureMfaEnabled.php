<?php

namespace App\Http\Middleware;

use App\Modules\Auth\Application\Services\MFAService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware imposant le MFA aux administrateurs.
 *
 * Le dashboard admin exige une authentification à deux facteurs. Tant que
 * l'administrateur connecté n'a pas confirmé son MFA (via
 * /admin/auth/mfa/enable puis /confirm), tout le reste de l'API admin lui
 * est bloqué — seul le sous-groupe /admin/auth/* (non protégé par ce
 * middleware) reste accessible pour lui permettre de terminer la
 * configuration.
 *
 * @package App\Http\Middleware
 */
class EnsureMfaEnabled
{
    public function __construct(private readonly MFAService $mfaService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'administrateur' && !$this->mfaService->isMFAEnabled($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration de l\'authentification à deux facteurs requise avant d\'accéder à cette ressource.',
                'errors'  => ['mfa_setup_required' => true],
            ], 403);
        }

        return $next($request);
    }
}
