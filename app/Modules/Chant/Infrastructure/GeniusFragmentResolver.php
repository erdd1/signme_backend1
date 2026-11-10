<?php

namespace App\Modules\Chant\Infrastructure;

use App\Modules\Chant\Application\Contracts\LyricsFragmentResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retrouve un chant à partir d'un extrait de paroles via la recherche de
 * Genius (titres et artistes uniquement — aucun texte de Genius n'est utilisé).
 */
class GeniusFragmentResolver implements LyricsFragmentResolver
{
    private const MAX_CANDIDATES = 3;

    public function resolve(string $fragment): array
    {
        $token = config('chants.genius.token');
        if (!$token) {
            return [];
        }

        try {
            $response = Http::baseUrl(config('chants.genius.base_url'))
                ->timeout(config('chants.genius.timeout'))
                ->withToken($token)
                ->acceptJson()
                ->get('/search', ['q' => $fragment]);
        } catch (Throwable $e) {
            Log::warning('[Chants] Genius injoignable', ['error' => $e->getMessage()]);
            return [];
        }

        if ($response->failed()) {
            Log::warning('[Chants] Genius erreur', ['status' => $response->status()]);
            return [];
        }

        return collect($response->json('response.hits') ?? [])
            ->filter(fn ($hit) => ($hit['type'] ?? null) === 'song')
            ->map(fn ($hit) => [
                'title'  => trim((string) ($hit['result']['title'] ?? '')),
                'artist' => trim((string) ($hit['result']['primary_artist']['name'] ?? '')),
            ])
            ->filter(fn ($c) => $c['title'] !== '')
            ->take(self::MAX_CANDIDATES)
            ->values()
            ->all();
    }
}
