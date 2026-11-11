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
    /** @throws ApiException communiqué ou membre étranger au groupe */
    public function enregistrer(User $auteur, Groupe $groupe, array $data): GroupeEntreeCash
    {
        $communique = null;
        if (!empty($data['communiqueUuid'])) {
            $communique = $groupe->communiques()
                ->where('uuid', $data['communiqueUuid'])
                ->whereNull('archivedAt')
                ->first() ?? throw ApiException::notFound('Communiqué');
        }

        $membreId = null;
        if (!empty($data['membreId'])) {
            if (!$groupe->membres()->where('users.id', (int) $data['membreId'])->exists()) {
                throw ApiException::unprocessable('Ce membre n\'appartient pas au groupe.');
            }
            $membreId = (int) $data['membreId'];
        }

        return $groupe->entreesCash()->create([
            'auteurId'   => $auteur->id,
            'communiqueId' => $communique?->id,
            'membreId'   => $membreId,
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
