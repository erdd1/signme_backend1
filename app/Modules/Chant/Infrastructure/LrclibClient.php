<?php

namespace App\Modules\Chant\Infrastructure;

use App\Core\Exceptions\ApiException;
use App\Modules\Chant\Application\Contracts\LyricsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client LRCLIB (https://lrclib.net) — recherche par titre/artiste.
 * LRCLIB demande un User-Agent identifiant l'application.
 */
class LrclibClient implements LyricsProvider
{
    public function search(string $query): array
    {
        $response = $this->get('/api/search', ['q' => $query]);

        return collect($response->json() ?? [])
            ->map(fn ($raw) => $this->normalize($raw))
            ->filter()
            ->values()
            ->all();
    }

    public function find(int $id): ?array
    {
        $response = $this->get("/api/get/{$id}", [], allowNotFound: true);

        return $response->status() === 404 ? null : $this->normalize($response->json());
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('chants.lrclib.base_url'))
            ->timeout(config('chants.lrclib.timeout'))
            ->withUserAgent('SignMe/1.0 (' . config('app.url') . ')')
            ->acceptJson();
    }

    private function get(string $path, array $query, bool $allowNotFound = false): Response
    {
        try {
            $response = $this->http()->get($path, $query);
        } catch (ConnectionException $e) {
            Log::warning('[Chants] LRCLIB injoignable', ['error' => $e->getMessage()]);
            throw $this->unavailable();
        }

        if ($response->failed() && !($allowNotFound && $response->status() === 404)) {
            Log::warning('[Chants] LRCLIB erreur', ['status' => $response->status()]);
            throw $this->unavailable();
        }

        return $response;
    }

    private function unavailable(): ApiException
    {
        return ApiException::serviceUnavailable(
            'Le répertoire de chants est momentanément indisponible. Réessayez dans un instant.'
        );
    }

    /** @return array<string, mixed>|null */
    private function normalize(mixed $raw): ?array
    {
        if (!is_array($raw) || !empty($raw['instrumental'])) {
            return null;
        }

        $lyrics = trim((string) ($raw['plainLyrics'] ?? ''));
        $title  = trim((string) ($raw['trackName'] ?? $raw['name'] ?? ''));
        if ($lyrics === '' || $title === '' || !isset($raw['id'])) {
            return null;
        }

        return [
            'id'       => (int) $raw['id'],
            'title'    => $title,
            'artist'   => trim((string) ($raw['artistName'] ?? '')),
            'album'    => ($album = trim((string) ($raw['albumName'] ?? ''))) !== '' ? $album : null,
            'duration' => isset($raw['duration']) ? (int) round((float) $raw['duration']) : null,
            'lyrics'   => $lyrics,
        ];
    }
}
