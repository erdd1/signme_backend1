<?php

namespace App\Modules\UserLookup\Application\Services;

use App\Models\Groupe;

/**
 * Service Groupe — Groupes/ministères de l'église (ex. Chorale, Jeunesse),
 * gérés par le pasteur/la secrétaire.
 */
class GroupeService extends AbstractLookupService
{
    protected function modelClass(): string
    {
        return Groupe::class;
    }

    protected function label(): string
    {
        return 'groupe';
    }
}
