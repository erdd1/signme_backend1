<?php

namespace App\Core\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Trait ApiResponseTrait
 *
 * Fournit des méthodes de réponse JSON standardisées pour toute l'API.
 * Toutes les réponses suivent le format : {success, message, data, pagination?}
 *
 * @package App\Core\Traits
 */
trait ApiResponseTrait
{
    /**
     * Réponse de succès générique (200 OK)
     */
    protected function successResponse(mixed $data = null, string $message = 'Succès', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Réponse de création réussie (201 Created)
     */
    protected function createdResponse(mixed $data = null, string $message = 'Ressource créée avec succès'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Réponse sans contenu (204 No Content) - ex: suppression
     */
    protected function noContentResponse(string $message = 'Supprimé avec succès'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => null,
        ], 200);
    }

    /**
     * Réponse paginée
     */
    protected function paginatedResponse(mixed $paginator, string $message = 'Succès'): JsonResponse
    {
        return response()->json([
            'success'    => true,
            'message'    => $message,
            'data'       => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ], 200);
    }

    /**
     * Réponse d'erreur générique
     */
    protected function errorResponse(string $message = 'Une erreur est survenue', int $code = 400, mixed $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Réponse non autorisée (401)
     */
    protected function unauthorizedResponse(string $message = 'Non authentifié'): JsonResponse
    {
        return $this->errorResponse($message, 401);
    }

    /**
     * Réponse interdite (403)
     */
    protected function forbiddenResponse(string $message = 'Accès refusé'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Réponse non trouvée (404)
     */
    protected function notFoundResponse(string $message = 'Ressource non trouvée'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Réponse d'erreur de validation (422)
     */
    protected function validationErrorResponse(mixed $errors, string $message = 'Erreur de validation'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * Réponse d'erreur serveur (500)
     */
    protected function serverErrorResponse(string $message = 'Erreur interne du serveur'): JsonResponse
    {
        return $this->errorResponse($message, 500);
    }
}
