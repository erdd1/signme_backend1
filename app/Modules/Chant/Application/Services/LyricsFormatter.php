<?php

namespace App\Modules\Chant\Application\Services;

use Illuminate\Support\Str;

/**
 * Découpe des paroles brutes en strophes et refrains pour l'affichage mobile.
 *
 * - Les blocs sont séparés par une ligne vide.
 * - Une ligne d'étiquette seule ([Refrain], Chorus:, Couplet 2…) type le bloc
 *   qui suit et n'est pas affichée.
 * - Sans étiquette, un bloc répété à l'identique est un refrain.
 */
class LyricsFormatter
{
    private const LABEL = '/^\[?\s*(refrain|chorus|pont|bridge|couplet|strophe|verse|intro|outro|final)\b[^\]\n:]*\]?\s*:?$/iu';

    /**
     * @return array<int, array{type: string, lines: array<int, string>}>
     */
    public function toSections(string $lyrics): array
    {
        $blocks = [];
        foreach (preg_split('/\R{2,}/u', trim($lyrics)) ?: [] as $rawBlock) {
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\R/u', $rawBlock) ?: []),
                fn ($line) => $line !== ''
            ));
            if ($lines === []) {
                continue;
            }

            $label = null;
            if (preg_match(self::LABEL, $lines[0], $m)) {
                $label = Str::lower($m[1]);
                array_shift($lines);
            }
            if ($lines === []) {
                // Étiquette seule : elle s'applique au bloc suivant.
                $blocks[] = ['pending' => $label];
                continue;
            }

            $blocks[] = ['label' => $label, 'lines' => $lines];
        }

        return $this->resolveTypes($blocks);
    }

    /** @return array<int, string> les deux premières lignes, pour l'aperçu en liste */
    public function excerpt(string $lyrics, int $maxLines = 2): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $lyrics) ?: []),
            fn ($line) => $line !== '' && !preg_match(self::LABEL, $line)
        ));

        return array_slice($lines, 0, $maxLines);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array{type: string, lines: array<int, string>}>
     */
    private function resolveTypes(array $blocks): array
    {
        $counts = [];
        foreach ($blocks as $block) {
            if (isset($block['lines'])) {
                $key = $this->key($block['lines']);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $sections = [];
        $pending  = null;
        foreach ($blocks as $block) {
            if (array_key_exists('pending', $block)) {
                $pending = $block['pending'];
                continue;
            }

            $label = $block['label'] ?? $pending;
            $pending = null;
            $isRefrain = in_array($label, ['refrain', 'chorus'], true)
                || ($label === null && $counts[$this->key($block['lines'])] > 1);

            $sections[] = [
                'type'  => $isRefrain ? 'refrain' : 'strophe',
                'lines' => $block['lines'],
            ];
        }

        return $sections;
    }

    /** @param array<int, string> $lines */
    private function key(array $lines): string
    {
        return Str::lower(implode('|', $lines));
    }
}
