<?php

namespace App\Modules\Celebration\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Celebration;
use App\Models\CelebrationType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Service CelebrationType — Côté admin. Gère le catalogue des 9 occasions
 * (contenu + activation) et l'historique des célébrations déjà envoyées.
 *
 * Volontairement restreint : seuls `titre`, `messageTemplate` et `active`
 * sont modifiables. La clé, la règle de date et le filtre de genre sont
 * structurels (voir la migration de seed) — les changer casserait la
 * correspondance avec l'occasion réelle (ex. déplacer "Noël" au 20 mai
 * n'aurait pas de sens fonctionnel), donc non exposés à l'édition.
 */
class CelebrationTypeService
{
    public function listTypes(): Collection
    {
        return CelebrationType::orderBy('id')->get();
    }

    public function findType(int $id): CelebrationType
    {
        $type = CelebrationType::find($id);
        if (!$type) {
            throw ApiException::notFound('Type de célébration');
        }

        return $type;
    }

    public function updateType(CelebrationType $type, array $data): CelebrationType
    {
        if (array_key_exists('titre', $data) && $data['titre'] !== null) {
            $type->titre = $data['titre'];
        }
        if (array_key_exists('messageTemplate', $data) && $data['messageTemplate'] !== null) {
            $type->messageTemplate = $data['messageTemplate'];
        }
        if (array_key_exists('active', $data) && $data['active'] !== null) {
            $type->active = $data['active'];
        }
        $type->save();

        return $type;
    }

    /** @param array{churchId?:int, celebrationTypeId?:int} $filters */
    public function listInstances(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Celebration::query()->with(['type', 'user:id,nom', 'church:id,nom']);

        if (!empty($filters['churchId'])) {
            $query->where('churchId', (int) $filters['churchId']);
        }
        if (!empty($filters['celebrationTypeId'])) {
            $query->where('celebrationTypeId', (int) $filters['celebrationTypeId']);
        }

        return $query->orderByDesc('occursOn')->paginate($perPage);
    }

    /** @throws ApiException si non trouvée */
    public function findInstance(string $uuid): Celebration
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Célébration');
        }

        $celebration = Celebration::where('uuid', $uuid)->first();
        if (!$celebration) {
            throw ApiException::notFound('Célébration');
        }

        return $celebration;
    }

    public function deleteInstance(Celebration $celebration): void
    {
        $celebration->delete();
    }
}
