<?php

namespace App\Modules\Publication\Application\Services;

use App\Models\Publication;
use App\Models\User;
use App\Core\Exceptions\ApiException;
use App\Modules\Badge\Application\Services\BadgeService;
use App\Modules\Push\Application\Jobs\SendNewContentPushJob;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Service Publication - Annonces et meditations de l'eglise
 *
 * Droits :
 * - Lecture : tous les roles (filtree par churchId)
 * - Creation/modification/suppression : pasteur, secretaire, admin
 */
class PublicationService
{
    public function __construct(
        private readonly BadgeService $badgeService
    ) {}
    /**
     * Liste les publications d'une ou toutes les eglises (fil d'actualite).
     *
     * @param User  $authUser
     * @param array $filters  { type, church_id, per_page }
     */
    public function list(User $authUser, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $churchKey = $authUser->isAdmin()
            ? (string) ($filters['church_id'] ?? 'all')
            : (string) $authUser->churchId;
        $type = (string) ($filters['type'] ?? 'all');
        $page = max(1, (int) request()->get('page', 1));
        $since = $filters['updated_after'] ?? null;

        $buildQuery = function () use ($authUser, $filters, $since) {
            $query = Publication::query()
                ->orderByDesc('updated_at')
                ->orderByDesc('date')
                ->orderByDesc('created_at');

            if ($since) {
                $query->withTrashed()
                    ->where(function ($q) use ($since) {
                        $q->where('updated_at', '>=', $since)
                          ->orWhere('deleted_at', '>=', $since);
                    });
            }

            if (!$authUser->isAdmin()) {
                $query->where('churchId', $authUser->churchId);
            } elseif (!empty($filters['church_id'])) {
                $query->where('churchId', $filters['church_id']);
            }

            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            return $query;
        };

        // Les deltas doivent toujours etre frais pour que le cache mobile puisse
        // rattraper les modifications et les suppressions apres une coupure.
        if ($since) {
            return $buildQuery()->paginate($perPage);
        }

        $version = (int) Cache::get("pub:version:{$churchKey}", 1);
        $cacheKey = "pub:list:{$churchKey}:{$version}:{$type}:{$perPage}:{$page}";

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($buildQuery, $perPage) {
            return $buildQuery()->paginate($perPage);
        });
    }

    private function bumpPublicationCache(int $churchId): void
    {
        // La vue admin non filtrée agrège toutes les églises (churchKey 'all') —
        // sans ça, une création/modif/suppression ne rafraîchit que le cache de
        // l'église concernée et la liste admin globale reste périmée jusqu'à
        // expiration naturelle du cache (3 min).
        foreach ([(string) $churchId, 'all'] as $churchKey) {
            $key = "pub:version:{$churchKey}";
            Cache::add($key, 1, now()->addDays(7));
            Cache::increment($key);
        }
    }

    /**
     * Cree une nouvelle publication.
     *
     * @throws ApiException
     */
    public function create(User $authUser, array $data): Publication
    {
        $churchId = $authUser->isAdmin()
            ? ($data['churchId'] ?? $authUser->churchId)
            : $authUser->churchId;

        // La reference biblique est obligatoire pour les meditations
        if ($data['type'] === Publication::TYPE_MEDITATION && empty($data['referenceBiblique'])) {
            throw ApiException::badRequest('La reference biblique est obligatoire pour une meditation.');
        }

        $publication = Publication::create([
            'churchId'          => $churchId,
            'type'              => $data['type'],
            'titre'             => $data['titre'],
            'contenu'           => $data['contenu'],
            'referenceBiblique' => $data['referenceBiblique'] ?? null,
            'auteurId'          => $authUser->id,
            'auteurNom'         => $authUser->nom,
            'auteurPhotoUrl'    => $authUser->photoUrl,
            'date'              => now(),
        ]);

        // L'auteur ne doit pas voir son propre badge - marquer comme lu des la creation
        $this->badgeService->markPublicationReadByAuthor(
            (int) $publication->id,
            (int) $authUser->id
        );

        $this->bumpPublicationCache((int) $churchId);

        $titrePush = $data['type'] === Publication::TYPE_MEDITATION
            ? 'Nouvelle méditation'
            : 'Nouvelle annonce';

        SendNewContentPushJob::dispatch(
            (int) $churchId,
            (int) $authUser->id,
            $titrePush,
            $publication->titre,
            ['type' => 'publication', 'id' => (string) $publication->id],
        );

        return $publication;
    }

    /**
     * Retourne une publication par ID.
     *
     * @throws ApiException
     */
    public function findById(User $authUser, int $id): Publication
    {
        $pub = Publication::find($id);

        if (!$pub) {
            throw ApiException::notFound('Publication');
        }

        // Verifier que la publication appartient a la bonne eglise
        if (!$authUser->isAdmin() && $pub->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Acces refuse a cette publication.');
        }

        return $pub;
    }

    /**
     * Met a jour une publication.
     *
     * @throws ApiException
     */
    public function update(User $authUser, int $id, array $data): Publication
    {
        $pub = $this->findById($authUser, $id);

        // Seul l'auteur ou un admin peut modifier
        if (!$authUser->isAdmin() && $pub->auteurId !== $authUser->id) {
            throw ApiException::forbidden('Vous ne pouvez modifier que vos propres publications.');
        }

        $pub->update(array_filter([
            'titre'             => $data['titre']             ?? null,
            'contenu'           => $data['contenu']           ?? null,
            'referenceBiblique' => $data['referenceBiblique'] ?? null,
        ], fn($v) => !is_null($v)));

        $fresh = $pub->fresh();
        $this->bumpPublicationCache((int) $pub->churchId);

        return $fresh;
    }

    /**
     * Supprime une publication.
     *
     * @throws ApiException
     */
    public function delete(User $authUser, int $id): void
    {
        $pub = $this->findById($authUser, $id);

        // Seul l'auteur, un pasteur de l'eglise ou un admin peut supprimer
        $canDelete = $authUser->isAdmin()
            || $pub->auteurId === $authUser->id
            || $authUser->isPasteur();

        if (!$canDelete) {
            throw ApiException::forbidden('Vous ne pouvez pas supprimer cette publication.');
        }

        $churchId = (int) $pub->churchId;
        $pub->delete();
        $this->bumpPublicationCache($churchId);
    }
}