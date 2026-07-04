<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Core\Exceptions\ApiException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // =====================================================================
        // Middlewares globaux (appliqués à TOUS les endpoints)
        // =====================================================================
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);

        // =====================================================================
        // Aliases des middlewares personnalisés (utilisables dans les routes)
        // =====================================================================
        $middleware->alias([
            'role'             => \App\Http\Middleware\CheckRole::class,
            'not.admin'        => \App\Http\Middleware\NotAdminRole::class,
            'check.actif'      => \App\Http\Middleware\CheckActif::class,
            'ancien.service'   => \App\Http\Middleware\CheckAncienDeService::class,
            'mfa.enabled'      => \App\Http\Middleware\EnsureMfaEnabled::class,
        ]);

        // =====================================================================
        // IMPORTANT : CheckActif n'est PAS dans le groupe global 'api'
        // car le webhook Campay est une route publique sans token.
        // CheckActif est appliqué sélectivement via middleware('auth:sanctum', 'check.actif')
        // directement dans routes/api.php sur les routes protégées.
        // =====================================================================
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // =====================================================================
        // Gestion centralisée des exceptions → réponses JSON standardisées
        // =====================================================================

        // Exceptions métier personnalisées
        $exceptions->render(function (ApiException $e) {
            $response = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
            if ($e->getErrors() !== null) {
                $response['errors'] = $e->getErrors();
            }

            $json = response()->json($response, $e->getStatusCode());

            if ($e->getStatusCode() === 429) {
                $retryAfter = is_array($e->getErrors())
                    ? ($e->getErrors()['retry_after'] ?? null)
                    : null;
                if ($retryAfter) {
                    $json->header('Retry-After', (string) $retryAfter);
                }
            }

            return $json;
        });

        // Erreurs de validation Laravel (FormRequest)
        $exceptions->render(function (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation des données',
                'errors'  => $e->errors(),
            ], 422);
        });

        // Non authentifié (token manquant ou expiré)
        $exceptions->render(function (AuthenticationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié. Veuillez vous connecter.',
            ], 401);
        });

        // Modèle Eloquent non trouvé (findOrFail)
        $exceptions->render(function (ModelNotFoundException $e) {
            $model = class_basename($e->getModel());
            return response()->json([
                'success' => false,
                'message' => "{$model} non trouvé(e).",
            ], 404);
        });
    })->create();
