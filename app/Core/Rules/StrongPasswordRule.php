<?php

namespace App\Core\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Closure;

/**
 * StrongPasswordRule — Politique de mot de passe forte de l'application SignMe
 *
 * Règles :
 *   ✓ 10 à 30 caractères
 *   ✓ Au moins une majuscule (A-Z)
 *   ✓ Au moins une minuscule (a-z)
 *   ✓ Au moins un chiffre (0-9)
 *   ✓ Au moins un caractère spécial parmi : ! @ # $ % ^ & * ?
 *   ✗ Aucun espace autorisé
 *
 * Exemples valides : SignMe@2026 / MonCompte#123 / SecurePass!2026
 */
class StrongPasswordRule implements ValidationRule
{
    /**
     * Pattern global : regroupe toutes les contraintes dans une seule regex.
     *
     * ^                    début
     * (?=.*[A-Z])          au moins 1 majuscule
     * (?=.*[a-z])          au moins 1 minuscule
     * (?=.*\d)             au moins 1 chiffre
     * (?=.*[!@#$%^&*?])    au moins 1 caractère spécial autorisé
     * \S                   aucun espace (remplace le .{10,30} pour exclure les espaces)
     * {10,30}              entre 10 et 30 caractères non-espace
     * $                    fin
     */
    private const PATTERN = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[!@#$%^&*?])\S{10,30}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('Le mot de passe doit être une chaîne de caractères.');
            return;
        }

        // Vérification rapide via regex globale
        if (!preg_match(self::PATTERN, $value)) {
            // Message d'erreur ciblé selon ce qui manque exactement
            $fail($this->buildMessage($value));
        }
    }

    /**
     * Génère un message d'erreur ciblé sur les critères non respectés.
     */
    private function buildMessage(string $value): string
    {
        $missing = [];

        if (strlen($value) < 10 || strlen($value) > 30) {
            $missing[] = strlen($value) < 10
                ? 'minimum 10 caractères'
                : 'maximum 30 caractères';
        }
        if (preg_match('/\s/', $value)) {
            $missing[] = 'aucun espace autorisé';
        }
        if (!preg_match('/[A-Z]/', $value)) {
            $missing[] = 'une lettre majuscule';
        }
        if (!preg_match('/[a-z]/', $value)) {
            $missing[] = 'une lettre minuscule';
        }
        if (!preg_match('/\d/', $value)) {
            $missing[] = 'un chiffre';
        }
        if (!preg_match('/[!@#$%^&*?]/', $value)) {
            $missing[] = 'un caractère spécial (! @ # $ % ^ & * ?)';
        }

        return 'Le mot de passe doit contenir : ' . implode(', ', $missing) . '.';
    }
}
