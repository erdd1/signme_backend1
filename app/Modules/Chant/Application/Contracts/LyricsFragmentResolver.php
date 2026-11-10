<?php

namespace App\Modules\Chant\Application\Contracts;

/**
 * Retrouve titre et artiste d'un chant à partir d'un extrait de paroles.
 * Service de confort : en cas d'échec ou d'absence de configuration, renvoie
 * simplement une liste vide (jamais d'exception).
 */
interface LyricsFragmentResolver
{
    /**
     * @return array<int, array{title: string, artist: string}>
     */
    public function resolve(string $fragment): array;
}
