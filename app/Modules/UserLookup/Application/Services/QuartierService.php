<?php

namespace App\Modules\UserLookup\Application\Services;

use App\Models\Quartier;

/**
 * Service Quartier — Quartiers de résidence, gérés par le pasteur/la secrétaire.
 */
class QuartierService extends AbstractLookupService
{
    protected function modelClass(): string
    {
        return Quartier::class;
    }

    protected function label(): string
    {
        return 'quartier';
    }
}
