<?php

namespace App\Domain\ValueObjects;

use Carbon\Carbon;

/**
 * ValueObject — Horaires de travail disponibles
 *
 * Représente l'horaire (début-fin) où un ancien est disponible pour signer en présentiel.
 * NULL (both) = disponible 24/7 (00:00-23:59)
 */
class DisponibiliteHoraires
{
    /**
     * @param string|null $debut Format HH:MM (ex: "08:00")
     * @param string|null $fin   Format HH:MM (ex: "18:00")
     */
    private function __construct(
        private readonly ?string $debut,
        private readonly ?string $fin
    ) {
        if ($debut !== null || $fin !== null) {
            $this->validate();
        }
    }

    public static function create(?string $debut, ?string $fin): self
    {
        return new self($debut, $fin);
    }

    public static function disponible24_7(): self
    {
        return new self(null, null);
    }

    private function validate(): void
    {
        if ($this->debut === null && $this->fin === null) {
            return;
        }

        if ($this->debut !== null && !$this->isValidTimeFormat($this->debut)) {
            throw new \InvalidArgumentException(
                "Horaire début invalide: {$this->debut}. Format attendu: HH:MM"
            );
        }

        if ($this->fin !== null && !$this->isValidTimeFormat($this->fin)) {
            throw new \InvalidArgumentException(
                "Horaire fin invalide: {$this->fin}. Format attendu: HH:MM"
            );
        }

        if ($this->debut !== null && $this->fin !== null) {
            $debut = Carbon::createFromFormat('H:i', $this->debut);
            $fin = Carbon::createFromFormat('H:i', $this->fin);

            if ($debut->greaterThanOrEqualTo($fin)) {
                throw new \InvalidArgumentException(
                    "L'horaire début ({$this->debut}) doit être avant l'horaire fin ({$this->fin})."
                );
            }
        }
    }

    private function isValidTimeFormat(string $time): bool
    {
        return (bool) \preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
    }

    /**
     * Vérifie si l'ancien est disponible à une heure spécifique.
     *
     * @param string $heure Format HH:MM
     */
    public function estDisponibleA(string $heure): bool
    {
        if ($this->debut === null && $this->fin === null) {
            return true; // 24/7
        }

        $heureObj = Carbon::createFromFormat('H:i', $heure);
        $debut = $this->debut ? Carbon::createFromFormat('H:i', $this->debut) : Carbon::createFromFormat('H:i', '00:00');
        $fin = $this->fin ? Carbon::createFromFormat('H:i', $this->fin) : Carbon::createFromFormat('H:i', '23:59');

        return $heureObj->greaterThanOrEqualTo($debut) && $heureObj->lessThanOrEqualTo($fin);
    }

    public function getDebut(): ?string
    {
        return $this->debut;
    }

    public function getFin(): ?string
    {
        return $this->fin;
    }

    public function equals(DisponibiliteHoraires $other): bool
    {
        return $this->debut === $other->debut && $this->fin === $other->fin;
    }

    public function __toString(): string
    {
        if ($this->debut === null && $this->fin === null) {
            return 'Disponible 24h/24';
        }

        $debut = $this->debut ?? '00:00';
        $fin = $this->fin ?? '23:59';

        return "Disponible: {$debut} - {$fin}";
    }
}
