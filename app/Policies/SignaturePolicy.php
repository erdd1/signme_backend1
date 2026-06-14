<?php

namespace App\Policies;

use App\Models\User;

/**
 * Class SignaturePolicy
 * 
 * Policy pour autoriser les actions sur les signatures
 */
class SignaturePolicy
{
    /**
     * L'utilisateur peut-il voir cette signature?
     */
    public function view(User $user, $signature): bool
    {
        return true; // À implémenter
    }

    /**
     * L'utilisateur peut-il créer une signature?
     */
    public function create(User $user): bool
    {
        return true; // À implémenter
    }

    /**
     * L'utilisateur peut-il mettre à jour cette signature?
     */
    public function update(User $user, $signature): bool
    {
        return $user->id === $signature->user_id;
    }

    /**
     * L'utilisateur peut-il supprimer cette signature?
     */
    public function delete(User $user, $signature): bool
    {
        return $user->id === $signature->user_id;
    }
}
