<?php

namespace App\Modules\Chant\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Modules\Chant\Application\Contracts\LyricsFragmentResolver;
use App\Modules\Chant\Application\Contracts\LyricsProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Service Chant — recherche et lecture de chants.
 *
 * Aucune persistance : copies temporaires en cache uniquement (voir
 * config/chants.php). La recherche se fait par titre/artiste ; si rien n'est
 * trouvé, le texte saisi est traité comme un extrait de paroles dont on
 * retrouve le titre avant de relancer la recherche.
 */
class ChantService
{
    public function __construct(
        private readonly LyricsProvider $provider,
        private readonly LyricsFragmentResolver $resolver,
        private readonly LyricsFormatter $formatter,
    ) {}

    /**
     * @return array<int, array<string, mixed>> chants regroupés par titre,
     *         chacun avec la liste de ses versions
     *
     * @throws ApiException
     */
    public function search(string $query): array
    {
        $this->ensureEnabled();

        $query = $this->normalizeQuery($query);

        return Cache::remember(
            'chants:search:' . md5(Str::lower($query)),
            config('chants.cache_ttl'),
            fn () => $this->runSearch($query)
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function show(int $id): array
    {
        $this->ensureEnabled();

        $track = Cache::get($this->trackKey($id));
        if ($track === null) {
            $track = $this->provider->find($id)
                ?? throw ApiException::notFound('Chant');
            $track['versions'] = [];
            Cache::put($this->trackKey($id), $track, config('chants.cache_ttl'));
        }

        return [
            'id'       => $track['id'],
            'title'    => $track['title'],
            'artist'   => $track['artist'],
            'album'    => $track['album'],
            'duration' => $track['duration'],
            'sections' => $this->formatter->toSections($track['lyrics']),
            'versions' => $track['versions'],
            'source'   => config('chants.attribution'),
        ];
    }

    private function ensureEnabled(): void
    {
        if (!config('chants.enabled')) {
            throw ApiException::serviceUnavailable('Le répertoire de chants est momentanément indisponible.');
        }
    }

    private function normalizeQuery(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    }

    /** @return array<int, array<string, mixed>> */
    private function runSearch(string $query): array
    {
        $tracks = $this->provider->search($query);

        if ($tracks === []) {
            foreach ($this->resolver->resolve($query) as $candidate) {
                $found = $this->provider->search(trim($candidate['title'] . ' ' . $candidate['artist']));
                $tracks = array_merge($tracks, $found);
            }
        }

        return $this->group($tracks);
    }

    /**
     * Regroupe les pistes de même titre : un chant = plusieurs versions.
     *
     * @param array<int, array<string, mixed>> $tracks
     * @return array<int, array<string, mixed>>
     */
    private function group(array $tracks): array
    {
        $groups = [];
        foreach ($tracks as $track) {
            $key = Str::of($track['title'])->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
            $groups[$key][$track['id']] = $track;
        }

        $chants = [];
        foreach ($groups as $versionsById) {
            $versions = array_map(fn ($t) => [
                'id'       => $t['id'],
                'artist'   => $t['artist'],
                'album'    => $t['album'],
                'duration' => $t['duration'],
            ], array_values($versionsById));

            foreach ($versionsById as $track) {
                Cache::put(
                    $this->trackKey($track['id']),
                    $track + ['versions' => $versions],
                    config('chants.cache_ttl')
                );
            }

            $primary = reset($versionsById);
            $chants[] = [
                'id'       => $primary['id'],
                'title'    => $primary['title'],
                'artist'   => $primary['artist'],
                'excerpt'  => $this->formatter->excerpt($primary['lyrics']),
                'versions' => $versions,
            ];

            if (count($chants) >= config('chants.max_results')) {
                break;
            }
        }

        return $chants;
    }

    private function trackKey(int $id): string
    {
        return "chants:track:{$id}";
    }
}
