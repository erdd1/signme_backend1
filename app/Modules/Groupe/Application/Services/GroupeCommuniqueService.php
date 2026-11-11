<?php

namespace App\Modules\Groupe\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Contribution;
use App\Models\Groupe;
use App\Models\GroupeCommunique;
use App\Models\User;
use App\Modules\Groupe\Application\Jobs\SendGroupePushJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Communiqués d'un groupe — créés, modifiés et archivés par son président
 * uniquement ; lus par tous les membres. Jamais supprimés en base.
 */
class GroupeCommuniqueService
{
    public function listActifs(Groupe $groupe)
    {
        return $groupe->communiques()
            ->whereNull('archivedAt')
            ->with('auteur:id,nom')
            ->orderByDesc('created_at')
            ->get();
    }

    public function creer(User $auteur, Groupe $groupe, array $data): GroupeCommunique
    {
        $communique = DB::transaction(fn () => $groupe->communiques()->create([
            'auteurId'        => $auteur->id,
            'titre'           => trim($data['titre']),
            'message'         => trim($data['message']),
            'objectifMontant' => $data['objectifMontant'] ?? null,
            'dateDebut'       => $data['dateDebut'] ?? null,
            'dateFin'         => $data['dateFin'] ?? null,
        ]));

        SendGroupePushJob::dispatch(
            $groupe->id,
            $auteur->id,
            "Communiqué — {$groupe->nom}",
            $communique->titre,
            ['type' => 'groupe_communique', 'id' => $communique->uuid, 'groupe' => $groupe->uuid],
        );

        return $communique;
    }

    public function modifier(Groupe $groupe, string $uuid, array $data): GroupeCommunique
    {
        $communique = $this->trouver($groupe, $uuid);

        $champs = [];
        foreach (['titre', 'message'] as $texte) {
            if (isset($data[$texte])) {
                $champs[$texte] = trim($data[$texte]);
            }
        }
        // Objectif et dates : présents (même à null) = modifiés, absents = inchangés.
        foreach (['objectifMontant', 'dateDebut', 'dateFin'] as $champ) {
            if (array_key_exists($champ, $data)) {
                $champs[$champ] = $data[$champ];
            }
        }
        $communique->update($champs);

        return $communique->fresh('auteur:id,nom');
    }

    /** « Supprimer » = archiver : les contributions déjà reçues restent visibles. */
    public function archiver(Groupe $groupe, string $uuid): void
    {
        $this->trouver($groupe, $uuid)->update(['archivedAt' => now()]);
    }

    /** @throws ApiException */
    public function trouver(Groupe $groupe, string $uuid, bool $inclureArchives = false): GroupeCommunique
    {
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Communiqué');
        }

        $query = $groupe->communiques()->where('uuid', $uuid);
        if (!$inclureArchives) {
            $query->whereNull('archivedAt');
        }

        return $query->first() ?? throw ApiException::notFound('Communiqué');
    }

    /**
     * Collecte d'un communiqué : montant net reçu et nombre de contributions
     * complétées. Une seule requête pour tous les communiqués d'un groupe.
     *
     * @return array<int, array{collecte: int, nombre: int}> indexé par communiqueId
     */
    public function statsParCommunique(Groupe $groupe): array
    {
        return Contribution::where('groupeCollecteId', $groupe->id)
            ->where('statut', Contribution::STATUT_COMPLETED)
            ->whereNotNull('communiqueId')
            ->groupBy('communiqueId')
            ->selectRaw('"communiqueId", SUM(montant) as collecte, COUNT(*) as nombre')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->communiqueId => [
                'collecte' => (int) $r->collecte,
                'nombre'   => (int) $r->nombre,
            ]])
            ->all();
    }
}
