<?php

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Exception API personnalisée
 *
 * Lancée pour toutes les erreurs métier applicatives.
 * Encapsule un code HTTP, un message lisible et des données supplémentaires optionnelles.
 *
 * @package App\Core\Exceptions
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Une erreur est survenue',
        private readonly int $statusCode = 400,
        private readonly mixed $errors = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors(): mixed
    {
        return $this->errors;
    }

    // === Méthodes factory pour les cas d'usage courants ===

    public static function notFound(string $resource = 'Ressource'): self
    {
        return new self("{$resource} non trouvé(e)", 404);
    }

    public static function forbidden(string $message = 'Accès refusé'): self
    {
        return new self($message, 403);
    }

    public static function unauthorized(string $message = 'Non authentifié', mixed $errors = null): self
    {
        return new self($message, 401, $errors);
    }

    public static function badRequest(string $message, mixed $errors = null): self
    {
        return new self($message, 400, $errors);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function unprocessable(string $message, mixed $errors = null): self
    {
        return new self($message, 422, $errors);
    }

    public static function tooManyRequests(string $message = 'Trop de requêtes'): self
    {
        return new self($message, 429);
    }

    public static function serviceUnavailable(string $message = 'Service temporairement indisponible'): self
    {
        return new self($message, 503);
    }
}
