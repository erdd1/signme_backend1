<?php

namespace App\Modules\UserLookup\Application\Services;

use App\Models\Ville;

/**
 * Service Ville — Villes de résidence, gérées par le pasteur/la secrétaire.
 * Chaque ville est marquée "Au Cameroun" ou "Hors Cameroun" (estDiaspora)
 * dès sa création (champ validé par CreateVilleRequest, transmis tel quel
 * via AbstractLookupService::create()).
 */
class VilleService extends AbstractLookupService
{
    protected function modelClass(): string
    {
        return Ville::class;
    }

    protected function label(): string
    {
        return 'ville';
    }
}
