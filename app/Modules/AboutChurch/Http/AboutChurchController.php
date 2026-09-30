<?php

namespace App\Modules\AboutChurch\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\AboutChurch\Application\Services\AboutChurchArticleService;
use App\Modules\AboutChurch\Application\Services\AboutChurchService;
use App\Modules\AboutChurch\Application\Services\ChurchAboutInfoService;
use App\Modules\AboutChurch\Http\Requests\CreateAboutChurchArticleRequest;
use App\Modules\AboutChurch\Http\Requests\UpdateAboutChurchArticleRequest;
use App\Modules\AboutChurch\Http\Requests\UpdateChurchAboutInfoRequest;
use App\Modules\AboutChurch\Http\Resources\AboutChurchArticleResource;
use App\Modules\AboutChurch\Http\Resources\ChurchAboutInfoResource;
use App\Modules\AboutChurch\Http\Resources\ChurchSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AboutChurchController — Module "À propos de mon Église"
 *
 * GET    /api/v1/about-church                       → Payload agrégé (église + infos + articles) [tous]
 * PUT    /api/v1/about-church/info                   → Maj infos permanentes [pasteur, secretaire, admin]
 * POST   /api/v1/about-church/articles               → Créer un article (multipart, images[] incluses) [pasteur, secretaire, admin]
 * PUT    /api/v1/about-church/articles/{uuid}        → Modifier [pasteur, secretaire, admin]
 * DELETE /api/v1/about-church/articles/{uuid}        → Supprimer [pasteur, secretaire, admin]
 * POST   /api/v1/about-church/articles/{uuid}/pin    → Épingler [pasteur, secretaire, admin]
 * POST   /api/v1/about-church/articles/{uuid}/unpin  → Désépingler [pasteur, secretaire, admin]
 */
class AboutChurchController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly AboutChurchService $aboutChurchService,
        private readonly ChurchAboutInfoService $infoService,
        private readonly AboutChurchArticleService $articleService,
    ) {}

    /**
     * GET /api/v1/about-church
     * Query: church_id (admin uniquement)
     */
    public function show(Request $request): JsonResponse
    {
        $churchId = $request->get('church_id') ? (int) $request->get('church_id') : null;
        $payload = $this->aboutChurchService->getAggregatedPayload($request->user(), $churchId);

        return $this->successResponse([
            'church'   => new ChurchSummaryResource($payload['church']),
            'info'     => new ChurchAboutInfoResource($payload['info']),
            'articles' => AboutChurchArticleResource::collection($payload['articles']),
        ], 'Page "À propos de mon Église" récupérée avec succès.');
    }

    /**
     * PUT /api/v1/about-church/info
     */
    public function updateInfo(UpdateChurchAboutInfoRequest $request): JsonResponse
    {
        $info = $this->infoService->update($request->user(), $request->validated());

        return $this->successResponse(
            new ChurchAboutInfoResource($info),
            'Informations mises à jour avec succès.'
        );
    }

    /**
     * POST /api/v1/about-church/articles
     * Body: multipart/form-data { type, titre, contenu, statutPasteur?, images[]? }
     */
    public function storeArticle(CreateAboutChurchArticleRequest $request): JsonResponse
    {
        $article = $this->articleService->create(
            $request->user(),
            $request->validated(),
            $request->file('images') ?? []
        );

        return $this->createdResponse(
            new AboutChurchArticleResource($article),
            'Article créé avec succès.'
        );
    }

    /**
     * PUT /api/v1/about-church/articles/{uuid}
     */
    public function updateArticle(UpdateAboutChurchArticleRequest $request, string $uuid): JsonResponse
    {
        $data = $request->validated();
        // `boolean()` cast explicite — `validated()` renverrait la chaîne
        // brute ("1"/"true") plutôt qu'un vrai bool PHP.
        $data['photosTouched'] = $request->boolean('photosTouched');

        $article = $this->articleService->update(
            $request->user(),
            $uuid,
            $data,
            $request->file('images') ?? []
        );

        return $this->successResponse(
            new AboutChurchArticleResource($article),
            'Article mis à jour avec succès.'
        );
    }

    /**
     * DELETE /api/v1/about-church/articles/{uuid}
     */
    public function destroyArticle(Request $request, string $uuid): JsonResponse
    {
        $this->articleService->delete($request->user(), $uuid);

        return $this->noContentResponse('Article supprimé avec succès.');
    }

    /**
     * POST /api/v1/about-church/articles/{uuid}/pin
     */
    public function pinArticle(Request $request, string $uuid): JsonResponse
    {
        $article = $this->articleService->pin($request->user(), $uuid);

        return $this->successResponse(
            new AboutChurchArticleResource($article),
            'Article épinglé avec succès.'
        );
    }

    /**
     * POST /api/v1/about-church/articles/{uuid}/unpin
     */
    public function unpinArticle(Request $request, string $uuid): JsonResponse
    {
        $article = $this->articleService->unpin($request->user(), $uuid);

        return $this->successResponse(
            new AboutChurchArticleResource($article),
            'Article désépinglé avec succès.'
        );
    }
}
