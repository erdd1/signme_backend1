<?php

namespace App\Observers;

/**
 * Class SignatureObserver
 * 
 * Observer pour écouter les événements du modèle Signature
 */
class SignatureObserver
{
    /**
     * Avant la création
     */
    public function creating($signature): void
    {
        // À implémenter - logique avant création
    }

    /**
     * Après la création
     */
    public function created($signature): void
    {
        // À implémenter - logique après création
    }

    /**
     * Avant la mise à jour
     */
    public function updating($signature): void
    {
        // À implémenter
    }

    /**
     * Après la mise à jour
     */
    public function updated($signature): void
    {
        // À implémenter
    }

    /**
     * Avant la suppression
     */
    public function deleting($signature): void
    {
        // À implémenter
    }

    /**
     * Après la suppression
     */
    public function deleted($signature): void
    {
        // À implémenter
    }
}
