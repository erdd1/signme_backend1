<?php

namespace App\Modules\User\Application\Services;

use App\Models\Church;
use App\Models\Groupe;
use App\Models\Quartier;
use App\Models\User;
use App\Models\Ville;
use App\Core\Exceptions\ApiException;
use App\Core\Services\CloudinaryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service User — Gestion des membres EEC
 *
 * Règles d'accès :
 * - Admin    : Peut tout faire sur tous les utilisateurs
 * - Pasteur  : Peut gérer les membres de son église (sauf créer admin/pasteur)
 * - Secrétaire : Peut créer/gérer ancien, paroissien, trésorier de son église
 *               ⚠ Ne peut PAS activer/désactiver les comptes
 */
class UserService
{
    /** Rôles qu'une secrétaire peut créer */
    private const ROLES_SECRETAIRE = ['ancien', 'paroissien', 'tresorier'];

    /** Rôles qu'un pasteur peut créer */
    private const ROLES_PASTEUR = ['secretaire', 'tresorier', 'ancien', 'paroissien'];

    /** Relations chargées systématiquement pour la sérialisation (UserResource) */
    private const RELATIONS = ['church', 'quartier', 'ville', 'groupePrincipal', 'groupes'];

    public function __construct(
        private readonly CloudinaryService $cloudinaryService
    ) {}

    /**
     * Liste les membres filtrés selon le rôle de l'utilisateur connecté.
     *
     * @param User  $authUser   Utilisateur faisant la requête
     * @param array $filters    Filtres : role, actif, search, church_id, sexe,
     *                          moisNaissance, quartierId, villeId, groupeId, originaireDe
     * @param int   $perPage
     */
    public function list(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = User::with(self::RELATIONS)->orderBy('nom');

        // ─── Restriction par église ────────────────────────────────────────────
        if (!$authUser->isAdmin()) {
            // Pasteur/Secrétaire/Trésorier : uniquement leur église
            $query->where('churchId', $authUser->churchId);
        } elseif (!empty($filters['church_id'])) {
            // Admin avec filtre optionnel par église
            $query->where('churchId', $filters['church_id']);
        }

        // ─── Filtres optionnels ────────────────────────────────────────────────
        if (!empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['actif'])) {
            $query->where('actif', (bool) $filters['actif']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('telephone', 'like', $search);
            });
        }

        // ─── Filtres démographiques (tableau de bord "Gérer utilisateurs") ────
        if (!empty($filters['sexe'])) {
            $query->where('sexe', $filters['sexe']);
        }

        if (!empty($filters['moisNaissance'])) {
            $query->whereMonth('dateNaissance', (int) $filters['moisNaissance']);
        }

        if (!empty($filters['quartierId'])) {
            $query->where('quartierResidenceId', $filters['quartierId']);
        }

        if (!empty($filters['villeId'])) {
            $query->where('villeResidenceId', $filters['villeId']);
        }

        if (!empty($filters['groupeId'])) {
            $groupeId = $filters['groupeId'];
            $query->whereHas('groupes', fn ($q) => $q->where('groupes.id', $groupeId));
        }

        if (!empty($filters['originaireDe'])) {
            $query->where('originaireDe', $filters['originaireDe']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Crée un nouveau membre avec son compte.
     *
     * @param User  $authUser   Utilisateur créant le compte
     * @param array $data       Données validées
     * @throws ApiException
     */
    public function create(User $authUser, array $data): User
    {
        // ─── Vérifier les droits de création ──────────────────────────────────
        $this->assertCanCreateRole($authUser, $data['role']);

        // ─── Déterminer l'église du nouveau membre ─────────────────────────────
        $churchId = $this->resolveChurchId($authUser, $data);

        // ─── Vérifier que l'email n'existe pas ────────────────────────────────
        if (User::where('email', $data['email'])->exists()) {
            throw ApiException::conflict("Un compte avec l'email \"{$data['email']}\" existe déjà.");
        }

        $groupeIds = $data['groupeIds'] ?? [];
        $groupePrincipalId = $data['groupePrincipalId'] ?? null;

        if ($churchId) {
            $this->assertLookupsBelongToChurch(
                $churchId,
                $data['quartierId'] ?? null,
                $data['villeId'] ?? null,
                $groupeIds
            );
        }
        $this->assertGroupePrincipalCoherent($groupeIds, $groupePrincipalId);

        $user = DB::transaction(function () use ($data, $churchId, $groupeIds, $groupePrincipalId) {
            // 'role' est NOT NULL sans défaut en base : il doit être posé
            // AVANT le premier save() (pas d'INSERT intermédiaire sans lui).
            $user = new User([
                'nom'                 => $data['nom'],
                'email'               => $data['email'],
                'password'            => $data['password'] ?? 'EEC@' . rand(10000, 99999),
                'churchId'            => $churchId,
                'telephone'           => $data['telephone'] ?? null,
                'profession'          => $data['profession'] ?? null,
                'originaireDe'        => $data['originaireDe'] ?? null,
                'dateConfirmation'    => $data['dateConfirmation'] ?? null,
                'lieuConfirmation'    => $data['lieuConfirmation'] ?? null,
                'sexe'                => $data['sexe'] ?? null,
                'dateNaissance'       => $data['dateNaissance'] ?? null,
                'quartierResidenceId' => $data['quartierId'] ?? null,
                'villeResidenceId'    => $data['villeId'] ?? null,
                'groupePrincipalId'   => $groupePrincipalId,
            ]);

            // Champs sensibles : assignation explicite (non mass-assignable).
            $user->role = $data['role'];
            $user->actif = true;
            $user->est_de_service = false;
            $user->save();

            if (!empty($groupeIds)) {
                $user->groupes()->sync($groupeIds);
            }

            return $user;
        });

        // ─── Si c'est un pasteur, lier à l'église ─────────────────────────────
        if ($user->role === User::ROLE_PASTEUR && $churchId) {
            Church::where('id', $churchId)->update([
                'pastorId'  => $user->id,
                'pastorNom' => $user->nom,
            ]);
        }

        return $user->load(self::RELATIONS);
    }

    /**
     * Retourne le détail d'un membre.
     *
     * @param User $authUser    Utilisateur faisant la requête
     * @param int  $targetId    ID du membre recherché
     * @throws ApiException
     */
    public function findById(User $authUser, int $targetId): User
    {
        $user = User::with(self::RELATIONS)->find($targetId);

        if (!$user) throw ApiException::notFound('Utilisateur');

        // Vérification d'appartenance à l'église
        if (!$authUser->isAdmin() && $user->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Vous n\'avez pas accès à ce membre.');
        }

        return $user;
    }

    /**
     * Met à jour les informations d'un membre.
     *
     * @throws ApiException
     */
    public function update(User $authUser, int $targetId, array $data): User
    {
        $user = $this->findById($authUser, $targetId);

        // Empêcher la modification de rôle par une secrétaire
        if ($authUser->isSecretaire() && isset($data['role'])) {
            throw ApiException::forbidden('Une secrétaire ne peut pas modifier les rôles.');
        }

        // Empêcher la modification du role vers admin/pasteur par un pasteur
        if ($authUser->isPasteur() && isset($data['role'])) {
            if (in_array($data['role'], [User::ROLE_ADMIN, User::ROLE_PASTEUR], true)) {
                throw ApiException::forbidden('Vous ne pouvez pas assigner ce rôle.');
            }
        }

        // La photo passe uniquement par POST /users/{id}/photo (Cloudinary).
        unset($data['photoUrl'], $data['photo_url']);

        $churchId = $user->churchId;

        // ─── Groupes multiples : null = "non fourni" (inchangé), [] = "vider" ──
        $groupeIds = array_key_exists('groupeIds', $data) ? ($data['groupeIds'] ?? []) : null;
        $groupePrincipalId = array_key_exists('groupePrincipalId', $data)
            ? $data['groupePrincipalId']
            : $user->groupePrincipalId;

        $effectiveGroupeIds = $groupeIds ?? $user->groupes()->pluck('groupes.id')->toArray();

        if ($churchId) {
            $this->assertLookupsBelongToChurch(
                $churchId,
                array_key_exists('quartierId', $data) ? $data['quartierId'] : null,
                array_key_exists('villeId', $data) ? $data['villeId'] : null,
                $groupeIds ?? []
            );
        }
        $this->assertGroupePrincipalCoherent($effectiveGroupeIds, $groupePrincipalId);

        $updateData = array_filter([
            'nom'               => $data['nom']               ?? null,
            'email'             => $data['email']             ?? null,
            'telephone'         => $data['telephone']         ?? null,
            'profession'        => $data['profession']        ?? null,
            'originaireDe'      => $data['originaireDe']      ?? null,
            'dateConfirmation'  => $data['dateConfirmation']  ?? null,
            'lieuConfirmation'  => $data['lieuConfirmation']  ?? null,
        ], fn ($v) => !is_null($v));

        // Champs démographiques : appliqués dès qu'ils sont présents dans la
        // requête (même à null, ex. retirer un quartier), pas seulement non-vides.
        $nullableFields = [
            'sexe'        => 'sexe',
            'dateNaissance' => 'dateNaissance',
            'quartierId'  => 'quartierResidenceId',
            'villeId'     => 'villeResidenceId',
        ];
        foreach ($nullableFields as $inputKey => $column) {
            if (array_key_exists($inputKey, $data)) {
                $updateData[$column] = $data[$inputKey];
            }
        }

        if (array_key_exists('groupePrincipalId', $data) || $groupeIds !== null) {
            $updateData['groupePrincipalId'] = $groupePrincipalId;
        }

        // Changement mots de passe si fourni
        if (!empty($data['password'])) {
            $updateData['password'] = $data['password'];
        }

        $newRole = $data['role'] ?? null;

        DB::transaction(function () use ($user, $updateData, $groupeIds, $newRole) {
            $user->update($updateData);

            // Champ sensible : assignation explicite (non mass-assignable).
            if ($newRole !== null) {
                $user->role = $newRole;
                $user->save();
            }

            if ($groupeIds !== null) {
                $user->groupes()->sync($groupeIds);
            }
        });

        return $user->fresh(self::RELATIONS);
    }

    /**
     * Upload/met à jour la photo de profil d'un membre (Cloudinary).
     *
     * @throws ApiException
     */
    public function uploadPhoto(User $authUser, int $targetId, UploadedFile $file): User
    {
        $user = $this->findById($authUser, $targetId);

        $this->assertCanUploadPhoto($authUser, $user);

        // Validation du type MIME
        if (!CloudinaryService::isValidImage($file)) {
            throw ApiException::badRequest('Format de fichier non supporté. Utilisez JPG, PNG ou WebP.');
        }

        // Supprimer l'ancienne photo si elle existe
        if ($user->photoUrl) {
            $this->cloudinaryService->deleteByUrl($user->photoUrl);
        }

        // Upload sur Cloudinary
        $publicId   = $this->cloudinaryService->generateUserPublicId($user->id);
        $photoUrl   = $this->cloudinaryService->uploadProfilePhoto($file, $publicId);

        $user->update(['photoUrl' => $photoUrl]);

        return $user->fresh();
    }

    /**
     * Active ou désactive le compte d'un membre.
     *
     * @throws ApiException
     */
    public function toggleStatus(User $authUser, int $targetId): User
    {
        // Seule la secrétaire est explicitement interdite ici
        // (double protection : la route est déjà filtrée par role:pasteur)
        if ($authUser->isSecretaire()) {
            throw ApiException::forbidden(
                'Une secrétaire ne peut pas activer ou désactiver un compte.'
            );
        }

        $user = $this->findById($authUser, $targetId);

        // Ne pas désactiver un admin
        if ($user->isAdmin() && !$authUser->isAdmin()) {
            throw ApiException::forbidden('Vous ne pouvez pas désactiver un administrateur.');
        }

        //EMPECHER L'ADMIN DE SE DESACTIVER LUI MEME

        if (
            $authUser->id === $user->id &&
            $user->isAdmin() &&
            $user->actif
        ) {
            throw ApiException::forbidden(
                'Un administrateur ne peut pas désactiver son propre compte.'
            );
        }

        $user->actif = !$user->actif;
        $user->save();

        // Si désactivé : révoquer tous ses tokens
        if (!$user->actif) {
            $user->tokens()->delete();
        }

        $status = $user->actif ? 'activé' : 'désactivé';
        return $user->fresh();
    }

    /**
     * Supprime un membre (soft-delete ou delete selon politique).
     *
     * @throws ApiException
     */
    public function delete(User $authUser, int $targetId): void
    {
        $user = $this->findById($authUser, $targetId);

        if ($user->isAdmin()) {
            throw ApiException::forbidden('Impossible de supprimer un compte administrateur.');
        }

        // Vérifier qu'il n'a pas de signatures (intégrité des données)
        $signaturesCount = $user->signatures()->count();
        if ($signaturesCount > 0) {
            throw ApiException::badRequest(
                "Impossible de supprimer : ce membre a {$signaturesCount} signature(s) enregistrée(s). Désactivez plutôt son compte."
            );
        }

        // Supprimer la photo Cloudinary
        if ($user->photoUrl) {
            $this->cloudinaryService->deleteByUrl($user->photoUrl);
        }

        // Révoquer les tokens
        $user->tokens()->delete();
        $user->delete();
    }

    /**
     * Statistiques démographiques pour le tableau de bord "Gérer utilisateurs" :
     * total fidèles, répartition Cameroun/Diaspora, répartition Hommes/Femmes.
     *
     * @throws ApiException
     */
    public function stats(User $authUser, ?int $churchId = null): array
    {
        $egliseId = $this->resolveChurchIdOrDefault($authUser, $churchId);
        $cacheKey = "users:stats:{$egliseId}";

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($egliseId) {
            $totals = User::where('churchId', $egliseId)
                ->selectRaw(
                    'COUNT(*) as total,
                     SUM(CASE WHEN sexe = ? THEN 1 ELSE 0 END) as hommes,
                     SUM(CASE WHEN sexe = ? THEN 1 ELSE 0 END) as femmes',
                    [User::SEXE_HOMME, User::SEXE_FEMME]
                )
                ->first();

            $auCameroun = User::where('churchId', $egliseId)
                ->whereHas('ville', fn ($q) => $q->where('estDiaspora', false))
                ->count();

            $horsCameroun = User::where('churchId', $egliseId)
                ->whereHas('ville', fn ($q) => $q->where('estDiaspora', true))
                ->count();

            return [
                'eglise_id'     => $egliseId,
                'total_fideles' => (int) ($totals->total ?? 0),
                'hommes'        => (int) ($totals->hommes ?? 0),
                'femmes'        => (int) ($totals->femmes ?? 0),
                'au_cameroun'   => $auCameroun,
                'hors_cameroun' => $horsCameroun,
            ];
        });
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    private function assertCanCreateRole(User $authUser, string $role): void
    {
        if ($authUser->isAdmin()) return;

        if ($authUser->isPasteur() && !in_array($role, self::ROLES_PASTEUR, true)) {
            throw ApiException::forbidden(
                "Un pasteur ne peut pas créer un compte de rôle \"{$role}\"."
            );
        }

        if ($authUser->isSecretaire() && !in_array($role, self::ROLES_SECRETAIRE, true)) {
            throw ApiException::forbidden(
                "Une secrétaire ne peut créer que : " . implode(', ', self::ROLES_SECRETAIRE) . "."
            );
        }
    }

    private function resolveChurchId(User $authUser, array $data): ?int
    {
        if ($authUser->isAdmin()) {
            // L'admin peut assigner n'importe quelle église
            return $data['churchId'] ?? null;
        }

        // Autres rôles : le membre rejoint l'église du créateur
        return $authUser->churchId;
    }

    private function resolveChurchIdOrDefault(User $authUser, ?int $churchId): int
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

    /**
     * Vérifie que le quartier/la ville/les groupes sélectionnés appartiennent
     * bien à l'église du membre (évite qu'un membre soit rattaché à une liste
     * gérée d'une autre église).
     *
     * @throws ApiException
     */
    private function assertLookupsBelongToChurch(
        int $churchId,
        ?int $quartierId,
        ?int $villeId,
        array $groupeIds
    ): void {
        if ($quartierId && !Quartier::where('id', $quartierId)->where('churchId', $churchId)->exists()) {
            throw ApiException::badRequest('Ce quartier n\'appartient pas à votre église.');
        }

        if ($villeId && !Ville::where('id', $villeId)->where('churchId', $churchId)->exists()) {
            throw ApiException::badRequest('Cette ville n\'appartient pas à votre église.');
        }

        if (!empty($groupeIds)) {
            $uniqueIds = array_unique($groupeIds);
            $count = Groupe::whereIn('id', $uniqueIds)->where('churchId', $churchId)->count();
            if ($count !== count($uniqueIds)) {
                throw ApiException::badRequest('Un ou plusieurs groupes sélectionnés n\'appartiennent pas à votre église.');
            }
        }
    }

    /**
     * Le groupe principal doit obligatoirement faire partie des groupes
     * sélectionnés par le membre.
     *
     * @throws ApiException
     */
    private function assertGroupePrincipalCoherent(array $groupeIds, ?int $groupePrincipalId): void
    {
        if ($groupePrincipalId === null) {
            return;
        }

        if (empty($groupeIds) || !in_array($groupePrincipalId, $groupeIds, true)) {
            throw ApiException::badRequest('Le groupe principal doit faire partie des groupes sélectionnés.');
        }
    }

    /**
     * Règles photo de profil :
     * - Admin : tout le monde
     * - Pasteur / secrétaire : membres de la même église (pas soi pour secrétaire/autres)
     * - Pasteur : peut modifier sa propre photo
     * - Autres rôles : ne peuvent pas modifier leur propre photo
     */
    private function assertCanUploadPhoto(User $authUser, User $target): void
    {
        if ($authUser->isAdmin()) {
            return;
        }

        $isSelf = $authUser->id === $target->id;

        if ($isSelf) {
            if (!$authUser->isPasteur()) {
                throw ApiException::forbidden(
                    'Vous ne pouvez pas modifier votre propre photo de profil. Contactez votre pasteur ou secrétaire.'
                );
            }
            return;
        }

        if (!$authUser->isPasteur() && !$authUser->isSecretaire()) {
            throw ApiException::forbidden(
                'Vous n\'avez pas la permission de modifier la photo de ce membre.'
            );
        }

        if ($authUser->churchId !== $target->churchId) {
            throw ApiException::forbidden(
                'Vous ne pouvez modifier la photo que des membres de votre église.'
            );
        }
    }
}
