<?php

namespace App\Domain\ValueObjects;

use Carbon\Carbon;

/**
 * ValueObject — Période de service (mois/année)
 *
 * Représente une période déterminée (mois et année) pour laquelle un ancien est désigné.
 * Enforce les règles métier: peut seulement être mois actuel ou mois prochain.
 */
class PeriodeService
{
    private function __construct(
        private readonly int $mois,
        private readonly int $annee
    ) {
        $this->validate();
    }

    public static function create(int $mois, int $annee): self
    {
        return new self($mois, $annee);
    }

    /**
     * Crée une PeriodeService pour le mois actuel.
     */
    public static function moisActuel(): self
    {
        $now = now();
        return new self($now->month, $now->year);
    }

    /**
     * Crée une PeriodeService pour le mois prochain.
     */
    public static function moisProchain(): self
    {
        $next = now()->addMonth();
        return new self($next->month, $next->year);
    }

    private function validate(): void
    {
        if ($this->mois < 1 || $this->mois > 12) {
            throw new \InvalidArgumentException("Le mois doit être entre 1 et 12, reçu: {$this->mois}");
        }

        if ($this->annee < 2020 || $this->annee > 2099) {
            throw new \InvalidArgumentException("L'année doit être entre 2020 et 2099, reçue: {$this->annee}");
        }
    }

    /**
     * Vérifie si cette période est valide (mois actuel ou prochain).
     * Utilisé pour valider les désignations pasteur.
     */
    public function estValideForDesignation(): bool
    {
        $moisActuel = self::moisActuel();
        $moisProchain = self::moisProchain();

        return $this->equals($moisActuel) || $this->equals($moisProchain);
    }

    /**
     * Retourne la date du dernier jour du mois à 23:59:59.
     */
    public function dateFinMois(): Carbon
    {
        return Carbon::createFromDate($this->annee, $this->mois, 1)
                     ->endOfMonth()
                     ->setTime(23, 59, 59);
    }

    /**
     * Vérifie si cette période est dans le passé (strictement < mois actuel).
     */
    public function estDansLePasse(): bool
    {
        $maintenant = now();
        $periodeActuelle = Carbon::createFromDate(
            $this->annee,
            $this->mois,
            1
        );

        return $periodeActuelle->endOfMonth()->lessThan($maintenant);
    }

    /**
     * Vérifie si l'utilisateur peut rattraper des signatures ce mois.
     * (Les anciens désignés peuvent signer tous les mois passés)
     */
    public function peutRattrapperSignatures(): bool
    {
        return true; // Tous les mois sont autorisés pour le rattrapage
    }

    public function getMois(): int
    {
        return $this->mois;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function equals(PeriodeService $other): bool
    {
        return $this->mois === $other->mois && $this->annee === $other->annee;
    }

    public function __toString(): string
    {
        $monthName = [
            'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
            'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
        ];

        return $monthName[$this->mois - 1] . ' ' . $this->annee;
    }
}
