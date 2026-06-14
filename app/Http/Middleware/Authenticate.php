<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

/**
 * Class Authenticate
 * 
 * Middleware d'authentification pour les routes protégées
 */
class Authenticate extends Middleware
{
    /**
     * Récupère les routes pour lesquelles l'authentification est requise
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|array|null
     */
    protected function redirectTo($request)
    {
        if (!$request->expectsJson()) {
            return route('login');
        }
    }
}
