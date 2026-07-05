<?php

namespace App\Modules\UserLookup\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Service de base pour les listes gérées rattachées à une église
 * (Groupe, Quartier, Ville) — géré par le pasteur/la secrétaire, lu par
 * tous les rôles client. Même gabarit de scoping que RapportService.
 */
abstract class AbstractLookupService
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Libellé humain (minuscule, singulier) pour les messages d'erreur. */
    abstract protected function label(): string;

    public function list(User $authUser, ?int $churchId = null): Collection
    {
        $egliseId = $this->resolveChurchId($authUser, $churchId);
        $model = $this->modelClass();

        return $model::where('churchId', $egliseId)->orderBy('nom')->get();
    }

    public function create(User $authUser, array $data): Model
    {
        $egliseId = $this->resolveChurchId($authUser, $data['churchId'] ?? null);
        $model = $this->modelClass();
        $nom = trim($data['nom']);

        $exists = $model::where('churchId', $egliseId)->where('nom', $nom)->exists();
        if ($exists) {
            throw ApiException::conflict(
                ucfirst($this->label()) . " \"{$nom}\" existe déjà pour cette église."
            );
        }

        $payload = array_merge($data, [
            'uuid'      => (string) Str::uuid(),
            'churchId'  => $egliseId,
            'nom'       => $nom,
            'createdBy' => $authUser->id,
        ]);

        return $model::create($payload);
    }

    /**
     * Supprime une entrée. Les FK (users.*Id, groupe_user) sont configurées
     * en nullOnDelete/cascadeOnDelete — aucune donnée utilisateur n'est
     * perdue, seule la référence est nettoyée automatiquement.
     */
    public function delete(User $authUser, int $id): void
    {
        $model = $this->modelClass();
        $entry = $model::find($id);

        if (!$entry) {
            throw ApiException::notFound(ucfirst($this->label()));
        }

        if (!$authUser->isAdmin() && $entry->churchId !== $authUser->churchId) {
            throw ApiException::forbidden("Vous ne pouvez pas supprimer ce {$this->label()}.");
        }

        $entry->delete();
    }

    protected function resolveChurchId(User $authUser, ?int $churchId): int
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
