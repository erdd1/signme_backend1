<?php

namespace App\Modules\Church\Application\Services;

use App\Models\Church;
use App\Models\User;
use App\Core\Exceptions\ApiException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Service Church — Gestion des congrégations EEC
 *
 * Accessible uniquement à l'administrateur système.
 */
class ChurchService
{
    /**
     * Liste toutes les églises avec pagination + recherche.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Church::withCount([
            'members as total_membres' => fn($q) => $q->where('actif', true),
        ])->orderBy('nom');

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', $search)
                  ->orWhere('ville', 'like', $search)
                  ->orWhere('region', 'like', $search);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Crée une nouvelle congrégation.
     *
     * @throws ApiException si le nom existe déjà dans la même ville
     */
    public function create(array $data): Church
    {
        $exists = Church::where('nom', $data['nom'])
                        ->where('ville', $data['ville'])
                        ->exists();

        if ($exists) {
            throw ApiException::conflict(
                "Une église nommée \"{$data['nom']}\" existe déjà dans la ville \"{$data['ville']}\"."
            );
        }

        return Church::create([
            'nom'       => $data['nom'],
            'ville'     => $data['ville'],
            'region'    => $data['region']    ?? null,
            'adresse'   => $data['adresse']   ?? null,
            'telephone' => $data['telephone'] ?? null,
            'email'     => $data['email']     ?? null,
        ]);
    }

    /**
     * Retourne une église avec ses statistiques.
     *
     * @throws ApiException si non trouvée
     */
    public function findWithStats(int $id): array
    {
        $church = Church::withCount([
            'members as total_membres'        => fn($q) => $q->where('actif', true),
            'members as total_anciens'        => fn($q) => $q->where('role', 'ancien')->where('actif', true),
            'members as total_paroissiens'    => fn($q) => $q->where('role', 'paroissien')->where('actif', true),
        ])->find($id);

        if (!$church) {
            throw ApiException::notFound('Église');
        }

        // Current month signatures count
        $currentMonth = now()->format('Y-m');
        $signaturesMonth = $church->signatures()
                                  ->where('month', $currentMonth)
                                  ->where('status', 'completed')
                                  ->count();

        return [
            ...$church->toArray(),
            'stats' => [
                'total_membres'     => $church->total_membres,
                'total_anciens'     => $church->total_anciens,
                'total_paroissiens' => $church->total_paroissiens,
                'signatures_mois_courant' => $signaturesMonth,
            ],
        ];
    }

    /**
     * Met à jour une congrégation.
     *
     * @throws ApiException si non trouvée
     */
    public function update(int $id, array $data): Church
    {
        $church = Church::find($id);
        if (!$church) {
            throw ApiException::notFound('Église');
        }

        $church->update(array_filter([
            'nom'       => $data['nom']       ?? null,
            'ville'     => $data['ville']     ?? null,
            'region'    => $data['region']    ?? null,
            'adresse'   => $data['adresse']   ?? null,
            'telephone' => $data['telephone'] ?? null,
            'email'     => $data['email']     ?? null,
        ], fn($v) => !is_null($v)));

        return $church->fresh();
    }

    /**
     * Assigne un pasteur à une église (et met à jour les deux côtés).
     *
     * @throws ApiException
     */
    public function assignPastor(int $churchId, int $pastorId): Church
    {
        $church = Church::find($churchId);
        if (!$church) throw ApiException::notFound('Église');

        $pastor = User::find($pastorId);
        if (!$pastor) throw ApiException::notFound('Pasteur');

        if ($pastor->role !== User::ROLE_PASTEUR) {
            throw ApiException::badRequest("L'utilisateur sélectionné n'est pas un pasteur.");
        }

        // Mettre à jour l'église
        $church->update([
            'pastorId'  => $pastor->id,
            'pastorNom' => $pastor->nom,
        ]);

        // Lier le pasteur à l'église
        $pastor->update(['churchId' => $church->id]);

        return $church->fresh();
    }

    /**
     * Supprime une congrégation (seulement si elle n'a plus de membres actifs).
     *
     * @throws ApiException
     */
    public function delete(int $id): void
    {
        $church = Church::find($id);
        if (!$church) throw ApiException::notFound('Église');

        $activeMembers = $church->members()->where('actif', true)->count();
        if ($activeMembers > 0) {
            throw ApiException::badRequest(
                "Impossible de supprimer : cette église a encore {$activeMembers} membre(s) actif(s)."
            );
        }

        $church->delete();
    }
}
