<?php

/**
 * Helpers globaux pour l'application
 */

/**
 * Génère une réponse de succès API
 */
if (!function_exists('apiSuccess')) {
    function apiSuccess($data = null, $message = 'Success', $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}

/**
 * Génère une réponse d'erreur API
 */
if (!function_exists('apiError')) {
    function apiError($message = 'Error', $code = 400, $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}

/**
 * Log les événements importants
 */
if (!function_exists('logEvent')) {
    function logEvent($message, $context = [])
    {
        \Illuminate\Support\Facades\Log::info($message, $context);
    }
}

/**
 * Génère un UUID v4
 */
if (!function_exists('generateUUID')) {
    function generateUUID()
    {
        return \Illuminate\Support\Str::uuid();
    }
}
