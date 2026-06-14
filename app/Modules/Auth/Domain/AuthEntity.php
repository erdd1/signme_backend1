<?php

namespace App\Modules\Auth\Domain;

/**
 * Entité domaine Auth — représentation pure (sans Eloquent)
 *
 * Encapsule les données d'authentification sans dépendance framework.
 *
 * PHASE 1 : Incluides tokens access + refresh pour rotation
 */
final class AuthEntity
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $nom,
        public readonly string  $email,
        public readonly string  $role,
        public readonly bool    $actif,
        public readonly bool    $est_de_service,
        public readonly ?int    $churchId,
        public readonly ?string $photoUrl,
        public readonly string  $token,              // Access token
        public readonly ?string $refreshToken = null, // Refresh token (PHASE 1)
        public readonly ?array  $church = null,       // Données de l'église
    ) {}

    public function toArray(): array
    {
        $data = [
            'id'             => $this->id,
            'nom'            => $this->nom,
            'email'          => $this->email,
            'role'           => $this->role,
            'actif'          => $this->actif,
            'est_de_service' => $this->est_de_service,
            'churchId'       => $this->churchId,
            'photoUrl'       => $this->photoUrl,
            'access_token'   => $this->token,
        ];

        // Inclure les données de l'église si disponible
        if ($this->church) {
            $data['church'] = $this->church;
        }

        // Inclure refresh token si disponible (PHASE 1)
        if ($this->refreshToken) {
            $data['refresh_token'] = $this->refreshToken;
            $data['token_type'] = 'Bearer';
            $data['expires_in'] = config('security.tokens.access_token_expires_in_minutes') * 60; // en secondes
        }

        return $data;
    }
}
