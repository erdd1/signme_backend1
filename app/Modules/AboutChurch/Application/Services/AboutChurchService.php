<?php

namespace App\Modules\AboutChurch\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Church;
use App\Models\ChurchAboutInfo;
use App\Models\User;

/**
 * Service AboutChurch — Agrégateur de la page "À propos de mon Église"
 *
 * Combine en une seule lecture : les infos de base de l'église (nom, ville,
 * région, adresse — déjà en base via Church), les infos permanentes du Card
 * principal (ChurchAboutInfoService) et la liste complète des articles
 * (AboutChurchArticleService). Un seul appel réseau côté Flutter au lieu de
 * trois, conformément à l'objectif d'optimisation des requêtes.
 */
class AboutChurchService
{
    public function __construct(
        private readonly ChurchAboutInfoService $infoService,
        private readonly AboutChurchArticleService $articleService,
    ) {}

    /**
     * @return array{church: Church, info: ChurchAboutInfo, articles: \Illuminate\Support\Collection}
     * @throws ApiException si l'église est introuvable
     */
    public function getAggregatedPayload(User $authUser, ?int $churchId = null): array
    {
        $resolvedChurchId = $this->resolveChurchId($authUser, $churchId);

        $church = Church::find($resolvedChurchId);
        if (!$church) {
            throw ApiException::notFound('Église');
        }

        // Église sans infos "À propos" encore saisies : objet vide non
        // persisté plutôt que null, pour que la Resource reste uniforme
        // côté API (toujours un objet 'info', jamais null).
        $info = $this->infoService->getForChurch($resolvedChurchId)
            ?? new ChurchAboutInfo(['activitesMajeures' => []]);

        $articles = $this->articleService->listForChurch($resolvedChurchId);

        return [
            'church'   => $church,
            'info'     => $info,
            'articles' => $articles,
        ];
    }

    private function resolveChurchId(User $authUser, ?int $churchId): int
    {
        if ($authUser->isAdmin()) {
            $id = $churchId ?? $authUser->churchId;
            if (!$id) {
                throw ApiException::badRequest('Veuillez spécifier une église (church_id).');
            }
            return $id;
        }
        return $authUser->churchId;
    }
}
