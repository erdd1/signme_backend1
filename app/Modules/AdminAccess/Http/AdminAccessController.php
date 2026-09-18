<?php

namespace App\Modules\AdminAccess\Http;

use App\Core\Exceptions\ApiException;
use App\Core\Services\AdminResourceRegistry;
use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\User;
use App\Modules\User\Application\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AdminAccessController — Gestion des mini-admins (SUPER ADMIN ONLY)
 *
 * GET  /api/admin/admin-access/resources      → Liste des modules accordables
 * GET  /api/admin/admin-access                → Liste des administrateurs
 * POST /api/admin/admin-access                → Crée un mini-admin
 * GET  /api/admin/admin-access/{id}           → Détail d'un administrateur + ses permissions
 * PUT  /api/admin/admin-access/{id}/permissions → Remplace la grille de permissions d'un mini-admin
 */
class AdminAccessController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly UserService $userService,
    ) {
    }

    public function resources(): JsonResponse
    {
        return $this->successResponse(AdminResourceRegistry::toArray());
    }

    public function index(): JsonResponse
    {
        $admins = User::where('role', User::ROLE_ADMIN)
            ->with('permissions')
            ->orderBy('nom')
            ->get();

        return $this->successResponse($admins->map($this->formatAdmin(...))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $admin = $this->userService->create($request->user(), [
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => User::ROLE_ADMIN,
        ]);

        return $this->createdResponse(
            $this->formatAdmin($admin->fresh('permissions')),
            'Administrateur créé avec succès.'
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->formatAdmin($this->findAdmin($id)));
    }

    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $admin = $this->findAdmin($id);

        if ($admin->isSuperAdmin()) {
            throw ApiException::conflict('Impossible de modifier les permissions d\'un super administrateur.');
        }

        $resources = AdminResourceRegistry::keys();

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*.resource' => ['required', 'string', Rule::in($resources)],
            'permissions.*.can_view' => ['boolean'],
            'permissions.*.can_create' => ['boolean'],
            'permissions.*.can_update' => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
        ]);

        // Remplacement complet : toute ligne omise repasse implicitement à
        // "tout refusé" — évite qu'une permission révoquée dans la grille
        // envoyée reste active en base parce qu'elle n'a pas été touchée.
        AdminPermission::where('user_id', $admin->id)->delete();

        foreach ($validated['permissions'] as $permission) {
            $hasAnyGrant = ($permission['can_view'] ?? false)
                || ($permission['can_create'] ?? false)
                || ($permission['can_update'] ?? false)
                || ($permission['can_delete'] ?? false);

            if (!$hasAnyGrant) {
                continue;
            }

            AdminPermission::create([
                'user_id' => $admin->id,
                'resource' => $permission['resource'],
                'can_view' => $permission['can_view'] ?? false,
                'can_create' => $permission['can_create'] ?? false,
                'can_update' => $permission['can_update'] ?? false,
                'can_delete' => $permission['can_delete'] ?? false,
            ]);
        }

        return $this->successResponse(
            $this->formatAdmin($admin->fresh('permissions')),
            'Permissions mises à jour avec succès.'
        );
    }

    private function findAdmin(int $id): User
    {
        $admin = User::where('role', User::ROLE_ADMIN)->with('permissions')->find($id);

        if (!$admin) {
            throw ApiException::notFound('Administrateur');
        }

        return $admin;
    }

    private function formatAdmin(User $admin): array
    {
        return [
            'id' => $admin->id,
            'nom' => $admin->nom,
            'email' => $admin->email,
            'actif' => $admin->actif,
            'isSuperAdmin' => $admin->is_super_admin,
            'createdAt' => $admin->created_at?->toISOString(),
            'permissions' => $admin->permissions->map(fn (AdminPermission $p) => [
                'resource' => $p->resource,
                'can_view' => $p->can_view,
                'can_create' => $p->can_create,
                'can_update' => $p->can_update,
                'can_delete' => $p->can_delete,
            ])->values(),
        ];
    }
}
