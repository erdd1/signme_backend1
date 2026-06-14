<?php

namespace App\Modules\AncienDesignation\Application\Services;

use App\Models\AncienDesignation;
use App\Models\User;
use App\Core\Exceptions\ApiException;
use App\Domain\Entities\AncienDesignationEntity;
use App\Domain\ValueObjects\PeriodeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service AncienDesignation — Gestion des anciens de service EEC
 *
 * Un ancien doit être explicitement désigné pour un mois/une église avant
 * de pouvoir accéder à la signature présentielle.
 *
 * Règles métier:
 * - Le pasteur peut seulement désigner un ancien pour le mois actuel ou le mois prochain
 * - Chaque désignation peut avoir des jours et horaires spécifiques (ou 24/7)
 * - Le dernier jour du mois de service, l'ancien est automatiquement retiré
 * - Un ancien désigné peut signer les mois passés (rattrapage)
 *
 * Lorsqu'un ancien est désigné → est_de_service: true sur son compte
 * Lorsqu'il est retiré → est_de_service: false (si plus de désignation active)
 *
 * Accessible uniquement au pasteur et à l'admin.
 */
class AncienDesignationService
{
    /**
     * Liste les anciens désignés pour un mois/église donné.
     *
     * @param User $authUser
     * @param int  $mois
     * @param int  $annee
     * @param int|null $churchId  (admin peut filtrer par église)
     * @return array { designes: [...], non_designes: [...], mois: ..., annee: ..., ... }
     */
    public function listByMonthAndChurch(User $authUser, int $mois, int $annee, ?int $churchId = null): array
    {
        $egliseId = $authUser->isAdmin()
            ? ($churchId ?? $authUser->churchId)
            : $authUser->churchId;

        // Log pour déboguer si l'utilisateur n'a pas d'église
        if (!$egliseId) {
            \Log::warning('Utilisateur sans église détecté', [
                'user_id' => $authUser->id,
                'user_email' => $authUser->email,
                'user_role' => $authUser->role,
                'churchId_field' => $authUser->churchId,
            ]);
            throw ApiException::badRequest('Aucune église sélectionnée. Contactez votre administrateur.');
        }

        // Récupère tous les anciens de l'église
        $allAnciens = User::where('role', User::ROLE_ANCIEN)
                          ->where('churchId', $egliseId)
                          ->where('actif', true)
                          ->get(['id', 'nom', 'photoUrl', 'telephone']);

        // Récupère les désignations pour ce mois/église
        $designations = AncienDesignation::where('egliseId', $egliseId)
                                         ->where('mois', $mois)
                                         ->where('annee', $annee)
                                         ->get(['id', 'ancienId', 'jours_travail', 'horaire_debut', 'horaire_fin']);

        $designeIds = $designations->pluck('ancienId')->toArray();
        $designationsByAncienId = $designations->keyBy('ancienId');

        $designes = [];
        $nonDesignes = [];

        foreach ($allAnciens as $ancien) {
            $estDesigne = \in_array($ancien->id, $designeIds, true);
            
            $data = [
                'id'                  => $ancien->id,
                'nom'                 => $ancien->nom,
                'photoUrl'            => $ancien->photoUrl,
                'telephone'           => $ancien->telephone,
                'est_designe'         => $estDesigne,
            ];

            if ($estDesigne) {
                $designation = $designationsByAncienId[$ancien->id];
                $data['designation_id'] = $designation->id;
                $data['jours_travail'] = $designation->jours_travail;
                $data['horaire_debut'] = $designation->horaire_debut;
                $data['horaire_fin'] = $designation->horaire_fin;
                $data['disponibilite'] = $this->formatDisponibilite(
                    $designation->jours_travail,
                    $designation->horaire_debut,
                    $designation->horaire_fin
                );
                $designes[] = $data;
            } else {
                $nonDesignes[] = $data;
            }
        }

        return [
            'mois'           => $mois,
            'annee'          => $annee,
            'eglise_id'      => $egliseId,
            'designes'       => $designes,
            'non_designes'   => $nonDesignes,
            'total_designes' => \count($designes),
        ];
    }

    /**
     * Désigne un ancien de service pour un mois/église.
     *
     * Validations métier:
     * - La période doit être le mois actuel ou le mois prochain
     * - L'ancien doit être actif et appartenir à l'église
     * - L'ancien ne peut pas être déjà désigné pour ce mois/église
     *
     * @param User $authUser   Pasteur ou admin
     * @param int  $ancienId   ID de l'ancien à désigner
     * @param int  $mois       1 à 12
     * @param int  $annee
     * @param int|null $churchId (admin peut spécifier une église)
     * @param array|null $joursTravail [1,2,3,4,5,6,7] ou null pour 24/7
     * @param string|null $horairesDebut HH:MM ou null
     * @param string|null $horairesFin   HH:MM ou null
     * @return AncienDesignation
     * @throws ApiException
     */
    public function designer(
        User $authUser,
        int $ancienId,
        int $mois,
        int $annee,
        ?int $churchId = null,
        ?array $joursTravail = null,
        ?string $horairesDebut = null,
        ?string $horairesFin = null,
    ): AncienDesignation {
        // Résoudre l'église
        $egliseId = $authUser->isAdmin()
            ? ($churchId ?? $authUser->churchId)
            : $authUser->churchId;

        // Valider la période (mois actuel ou prochain)
        try {
            $periode = PeriodeService::create($mois, $annee);
            
            if (!$periode->estValideForDesignation()) {
                throw new \DomainException(
                    "Seuls le mois actuel et le mois prochain sont autorisés pour la désignation. "
                    . "Vous avez demandé: {$periode}"
                );
            }
        } catch (\DomainException $e) {
            throw ApiException::badRequest($e->getMessage());
        }

        // Vérifier que l'ancien existe et est bien un ancien de cette église
        $ancien = User::where('id', $ancienId)
                      ->where('role', User::ROLE_ANCIEN)
                      ->where('actif', true)
                      ->first();

        if (!$ancien) {
            throw ApiException::notFound('Ancien');
        }

        if (!$authUser->isAdmin() && $ancien->churchId !== $egliseId) {
            throw ApiException::forbidden('Cet ancien n\'appartient pas à votre église.');
        }

        // Vérifier si déjà désigné
        $exists = AncienDesignation::where('ancienId', $ancienId)
                                   ->where('egliseId', $egliseId)
                                   ->where('mois', $mois)
                                   ->where('annee', $annee)
                                   ->exists();

        if ($exists) {
            throw ApiException::conflict(
                "Cet ancien est déjà désigné de service pour {$mois}/{$annee}."
            );
        }

        // Créer l'entité DDD pour valider la logique métier
        try {
            $entity = AncienDesignationEntity::creerNouvelle(
                $ancienId,
                $egliseId,
                $periode,
                $joursTravail,
                $horairesDebut,
                $horairesFin,
                $authUser->id
            );
        } catch (\DomainException $e) {
            throw ApiException::badRequest($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            throw ApiException::badRequest($e->getMessage());
        }

        // Créer la désignation en base de données
        $data = $entity->toArray();
        $data['ancienNom'] = $ancien->nom;
        $data['designeParNom'] = $authUser->nom;

        $designation = AncienDesignation::create($data);

        // Mettre à jour le flag est_de_service sur le compte de l'ancien
        $ancien->update(['est_de_service' => true]);

        Log::info(
            'Ancien désigné de service',
            [
                'ancien_id' => $ancien->id,
                'designation_id' => $designation->id,
                'mois' => $mois,
                'annee' => $annee,
                'jours' => $joursTravail,
                'horaires' => "{$horairesDebut}-{$horairesFin}",
            ]
        );

        return $designation;
    }

    /**
     * Retire la désignation d'un ancien (et met est_de_service=false si plus désigné ce mois).
     *
     * @param User $authUser
     * @param int  $designationId  ID de la désignation
     * @throws ApiException
     */
    public function retirer(User $authUser, int $designationId): void
    {
        $designation = AncienDesignation::find($designationId);

        if (!$designation) {
            throw ApiException::notFound('Désignation');
        }

        // Vérifier que l'authUser peut modifier cette désignation
        if (!$authUser->isAdmin() && $designation->egliseId !== $authUser->churchId) {
            throw ApiException::forbidden('Vous ne pouvez pas retirer cette désignation.');
        }

        $ancienId = $designation->ancienId;

        // Supprimer la désignation
        $designation->delete();

        // Vérifier s'il reste d'autres désignations actives pour cet ancien
        // Une désignation est active si sa date_fin_service est dans le futur
        $autresDesignationsActives = AncienDesignation::where('ancienId', $ancienId)
                                                       ->where('date_fin_service', '>', now())
                                                       ->exists();

        if (!$autresDesignationsActives) {
            // Plus de désignation active → retirer le flag
            User::where('id', $ancienId)
                ->update(['est_de_service' => false]);

            Log::info(
                'Ancien retiré du service',
                ['ancien_id' => $ancienId]
            );
        }
    }

    /**
     * Synchronise les flags est_de_service de tous les anciens d'une église.
     * Utile pour corriger des incohérences.
     *
     * Considère les désignations actives (date_fin_service dans le futur).
     *
     * @param int $egliseId
     */
    public function syncServiceFlags(int $egliseId): void
    {
        // Anciens avec des désignations actives (non expirées)
        $designedIds = AncienDesignation::where('egliseId', $egliseId)
                                        ->where('date_fin_service', '>', now())
                                        ->pluck('ancienId')
                                        ->unique()
                                        ->toArray();

        // Tous les anciens de l'église
        $allAnciens = User::where('role', User::ROLE_ANCIEN)
                          ->where('churchId', $egliseId)
                          ->get();

        foreach ($allAnciens as $ancien) {
            $shouldBeInService = \in_array($ancien->id, $designedIds, true);
            if ($ancien->est_de_service !== $shouldBeInService) {
                $ancien->update(['est_de_service' => $shouldBeInService]);
            }
        }

        Log::info(
            'Sync flags est_de_service complété',
            ['eglise_id' => $egliseId, 'anciens_actifs' => \count($designedIds)]
        );
    }

    /**
     * Vérifie si un ancien peut signer en présentiel maintenant selon sa désignation.
     *
     * @param int $ancienId
     * @param int $egliseId
     * @param int $moisActuel (1-12)
     * @param int $anneeActuelle
     * @return bool
     */
    public function peutSignerEnPresentiel(
        int $ancienId,
        int $egliseId,
        int $moisActuel,
        int $anneeActuelle
    ): bool {
        // Vérifier s'il a une désignation pour ce mois-ci
        $designation = AncienDesignation::where('ancienId', $ancienId)
                                        ->where('egliseId', $egliseId)
                                        ->where('mois', $moisActuel)
                                        ->where('annee', $anneeActuelle)
                                        ->first();

        if (!$designation) {
            return false; // Pas de désignation pour ce mois
        }

        // Créer une entity pour vérifier les disponibilités
        $entity = AncienDesignationEntity::fromData($designation->toArray());

        return $entity->peutSignerMaintenant();
    }

    /**
     * Formatte la disponibilité d'un ancien de manière lisible.
     *
     * @param string|null $joursTravail JSON array [1,2,3,...]
     * @param string|null $horairesDebut HH:MM
     * @param string|null $horairesFin   HH:MM
     * @return array
     */
    private function formatDisponibilite(
        ?string $joursTravail,
        ?string $horairesDebut,
        ?string $horairesFin
    ): array {
        $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        
        $jours = 'Tous les jours';
        if ($joursTravail) {
            $joursCodes = \json_decode($joursTravail, true) ?? [];
            if (!empty($joursCodes)) {
                $joursNoms = \array_map(fn ($code) => $dayNames[$code - 1] ?? null, $joursCodes);
                $joursNoms = \array_filter($joursNoms);
                $jours = \implode(', ', $joursNoms);
            }
        }

        // Convertir les horaires du format HH:MM:SS → HH:MM
        $debut = $horairesDebut ?? '00:00';
        if (\strlen($debut) > 5) {
            $debut = \substr($debut, 0, 5);
        }
        
        $fin = $horairesFin ?? '23:59';
        if (\strlen($fin) > 5) {
            $fin = \substr($fin, 0, 5);
        }
        
        $horaires = "{$debut} - {$fin}";

        return [
            'jours' => $jours,
            'horaires' => $horaires,
            'resume' => "{$jours} ({$horaires})",
        ];
    }
}
