<?php

namespace App\Modules\User\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\User\Application\Services\UserService;
use App\Modules\User\Http\Requests\CreateUserRequest;
use App\Modules\User\Http\Requests\UpdateUserRequest;
use App\Modules\User\Http\Requests\UploadUserPhotoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserController — Module User (Gestion des membres)
 *
 * GET    /api/v1/users                       → Liste membres [admin, pasteur, secretaire]
 * POST   /api/v1/users                       → Créer membre  [admin, pasteur, secretaire]
 * GET    /api/v1/users/{id}                  → Détail membre [admin, pasteur, secretaire]
 * PUT    /api/v1/users/{id}                  → Modifier      [admin, pasteur, secretaire]
 * DELETE /api/v1/users/{id}                  → Supprimer     [admin]
 * PATCH  /api/v1/users/{id}/toggle-status    → Activer/Désactiver [admin, pasteur]
 * POST   /api/v1/users/{id}/photo            → Upload photo  [admin, pasteur, secretaire]
 */
class UserController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly UserService $userService
    ) {}

    /**
     * GET /api/v1/users
     * Params: role, actif (0|1), search, church_id, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['role', 'actif', 'search', 'church_id']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $users = $this->userService->list($request->user(), $filters, $perPage);

        return $this->paginatedResponse($users, 'Membres récupérés avec succès.');
    }

    /**
     * GET /api/v1/users/can-sign-presentiel
     * Vérifie si l'ancien connecté peut signer en présentiel MAINTENANT
     * (check jours/horaires de sa désignation du mois actuel)
     */
    public function canSignPresentielNow(Request $request): JsonResponse
    {
        $user = $request->user();

        // Seuls les anciens de service peuvent signer en présentiel
        if (!($user->isAncien() && $user->est_de_service)) {
            return $this->successResponse([
                'can_sign' => false,
                'reason' => 'Vous n\'êtes pas un ancien de service',
            ]);
        }

        // Récupérer la désignation du mois actuel
        $now = now();
        $designation = \App\Models\AncienDesignation::where('ancienId', $user->id)
                                                    ->where('egliseId', $user->churchId)
                                                    ->where('mois', $now->month)
                                                    ->where('annee', $now->year)
                                                    ->first();

        if (!$designation) {
            return $this->successResponse([
                'can_sign' => false,
                'reason' => 'Vous n\'êtes pas désigné pour ce mois',
            ]);
        }

        // Vérifier les jours/horaires
        try {
            $entity = \App\Domain\Entities\AncienDesignationEntity::fromData($designation->toArray());
            $can_sign = $entity->peutSignerMaintenant();
            
            if (!$can_sign) {
                return $this->successResponse([
                    'can_sign' => false,
                    'reason' => 'Vous pouvez signer seulement pendant vos horaires de service',
                ]);
            }

            return $this->successResponse([
                'can_sign' => true,
                'jours_travail' => $designation->jours_travail,
                'horaire_debut' => $designation->horaire_debut,
                'horaire_fin' => $designation->horaire_fin,
            ]);
        } catch (\Exception $e) {
            return $this->successResponse([
                'can_sign' => false,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/v1/users
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->user(), $request->validated());

        return $this->createdResponse($user, 'Membre créé avec succès.');
    }

    /**
     * GET /api/v1/users/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->userService->findById($request->user(), $id);

        return $this->successResponse($user, 'Membre récupéré avec succès.');
    }

    /**
     * PUT /api/v1/users/{id}
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = $this->userService->update($request->user(), $id, $request->validated());

        return $this->successResponse($user, 'Membre mis à jour avec succès.');
    }

    /**
     * DELETE /api/v1/users/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->userService->delete($request->user(), $id);

        return $this->noContentResponse('Membre supprimé avec succès.');
    }

    /**
     * PATCH /api/v1/users/{id}/toggle-status
     * Active ou désactive un compte et révoque ses tokens si désactivé.
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $user = $this->userService->toggleStatus($request->user(), $id);
        $status = $user->actif ? 'activé' : 'désactivé';

        return $this->successResponse($user, "Compte {$status} avec succès.");
    }

    /**
     * POST /api/v1/users/{id}/photo
     * Body: multipart/form-data { photo: <file> }
     */
    public function uploadPhoto(UploadUserPhotoRequest $request, int $id): JsonResponse
    {
        $user = $this->userService->uploadPhoto(
            $request->user(),
            $id,
            $request->file('photo')
        );

        return $this->successResponse(
            ['photoUrl' => $user->photoUrl],
            'Photo de profil mise à jour avec succès.'
        );
    }
}
