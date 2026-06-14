<?php

namespace App\Modules\Publication\Application\Services;

use App\Models\Publication;
use App\Models\User;
use App\Core\Exceptions\ApiException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Service Publication — Annonces et méditations de l'église
 *
 * Droits :
 * - Lecture : tous les rôles (filtrée par churchId)
 * - Création/modification/suppression : pasteur, secrétaire, admin
 */
class PublicationService
{
    /**
     * Liste les publications d'une ou toutes les églises (fil d'actualité).
     *
     * @param User  $authUser
     * @param array $filters  { type, church_id, per_page }
     */
    public function list(User $authUser, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $churchKey = $authUser->isAdmin()
            ? (string) ($filters['church_id'] ?? 'all')
            : (string) $authUser->churchId;
        $type      = (string) ($filters['type'] ?? 'all');
        $page      = max(1, (int) request()->get('page', 1));
        $version   = (int) Cache::get("pub:version:{$churchKey}", 1);

        $cacheKey = "pub:list:{$churchKey}:{$version}:{$type}:{$perPage}:{$page}";

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($authUser, $filters, $perPage) {
            $query = Publication::orderByDesc('date')->orderByDesc('created_at');

            if (!$authUser->isAdmin()) {
                $query->where('churchId', $authUser->churchId);
            } elseif (!empty($filters['church_id'])) {
                $query->where('churchId', $filters['church_id']);
            }

            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            return $query->paginate($perPage);
        });
    }

    private function bumpPublicationCache(int $churchId): void
    {
        $key = "pub:version:{$churchId}";
        Cache::add($key, 1, now()->addDays(7));
        Cache::increment($key);
    }

    /**
     * Crée une nouvelle publication.
     *
     * @throws ApiException
     */
    public function create(User $authUser, array $data): Publication
    {
        $churchId = $authUser->isAdmin()
            ? ($data['churchId'] ?? $authUser->churchId)
            : $authUser->churchId;

        // La référence biblique est obligatoire pour les méditations
        if ($data['type'] === Publication::TYPE_MEDITATION && empty($data['referenceBiblique'])) {
            throw ApiException::badRequest('La référence biblique est obligatoire pour une méditation.');
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

        $this->bumpPublicationCache((int) $churchId);

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

        // Vérifier que la publication appartient à la bonne église
        if (!$authUser->isAdmin() && $pub->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Accès refusé à cette publication.');
        }

        return $pub;
    }

    /**
     * Met à jour une publication.
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

        // Seul l'auteur, un pasteur de l'église ou un admin peut supprimer
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
