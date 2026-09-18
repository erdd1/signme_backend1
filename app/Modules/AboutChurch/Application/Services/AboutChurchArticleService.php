<?php

namespace App\Modules\AboutChurch\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Core\Services\CloudinaryService;
use App\Models\AboutChurchArticle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Service AboutChurchArticle — Articles "À propos de mon Église"
 *
 * Lecture : tous les rôles.
 * Écriture (créer/modifier/supprimer/épingler) : pasteur, secrétaire, admin.
 * Contrairement à Publication, il n'y a PAS de restriction "auteur
 * uniquement" sur la modification/suppression : le contenu "À propos" est
 * une présentation collective de l'église, gérée collaborativement par
 * tout pasteur/secrétaire de la même église (pas une publication
 * personnelle).
 */
class AboutChurchArticleService
{
    public function __construct(
        private readonly CloudinaryService $cloudinaryService,
    ) {}

    /**
     * Liste complète (non paginée) des articles d'une église.
     *
     * Le filtrage par catégorie ("Tous", "Pasteur actuel", "Nos groupes",
     * "Activités majeures") est fait côté client sur cette même liste — le
     * backend renvoie donc toujours l'ensemble, pour permettre un filtrage
     * instantané sans nouvel appel réseau. Les articles épinglés
     * (isPinned/pinnedAt) sont inclus dans le même flux : le client dérive
     * localement les carrousels plutôt que de dupliquer les données sur le
     * réseau.
     */
    public function listForChurch(int $churchId): Collection
    {
        return AboutChurchArticle::where('churchId', $churchId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(User $authUser, array $data, array $imageFiles = []): AboutChurchArticle
    {
        $this->assertCanManage($authUser);
        $churchId = $this->resolveChurchId($authUser, $data['churchId'] ?? null);

        if (count($imageFiles) > AboutChurchArticle::MAX_IMAGES) {
            throw ApiException::badRequest('Maximum ' . AboutChurchArticle::MAX_IMAGES . ' photos par article.');
        }

        $type = $data['type'];
        $statutPasteur = $type === AboutChurchArticle::TYPE_PASTOR
            ? ($data['statutPasteur'] ?? AboutChurchArticle::STATUT_PASTEUR_ACTUEL)
            : null;

        $uuid = (string) Str::uuid();
        $imageUrls = $this->uploadImages($imageFiles, $uuid);

        try {
            return AboutChurchArticle::create([
                'uuid'          => $uuid,
                'churchId'      => $churchId,
                'type'          => $type,
                'titre'         => $data['titre'],
                'contenu'       => $data['contenu'],
                'images'        => $imageUrls,
                'statutPasteur' => $statutPasteur,
                'auteurId'      => $authUser->id,
                'auteurNom'     => $authUser->nom,
            ]);
        } catch (\Throwable $e) {
            // La création DB a échoué après un upload Cloudinary réussi :
            // nettoyer les images déjà envoyées pour ne rien laisser
            // d'orphelin sur Cloudinary.
            foreach ($imageUrls as $url) {
                $this->cloudinaryService->deleteByUrl($url);
            }
            throw $e;
        }
    }

    /**
     * @param array{titre?:?string, contenu?:?string, statutPasteur?:?string, photosTouched?:bool, keepImages?:string[]} $data
     *   `photosTouched` (bool explicite) déclenche la logique de
     *   remplacement du jeu de photos : final = keepImages + $imageFiles
     *   uploadées, dans cet ordre. Si absent/faux ET `$imageFiles` vide, les
     *   photos existantes ne sont pas touchées.
     */
    public function update(User $authUser, string $uuid, array $data, array $imageFiles = []): AboutChurchArticle
    {
        $article = $this->findByUuid($uuid, $authUser);
        $this->assertCanManageArticle($authUser, $article);

        $oldImages = $article->images ?? [];
        $touchesImages = ($data['photosTouched'] ?? false) || !empty($imageFiles);

        $finalImages = null;
        if ($touchesImages) {
            // Ne conserver que les URLs qui appartiennent réellement à
            // l'article — jamais faire confiance à une URL arbitraire
            // envoyée par le client.
            $keep = array_values(array_intersect($data['keepImages'] ?? [], $oldImages));

            if (!empty($imageFiles)) {
                if ((count($keep) + count($imageFiles)) > AboutChurchArticle::MAX_IMAGES) {
                    throw ApiException::badRequest('Maximum ' . AboutChurchArticle::MAX_IMAGES . ' photos par article.');
                }
                $finalImages = [...$keep, ...$this->uploadImages($imageFiles, $article->uuid)];
            } else {
                $finalImages = $keep;
            }
        }

        if (array_key_exists('titre', $data) && $data['titre'] !== null) {
            $article->titre = $data['titre'];
        }
        if (array_key_exists('contenu', $data) && $data['contenu'] !== null) {
            $article->contenu = $data['contenu'];
        }
        if ($article->isPastor() && array_key_exists('statutPasteur', $data) && $data['statutPasteur'] !== null) {
            $article->statutPasteur = $data['statutPasteur'];
        }
        if ($finalImages !== null) {
            $article->images = $finalImages;
        }

        $article->save();

        // Nettoyage Cloudinary des anciennes images retirées UNIQUEMENT
        // après le succès de la sauvegarde, pour ne jamais perdre d'images
        // si l'update échoue en cours de route. Ne supprime que celles qui
        // ne font plus partie du jeu final (jamais celles conservées via
        // keepImages, et jamais les nouvelles fraîchement uploadées — leur
        // public_id est de toute façon distinct, voir
        // CloudinaryService::generateAboutChurchArticlePublicId).
        if ($finalImages !== null) {
            foreach (array_diff($oldImages, $finalImages) as $url) {
                $this->cloudinaryService->deleteByUrl($url);
            }
        }

        return $article->fresh();
    }

    public function delete(User $authUser, string $uuid): void
    {
        $article = $this->findByUuid($uuid, $authUser);
        $this->assertCanManageArticle($authUser, $article);

        foreach ($article->images ?? [] as $url) {
            $this->cloudinaryService->deleteByUrl($url);
        }

        $article->delete();
    }

    /**
     * Épingle un article dans son carrousel de catégorie.
     *
     * Règles (contrôlées ici, jamais uniquement côté Flutter) :
     *  - Photo obligatoire.
     *  - Pour un pasteur : uniquement les articles "actuel" (pas "ancien").
     *  - Quota par catégorie : 5 pasteurs, 5 groupes, 3 activités.
     *  - Type sans quota défini (ex. 'history') : jamais épinglable.
     */
    public function pin(User $authUser, string $uuid): AboutChurchArticle
    {
        $article = $this->findByUuid($uuid, $authUser);
        $this->assertCanManageArticle($authUser, $article);

        if ($article->isPinned) {
            return $article;
        }

        if (!$article->hasAtLeastOneImage()) {
            throw ApiException::badRequest('Une photo est requise pour épingler cet article.');
        }

        if ($article->isPastor() && $article->statutPasteur !== AboutChurchArticle::STATUT_PASTEUR_ACTUEL) {
            throw ApiException::badRequest('Seuls les pasteurs actuels peuvent être épinglés.');
        }

        $maxPinned = AboutChurchArticle::maxPinnedForType($article->type);
        if ($maxPinned === null) {
            throw ApiException::badRequest('Ce type d\'article ne peut pas être épinglé.');
        }

        $currentPinnedCount = AboutChurchArticle::where('churchId', $article->churchId)
            ->where('type', $article->type)
            ->where('isPinned', true)
            ->count();

        if ($currentPinnedCount >= $maxPinned) {
            throw ApiException::badRequest(
                "Maximum {$maxPinned} éléments épinglés pour cette catégorie. Désépinglez-en un avant d'en ajouter un nouveau."
            );
        }

        $article->update([
            'isPinned' => true,
            'pinnedAt' => now(),
        ]);

        return $article->fresh();
    }

    public function unpin(User $authUser, string $uuid): AboutChurchArticle
    {
        $article = $this->findByUuid($uuid, $authUser);
        $this->assertCanManageArticle($authUser, $article);

        $article->update([
            'isPinned' => false,
            'pinnedAt' => null,
        ]);

        return $article->fresh();
    }

    /** @throws ApiException si non trouvé, ou si l'accès inter-église est refusé */
    public function findByUuid(string $uuid, ?User $authUser = null): AboutChurchArticle
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Article');
        }

        $article = AboutChurchArticle::where('uuid', $uuid)->first();

        if (!$article) {
            throw ApiException::notFound('Article');
        }

        if ($authUser && !$authUser->isAdmin() && $article->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Accès refusé à cet article.');
        }

        return $article;
    }

    // ─── Helpers privés ─────────────────────────────────────────────────────

    /** @return string[] URLs Cloudinary, dans l'ordre reçu */
    private function uploadImages(array $imageFiles, string $articleUuid): array
    {
        $urls = [];
        try {
            foreach (array_values($imageFiles) as $index => $file) {
                $publicId = $this->cloudinaryService->generateAboutChurchArticlePublicId($articleUuid, $index);
                $urls[] = $this->cloudinaryService->uploadAboutChurchArticleImage($file, $publicId);
            }
        } catch (\Throwable $e) {
            foreach ($urls as $url) {
                $this->cloudinaryService->deleteByUrl($url);
            }
            throw $e;
        }

        return $urls;
    }

    private function assertCanManage(User $authUser): void
    {
        if (!$authUser->isAdmin() && !$authUser->isPasteur() && !$authUser->isSecretaire()) {
            throw ApiException::forbidden('Seuls le pasteur et le secrétaire peuvent gérer cette page.');
        }
    }

    private function assertCanManageArticle(User $authUser, AboutChurchArticle $article): void
    {
        $this->assertCanManage($authUser);

        if (!$authUser->isAdmin() && $article->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Cet article n\'appartient pas à votre église.');
        }
    }

    private function resolveChurchId(User $authUser, ?int $churchId): int
    {
        if ($authUser->isAdmin()) {
            $id = $churchId ?? $authUser->churchId;
            if (!$id) {
                throw ApiException::badRequest('Veuillez spécifier une église (churchId).');
            }
            return $id;
        }
        return $authUser->churchId;
    }
}
