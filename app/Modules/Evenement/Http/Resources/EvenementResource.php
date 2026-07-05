<?php

namespace App\Modules\Evenement\Http\Resources;

use App\Models\Evenement;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource Evenement — fil d'actualité et détail.
 *
 * Le compte à rebours d'archivage et le pourcentage de progression vers
 * l'objectif financier sont calculés à la volée (jamais stockés en base).
 */
class EvenementResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Evenement $this */
        $authUser = $request->user();

        return [
            'uuid'                => $this->uuid,
            'titre'               => $this->titre,
            'description'         => $this->description,
            'imageUrl'            => $this->imageUrl,
            'statut'              => $this->statut,
            'dateDebut'           => $this->dateDebut?->format('Y-m-d'),
            'dateFin'             => $this->dateFin?->toIso8601String(),
            'publishedAt'         => $this->publishedAt?->toIso8601String(),
            'scheduledPublishAt'  => $this->scheduledPublishAt?->toIso8601String(),
            'termineAt'           => $this->termineAt?->toIso8601String(),
            'archivedAt'          => $this->archivedAt?->toIso8601String(),
            'objectifMontant'     => $this->objectifMontant,
            'montantCollecte'     => $this->montantCollecte,
            'nombreContributions' => $this->nombreContributions,
            // Uniquement présent sur la vue "Gérer les événements" (comptage de
            // contributeurs distincts calculé via une sous-requête dédiée).
            'nombreContributeurs' => $this->nombreContributeurs !== null ? (int) $this->nombreContributeurs : null,
            'pourcentageObjectif' => $this->pourcentageObjectif(),
            'joursAvantArchivage' => $this->joursAvantArchivage(),
            'auteur'              => [
                'id'       => $this->auteur?->id,
                'nom'      => $this->auteur?->nom,
                'photoUrl' => $this->auteur?->photoUrl,
            ],
            'church' => [
                'id'  => $this->church?->id,
                'nom' => $this->church?->nom,
            ],
            'estAuteur'      => $authUser ? $this->auteurId === $authUser->id : false,
            'peutContribuer' => $this->statut === Evenement::STATUT_ACTIF,
            'peutGerer'      => $authUser
                ? ($authUser->isAdmin() || in_array($authUser->role, ['pasteur', 'secretaire'], true))
                : false,
            'createdAt'      => $this->created_at?->toIso8601String(),
            'updatedAt'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
