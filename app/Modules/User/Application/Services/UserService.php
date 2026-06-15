<?php

namespace App\Modules\User\Application\Services;

use App\Models\User;
use App\Models\Church;
use App\Core\Exceptions\ApiException;
use App\Core\Services\CloudinaryService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;

/**
 * Service User — Gestion des membres EEC
 *
 * Règles d'accès :
 * - Admin    : Peut tout faire sur tous les utilisateurs
 * - Pasteur  : Peut gérer les membres de son église (sauf créer admin/pasteur)
 * - Secrétaire : Peut créer/gérer ancien, paroissien, trésorier de son église
 */
class UserService
{
    /** Rôles qu'une secrétaire peut créer */
    private const ROLES_SECRETAIRE = ['ancien', 'paroissien', 'tresorier'];

    /** Rôles qu'un pasteur peut créer */
    private const ROLES_PASTEUR = ['secretaire', 'tresorier', 'ancien', 'paroissien'];

    public function __construct(
        private readonly CloudinaryService $cloudinaryService
    ) {}

    /**
     * Liste les membres filtrés selon le rôle de l'utilisateur connecté.
     *
     * @param User  $authUser   Utilisateur faisant la requête
     * @param array $filters    Filtres : role, actif, search, church_id
     * @param int   $perPage
     */
    public function list(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = User::with('church')->orderBy('nom');

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

        // ─── Créer l'utilisateur ──────────────────────────────────────────────
        $user = User::create([
            'nom'               => $data['nom'],
            'email'             => $data['email'],
            'password'          => $data['password'] ?? 'EEC@' . rand(10000, 99999),
            'role'              => $data['role'],
            'churchId'          => $churchId,
            'actif'             => true,
            'est_de_service'    => false,
            'telephone'         => $data['telephone'] ?? null,
            'quartierResidence' => $data['quartierResidence'] ?? null,
            'profession'        => $data['profession'] ?? null,
            'groupe'            => $data['groupe'] ?? null,
            'originaireDe'      => $data['originaireDe'] ?? null,
            'dateConfirmation'  => $data['dateConfirmation'] ?? null,
            'lieuConfirmation'  => $data['lieuConfirmation'] ?? null,
        ]);

        // ─── Si c'est un pasteur, lier à l'église ─────────────────────────────
        if ($user->role === User::ROLE_PASTEUR && $churchId) {
            Church::where('id', $churchId)->update([
                'pastorId'  => $user->id,
                'pastorNom' => $user->nom,
            ]);
        }

        return $user->load('church');
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
        $user = User::with('church')->find($targetId);

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

        $updateData = array_filter([
            'nom'               => $data['nom']               ?? null,
            'email'             => $data['email']             ?? null,
            'role'              => $data['role']              ?? null,
            'telephone'         => $data['telephone']         ?? null,
            'quartierResidence' => $data['quartierResidence'] ?? null,
            'profession'        => $data['profession']        ?? null,
            'groupe'            => $data['groupe']            ?? null,
            'originaireDe'      => $data['originaireDe']      ?? null,
            'dateConfirmation'  => $data['dateConfirmation']  ?? null,
            'lieuConfirmation'  => $data['lieuConfirmation']  ?? null,
        ], fn($v) => !is_null($v));

        // Changement mots de passe si fourni
        if (!empty($data['password'])) {
            $updateData['password'] = $data['password'];
        }

        $user->update($updateData);

        return $user->fresh('church');
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

        $user->update(['actif' => !$user->actif]);

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
