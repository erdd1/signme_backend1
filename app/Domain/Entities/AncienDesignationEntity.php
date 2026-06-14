<?php

namespace App\Domain\Entities;

use App\Domain\ValueObjects\DisponibiliteJours;
use App\Domain\ValueObjects\DisponibiliteHoraires;
use App\Domain\ValueObjects\PeriodeService;

/**
 * Entity DDD — AncienDesignation
 *
 * Encapsule la logique métier pour la désignation d'un ancien de service.
 * Utilise des ValueObjects pour représenter les concepts métier.
 */
class AncienDesignationEntity
{
    private int $id;

    public function __construct(
        private int $ancienId,
        private int $egliseId,
        private PeriodeService $periode,
        private DisponibiliteJours $jours,
        private DisponibiliteHoraires $horaires,
        private int $designePar,
    ) {}

    public static function creerNouvelle(
        int $ancienId,
        int $egliseId,
        PeriodeService $periode,
        ?array $joursTravail,
        ?string $horairesDebut,
        ?string $horairesFin,
        int $designePar
    ): self {
        // Validation métier: la période doit être valide
        if (!$periode->estValideForDesignation()) {
            throw new \DomainException(
                "La période {$periode} n'est pas valide. "
                . "Seuls le mois actuel et le mois prochain sont autorisés."
            );
        }

        $jours = DisponibiliteJours::create($joursTravail);
        $horaires = DisponibiliteHoraires::create($horairesDebut, $horairesFin);

        return new self(
            $ancienId,
            $egliseId,
            $periode,
            $jours,
            $horaires,
            $designePar
        );
    }

    /**
     * Hydrate une entity à partir de données de base de données.
     */
    public static function fromData(array $data): self
    {
        // Convertir les horaires du format TIME (HH:MM:SS) au format HH:MM
        $horairesDebut = null;
        if (!empty($data['horaire_debut'])) {
            $horairesDebut = substr($data['horaire_debut'], 0, 5); // "HH:MM:SS" → "HH:MM"
        }
        
        $horairesFin = null;
        if (!empty($data['horaire_fin'])) {
            $horairesFin = substr($data['horaire_fin'], 0, 5); // "HH:MM:SS" → "HH:MM"
        }

        $entity = new self(
            (int) $data['ancienId'],
            (int) $data['egliseId'],
            PeriodeService::create((int) $data['mois'], (int) $data['annee']),
            DisponibiliteJours::create(
                isset($data['jours_travail']) ? json_decode($data['jours_travail'], true) : null
            ),
            DisponibiliteHoraires::create(
                $horairesDebut,
                $horairesFin
            ),
            (int) $data['designePar']
        );

        if (isset($data['id'])) {
            $entity->id = (int) $data['id'];
        }

        return $entity;
    }

    /**
     * Vérifie si l'ancien peut signer en présentiel maintenant.
     * Vérifie: jour de la semaine + horaire actuel.
     */
    public function peutSignerMaintenant(): bool
    {
        $maintenant = now();
        $dayOfWeek = $maintenant->dayOfWeek === 0 ? 7 : $maintenant->dayOfWeek; // 1-7 (Lundi-Dimanche)
        $heure = $maintenant->format('H:i');

        return $this->jours->estDisponibleLe($dayOfWeek)
            && $this->horaires->estDisponibleA($heure);
    }

    /**
     * Vérifie si l'ancien peut signer à un jour/heure spécifiques.
     */
    public function peutSignerLe(int $dayOfWeek, string $heure): bool
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new \InvalidArgumentException("Jour invalide: {$dayOfWeek}");
        }

        return $this->jours->estDisponibleLe($dayOfWeek)
            && $this->horaires->estDisponibleA($heure);
    }

    /**
     * Retourne la date de fin de service (dernier jour du mois à 23h59).
     */
    public function dateFinService(): \DateTime
    {
        return $this->periode->dateFinMois();
    }

    /**
     * Vérifie si cette désignation est expirée (passé la date de fin de service).
     */
    public function estExpiree(): bool
    {
        return now()->greaterThan($this->dateFinService());
    }

    // Getters

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getAncienId(): int
    {
        return $this->ancienId;
    }

    public function getEgliseId(): int
    {
        return $this->egliseId;
    }

    public function getPeriode(): PeriodeService
    {
        return $this->periode;
    }

    public function getJours(): DisponibiliteJours
    {
        return $this->jours;
    }

    public function getHoraires(): DisponibiliteHoraires
    {
        return $this->horaires;
    }

    public function getDesignePar(): int
    {
        return $this->designePar;
    }

    /**
     * Retourne les données pour l'insertion en base de données.
     */
    public function toArray(): array
    {
        return [
            'ancienId'        => $this->ancienId,
            'egliseId'        => $this->egliseId,
            'mois'            => $this->periode->getMois(),
            'annee'           => $this->periode->getAnnee(),
            'jours_travail'   => $this->jours->toArray() === null ? null : $this->jours->json(),
            'horaire_debut'   => $this->horaires->getDebut(),
            'horaire_fin'     => $this->horaires->getFin(),
            'designePar'      => $this->designePar,
            'date_fin_service' => $this->dateFinService()->format('Y-m-d H:i:s'),
        ];
    }

    public function __toString(): string
    {
        return "AncienDesignation: {$this->ancienId} - Période: {$this->periode}";
    }
}
