<?php

namespace App\Modules\Groupe\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Groupe;
use App\Models\GroupeEntreeCash;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Argent reçu hors application (cash) — saisi par le président ou le
 * trésorier, toujours distinct de l'argent reçu par l'application.
 * Immuable : une erreur s'annule avec un motif, elle ne se modifie pas.
 */
class GroupeCashService
{
    public function enregistrer(User $auteur, Groupe $groupe, array $data): GroupeEntreeCash
    {
        return $groupe->entreesCash()->create([
            'auteurId'   => $auteur->id,
            'montant'    => (int) $data['montant'],
            'donateur'   => isset($data['donateur']) ? trim($data['donateur']) ?: null : null,
            'note'       => isset($data['note']) ? trim($data['note']) ?: null : null,
            'dateEntree' => $data['dateEntree'] ?? now()->toDateString(),
        ])->load('auteur:id,nom');
    }

    /** @throws ApiException */
    public function annuler(User $auteur, Groupe $groupe, string $uuid, string $motif): GroupeEntreeCash
    {
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Entrée');
        }

        $entree = $groupe->entreesCash()->where('uuid', $uuid)->first()
            ?? throw ApiException::notFound('Entrée');

        if ($entree->estAnnulee()) {
            throw ApiException::conflict('Cette entrée est déjà annulée.');
        }

        $entree->update([
            'annuleAt'        => now(),
            'annuleParId'     => $auteur->id,
            'motifAnnulation' => trim($motif),
        ]);

        return $entree->fresh('auteur:id,nom');
    }
}
