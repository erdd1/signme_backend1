<?php

namespace App\Modules\Chant\Application\Contracts;

use App\Core\Exceptions\ApiException;

/**
 * Source de paroles. Permet de remplacer LRCLIB par une API sous licence
 * sans toucher au service ni à l'application mobile.
 *
 * Une « piste » est un tableau :
 * ['id' => int, 'title' => string, 'artist' => string, 'album' => ?string,
 *  'duration' => ?int, 'lyrics' => string]
 */
interface LyricsProvider
{
    /**
     * @return array<int, array<string, mixed>> pistes avec paroles, sans instrumentales
     *
     * @throws ApiException si la source est indisponible
     */
    public function search(string $query): array;

    /**
     * @return array<string, mixed>|null null si la piste n'existe pas (ou n'a pas de paroles)
     *
     * @throws ApiException si la source est indisponible
     */
    public function find(int $id): ?array;
}
