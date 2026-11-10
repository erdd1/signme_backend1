<?php

namespace App\Modules\Don\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\User;
use App\Modules\Evenement\Application\Services\ContributionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Service Don — « Faire un don » à son église, hors événement.
 *
 * Un don est une contribution rattachée à l'événement permanent « Dons libres »
 * de l'église (créé au premier don, invisible du fil et de la gestion) : tout
 * le circuit existant (paiement, webhook, trésorerie, rapports, « Mes
 * dépenses ») s'applique sans aucune logique dupliquée.
 */
class DonService
{
    public function __construct(private readonly ContributionService $contributionService) {}

    /**
     * @param array<string, mixed> $data mêmes champs qu'une contribution
     *
     * @throws ApiException
     */
    public function initiate(User $user, array $data): Contribution
    {
        if (!$user->churchId) {
            throw ApiException::forbidden('Vous devez appartenir à une église pour faire un don.');
        }

        $evenement = $this->donsLibresDe($user->churchId);

        return $this->contributionService->initiate($user, $evenement->uuid, $data);
    }

    /** Événement « Dons libres » de l'église, créé au besoin (verrou : une seule création). */
    private function donsLibresDe(int $churchId): Evenement
    {
        return Cache::lock("dons-libres:{$churchId}", 10)->block(5, function () use ($churchId) {
            return Evenement::where('churchId', $churchId)
                ->where('type', Evenement::TYPE_DON_LIBRE)
                ->first() ?? $this->creerDonsLibres($churchId);
        });
    }

    private function creerDonsLibres(int $churchId): Evenement
    {
        $church = Church::findOrFail($churchId);

        return Evenement::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $this->auteurPour($church),
            'titre'       => 'Dons libres',
            'description' => "Dons faits à l'Église en dehors d'un événement.",
            'statut'      => Evenement::STATUT_ACTIF,
            'type'        => Evenement::TYPE_DON_LIBRE,
            'dateDebut'   => now()->toDateString(),
            'publishedAt' => now(),
        ]);
    }

    /** Auteur technique : le pasteur de l'église, à défaut un pasteur ou un membre actif. */
    private function auteurPour(Church $church): int
    {
        $auteurId = $church->pastorId
            ?? User::where('churchId', $church->id)->where('role', User::ROLE_PASTEUR)->value('id')
            ?? User::where('churchId', $church->id)->where('actif', true)->orderBy('id')->value('id');

        return $auteurId ?? throw ApiException::unprocessable("Aucun responsable n'est rattaché à cette église.");
    }
}
