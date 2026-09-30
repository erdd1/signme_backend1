<?php

namespace App\Modules\User\Application\Services;

use App\Models\Church;
use App\Models\Groupe;
use App\Models\Quartier;
use App\Models\User;
use App\Models\Ville;
use App\Core\Exceptions\ApiException;
use App\Core\Helpers\DbCompat;
use App\Core\Services\ImageStorageService;
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
        private readonly ImageStorageService $imageStorageService
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
            $like = DbCompat::likeOperator();
            $query->where(function ($q) use ($search, $like) {
                $q->where('nom', $like, $search)
                  ->orWhere('email', $like, $search)
                  ->orWhere('telephone', $like, $search)
                  ->orWhere('matricule', $like, $search);
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
        $this->assertMatriculeValidForChurch($churchId, $data['matricule'] ?? null, $data['sexe'] ?? null);
        $estCommuniant = $this->resolveCommuniantForCreate($data['role'], $data);

        // La vérification ci-dessus (exists()) n'est pas atomique : sous une
        // course très rare (deux créations quasi simultanées avec le même
        // email), c'est la contrainte UNIQUE en base (migration users.email)
        // qui garantit qu'aucun doublon n'est JAMAIS réellement inséré — on
        // se contente ici de transformer cette violation en message propre
        // au lieu de laisser remonter une erreur 500 brute.
        try {
            $user = DB::transaction(function () use ($data, $churchId, $groupeIds, $groupePrincipalId, $estCommuniant) {
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
                    'matricule'           => $churchId ? ($data['matricule'] ?? null) : null,
                    'dateNaissance'       => $data['dateNaissance'] ?? null,
                    'quartierResidenceId' => $data['quartierId'] ?? null,
                    'villeResidenceId'    => $data['villeId'] ?? null,
                    'groupePrincipalId'   => $groupePrincipalId,
                ]);

                // Champs sensibles : assignation explicite (non mass-assignable).
                $user->role = $data['role'];
                $user->actif = true;
                $user->est_de_service = false;
                $user->estCommuniant = $estCommuniant;
                $user->save();

                if (!empty($groupeIds)) {
                    $user->groupes()->sync($groupeIds);
                }

                return $user;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                throw ApiException::conflict("Un compte avec l'email \"{$data['email']}\" existe déjà.");
            }
            throw $e;
        }

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

        $isSelfEdit = $authUser->id === $user->id;
        $isClientRole = !$authUser->isAdmin() && !$authUser->isPasteur() && !$authUser->isSecretaire();

        // Modifier un AUTRE membre reste réservé à pasteur/secrétaire (+
        // admin) — un rôle "client" (paroissien/ancien/trésorier) n'a plus
        // cette route bloquée en amont par middleware, donc ce garde-fou est
        // désormais appliqué ici.
        if (!$isSelfEdit && $isClientRole) {
            throw ApiException::forbidden('Vous n\'avez pas la permission de modifier ce membre.');
        }

        // Auto-édition par un rôle "client" : limitée aux champs non
        // sensibles exposés par l'app mobile (Nom, Téléphone, Sexe, Date de
        // naissance, Quartier, Ville) — jamais le rôle, l'église, le
        // matricule, les groupes ou le mot de passe (qui passe par
        // l'endpoint dédié de changement de mot de passe). Tout autre champ
        // envoyé est silencieusement ignoré plutôt que rejeté, puisque l'app
        // mobile ne les envoie jamais pour ces rôles — seule une requête
        // forgée à la main pourrait tenter de les inclure.
        if ($isSelfEdit && $isClientRole) {
            $data = array_intersect_key($data, array_flip([
                'nom', 'telephone', 'sexe', 'dateNaissance', 'quartierId', 'villeId',
            ]));
        }

        // Seul le super admin peut modifier un compte administrateur (y
        // compris un mini-admin avec le droit "modifier" sur Utilisateurs) —
        // et seul lui peut promouvoir quelqu'un vers ce rôle.
        if (
            (($user->isAdmin() && !$authUser->isSuperAdmin()))
            || (isset($data['role']) && $data['role'] === User::ROLE_ADMIN && !$authUser->isSuperAdmin())
        ) {
            throw ApiException::forbidden(
                'Seul le super administrateur peut modifier un compte administrateur.'
            );
        }

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

        // La photo passe uniquement par POST /users/{id}/photo.
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

        if (array_key_exists('matricule', $data)) {
            $effectiveSexe = array_key_exists('sexe', $data) ? $data['sexe'] : $user->sexe;
            $this->assertMatriculeValidForChurch($churchId, $data['matricule'], $effectiveSexe, $user->id);
            $updateData['matricule'] = $data['matricule'];
        }

        if (array_key_exists('groupePrincipalId', $data) || $groupeIds !== null) {
            $updateData['groupePrincipalId'] = $groupePrincipalId;
        }

        // Changement mots de passe si fourni
        if (!empty($data['password'])) {
            $updateData['password'] = $data['password'];
        }

        $newRole = $data['role'] ?? null;
        $effectiveRole = $newRole ?? $user->role;
        [$resolvedEstCommuniant, $resolvedCommuniantDepuis, $communiantChanged] =
            $this->resolveCommuniantForUpdate($user, $data, $effectiveRole);

        // Comme create() : la contrainte UNIQUE en base (users.email) reste
        // la garantie ultime contre un doublon sous une course rare — on
        // transforme juste sa violation en message propre.
        try {
            DB::transaction(function () use (
                $user,
                $updateData,
                $groupeIds,
                $newRole,
                $resolvedEstCommuniant,
                $resolvedCommuniantDepuis,
                $communiantChanged
            ) {
                $user->update($updateData);

                // Champs sensibles : assignation explicite (non mass-assignable).
                if ($newRole !== null) {
                    $user->role = $newRole;
                    $user->save();
                }

                if ($communiantChanged) {
                    $user->estCommuniant = $resolvedEstCommuniant;
                    $user->communiantDepuis = $resolvedCommuniantDepuis;
                    $user->save();
                }

                if ($groupeIds !== null) {
                    $user->groupes()->sync($groupeIds);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                throw ApiException::conflict("Un compte avec cet email existe déjà.");
            }
            throw $e;
        }

        return $user->fresh(self::RELATIONS);
    }

    /**
     * Upload/met à jour la photo de profil d'un membre.
     *
     * @throws ApiException
     */
    public function uploadPhoto(User $authUser, int $targetId, UploadedFile $file): User
    {
        $user = $this->findById($authUser, $targetId);

        $this->assertCanUploadPhoto($authUser, $user);

        // Validation du type MIME
        if (!ImageStorageService::isValidImage($file)) {
            throw ApiException::badRequest('Format de fichier non supporté. Utilisez JPG, PNG ou WebP.');
        }

        // Supprimer l'ancienne photo si elle existe
        if ($user->photoUrl) {
            $this->imageStorageService->deleteByUrl($user->photoUrl);
        }

        $filename   = $this->imageStorageService->generateUserFilename($user->id);
        $photoUrl   = $this->imageStorageService->uploadProfilePhoto($file, $filename);

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

        // Ne pas désactiver un admin — réservé au super admin (un mini-admin
        // avec le droit "modifier" sur Utilisateurs ne doit jamais pouvoir
        // désactiver un autre administrateur, encore moins le super admin).
        if ($user->isAdmin() && !$authUser->isSuperAdmin()) {
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

        // Supprimer la photo de profil stockée localement
        if ($user->photoUrl) {
            $this->imageStorageService->deleteByUrl($user->photoUrl);
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

    /**
     * Calcule le prochain identifiant disponible (H001-H999 / F001-F999)
     * pour une église et un sexe donnés — utilisé par le mode "génération
     * automatique" du formulaire de création de membre.
     *
     * @throws ApiException
     */
    public function nextAvailableMatricule(User $authUser, ?int $churchId, string $sexe): string
    {
        $targetChurchId = $this->resolveChurchIdOrDefault($authUser, $churchId);
        $prefix = $sexe === User::SEXE_FEMME ? 'F' : 'H';

        $max = User::where('churchId', $targetChurchId)
            ->where('matricule', 'like', $prefix . '___')
            ->pluck('matricule')
            ->map(fn ($m) => (int) substr($m, 1))
            ->max() ?? 0;

        $next = $max + 1;
        if ($next > 999) {
            throw ApiException::badRequest("Limite de 999 identifiants \"{$prefix}\" atteinte pour cette église.");
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    private function assertCanCreateRole(User $authUser, string $role): void
    {
        // Seul le super admin peut créer un nouveau compte administrateur —
        // sans ça, un mini-admin avec le droit "créer" sur Utilisateurs
        // pourrait se créer un accès total en passant par cet endpoint
        // générique, en contournant entièrement le système de permissions.
        if ($role === User::ROLE_ADMIN && !$authUser->isSuperAdmin()) {
            throw ApiException::forbidden(
                'Seul le super administrateur peut créer un compte administrateur.'
            );
        }

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
     * Vérifie l'identifiant propre à l'église (matricule) : obligatoire dès
     * qu'un membre est rattaché à une église, cohérent avec le sexe (préfixe
     * H/F) et unique au sein de cette église (deux églises différentes
     * peuvent réutiliser le même matricule sans conflit).
     *
     * @throws ApiException
     */
    private function assertMatriculeValidForChurch(
        ?int $churchId,
        ?string $matricule,
        ?string $sexe,
        ?int $excludeUserId = null
    ): void {
        if ($churchId === null) {
            return;
        }

        if (empty($matricule)) {
            throw ApiException::badRequest('L\'identifiant est obligatoire.');
        }

        if (!preg_match(User::MATRICULE_REGEX, $matricule)) {
            throw ApiException::badRequest('Format d\'identifiant invalide. Attendu : H001-H999 ou F001-F999.');
        }

        $expectedPrefix = $sexe === User::SEXE_FEMME ? 'F' : 'H';
        if ($sexe && $matricule[0] !== $expectedPrefix) {
            throw ApiException::badRequest('L\'identifiant ne correspond pas au sexe sélectionné.');
        }

        $query = User::where('churchId', $churchId)->where('matricule', $matricule);
        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }
        if ($query->exists()) {
            throw ApiException::conflict("L'identifiant \"{$matricule}\" est déjà utilisé dans cette église.");
        }
    }

    /**
     * Détermine le statut communiant à la création : obligatoire (et choisi
     * librement) pour un paroissien, forcé à `true` pour tout autre rôle —
     * les autres rôles sont et seront toujours communiants.
     *
     * @throws ApiException
     */
    private function resolveCommuniantForCreate(string $role, array $data): bool
    {
        if ($role !== User::ROLE_PAROISSIEN) {
            return true;
        }

        if (!array_key_exists('estCommuniant', $data) || $data['estCommuniant'] === null) {
            throw ApiException::badRequest(
                'Le statut communiant/non communiant est obligatoire pour un paroissien.'
            );
        }

        return (bool) $data['estCommuniant'];
    }

    /**
     * Détermine le statut communiant à la mise à jour :
     * - Rôle effectif ≠ paroissien : forcé à `true` (valeur cliente
     *   ignorée), `communiantDepuis` réinitialisé — les autres rôles sont
     *   et seront toujours communiants.
     * - Rôle effectif = paroissien et `estCommuniant` non fourni : inchangé.
     * - Rôle effectif = paroissien et valeur fournie : appliquée. Un
     *   passage false→true pose `communiantDepuis` au 1er du mois courant
     *   (plancher pour signer, jamais de rattrapage sur les mois où il
     *   n'était pas communiant) ; un passage true→false l'efface (sera
     *   reposé au prochain déblocage).
     *
     * @return array{0: bool, 1: ?string, 2: bool} [estCommuniant, communiantDepuis, changed]
     */
    private function resolveCommuniantForUpdate(User $user, array $data, string $effectiveRole): array
    {
        if ($effectiveRole !== User::ROLE_PAROISSIEN) {
            $alreadyDefault = $user->estCommuniant && $user->communiantDepuis === null;
            return [true, null, !$alreadyDefault];
        }

        if (!array_key_exists('estCommuniant', $data) || $data['estCommuniant'] === null) {
            return [$user->estCommuniant, $user->communiantDepuis?->toDateString(), false];
        }

        $newValue = (bool) $data['estCommuniant'];

        if ($newValue === $user->estCommuniant) {
            return [$newValue, $user->communiantDepuis?->toDateString(), false];
        }

        $communiantDepuis = $newValue ? now()->startOfMonth()->toDateString() : null;

        return [$newValue, $communiantDepuis, true];
    }

    /**
     * Règles photo de profil :
     * - Admin : tout le monde
     * - Tout rôle : peut modifier sa propre photo
     * - Pasteur / secrétaire : membres de la même église (en plus de la leur)
     */
    private function assertCanUploadPhoto(User $authUser, User $target): void
    {
        if ($authUser->isAdmin()) {
            return;
        }

        if ($authUser->id === $target->id) {
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
