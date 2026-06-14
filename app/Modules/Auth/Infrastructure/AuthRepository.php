<?php

namespace App\Modules\Auth\Application;

/**
 * Class AuthService
 * 
 * Service métier pour l'authentification
 */
class AuthService
{
    public function login($email, $password)
    {
        // À implémenter
    }

    public function logout($token)
    {
        // À implémenter
    }

    public function refresh($token)
    {
        // À implémenter
    }

    public function validateToken($token)
    {
        // À implémenter
    }
}
