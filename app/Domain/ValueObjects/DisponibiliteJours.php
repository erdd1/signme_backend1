<?php

namespace App\Domain\ValueObjects;

/**
 * ValueObject — Jours de travail disponibles
 *
 * Représente les jours de la semaine (1-7) où un ancien est disponible pour signer.
 * NULL = disponible tous les jours (24/7)
 *
 * Jours: 1=Lundi, 2=Mardi, 3=Mercredi, 4=Jeudi, 5=Vendredi, 6=Samedi, 7=Dimanche
 */
class DisponibiliteJours
{
    /**
     * @param array<int>|null $jours [1,2,3,4,5,6,7] ou null pour 24/7
     */
    private function __construct(private readonly ?array $jours)
    {
        if ($this->jours !== null) {
            $this->validate();
        }
    }

    public static function create(?array $jours): self
    {
        return new self($jours);
    }

    public static function disponible24_7(): self
    {
        return new self(null);
    }

    private function validate(): void
    {
        if (empty($this->jours)) {
            throw new \InvalidArgumentException('Les jours ne peuvent pas être vides. Utilisez null pour 24/7.');
        }

        foreach ($this->jours as $jour) {
            if (!\is_int($jour) || $jour < 1 || $jour > 7) {
                throw new \InvalidArgumentException(
                    "Jour invalide: {$jour}. Les jours doivent être entre 1 (lundi) et 7 (dimanche)."
                );
            }
        }

        if (\count($this->jours) !== \count(\array_unique($this->jours))) {
            throw new \InvalidArgumentException('Les jours en doublon ne sont pas autorisés.');
        }
    }

    /**
     * Vérifie si l'ancien est disponible un jour spécifique.
     *
     * @param int $dayOfWeek 1-7 (lundi-dimanche)
     */
    public function estDisponibleLe(int $dayOfWeek): bool
    {
        if ($this->jours === null) {
            return true; // 24/7
        }

        return \in_array($dayOfWeek, $this->jours, true);
    }

    public function toArray(): ?array
    {
        return $this->jours;
    }

    public function json(): string
    {
        return \json_encode($this->jours, \JSON_THROW_ON_ERROR);
    }

    public function equals(DisponibiliteJours $other): bool
    {
        return $this->jours === $other->jours;
    }

    public function __toString(): string
    {
        if ($this->jours === null) {
            return 'Disponible 24/7 tous les jours';
        }

        $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $jours = \array_map(fn ($day) => $dayNames[$day - 1], $this->jours);

        return 'Disponible: ' . \implode(', ', $jours);
    }
}
