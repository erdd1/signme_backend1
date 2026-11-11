<?php

namespace App\Modules\Groupe\Http;

use App\Core\Exceptions\ApiException;
use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Groupe;
use App\Models\Retrait;
use App\Modules\Auth\Application\Services\RateLimitService;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Evenement\Http\Resources\ContributionResource;
use App\Modules\Groupe\Application\Services\GroupeAccessService;
use App\Modules\Groupe\Application\Services\GroupeCaisseService;
use App\Modules\Groupe\Application\Services\GroupeCashService;
use App\Modules\Groupe\Application\Services\GroupeCommuniqueService;
use App\Modules\Groupe\Application\Services\GroupeMouvementsService;
use App\Modules\Groupe\Http\Requests\AnnulerEntreeCashRequest;
use App\Modules\Groupe\Http\Requests\StoreCommuniqueRequest;
use App\Modules\Groupe\Http\Requests\StoreEntreeCashRequest;
use App\Modules\Groupe\Http\Requests\StoreGroupeContributionRequest;
use App\Modules\Groupe\Http\Requests\StoreRetraitGroupeRequest;
use App\Modules\Groupe\Http\Requests\UpdateCommuniqueRequest;
use App\Modules\Groupe\Http\Resources\CommuniqueResource;
use App\Modules\Groupe\Http\Resources\GroupeResource;
use App\Modules\Retrait\Application\Services\RetraitService;
use App\Modules\Retrait\Http\Requests\StoreRetraitBeneficiaireRequest;
use App\Modules\Retrait\Http\Resources\RetraitBeneficiaireResource;
use App\Modules\Retrait\Http\Resources\RetraitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MesGroupesController — service « Groupes » (tout membre d'un groupe).
 *
 * Droits par POSTE dans le groupe (jamais par rôle) :
 *   membre       : lire communiqués, caisse, historique ; contribuer, donner
 *   président    : + créer/modifier/archiver les communiqués
 *   président ou trésorier : + entrées cash, retraits de la caisse
 *
 * GET    /mes-groupes
 * GET    /mes-groupes/{uuid}
 * GET    /mes-groupes/{uuid}/communiques
 * POST   /mes-groupes/{uuid}/communiques                         [président]
 * PUT    /mes-groupes/{uuid}/communiques/{cuuid}                 [président]
 * DELETE /mes-groupes/{uuid}/communiques/{cuuid}                 [président] (archive)
 * GET    /mes-groupes/{uuid}/communiques/{cuuid}/contributions
 * POST   /mes-groupes/{uuid}/contributions                       (communiqueUuid absent = don libre)
 * GET    /mes-groupes/{uuid}/mouvements
 * GET    /mes-groupes/{uuid}/membres                             [président/trésorier]
 * POST   /mes-groupes/{uuid}/entrees-cash                        [président/trésorier]
 * POST   /mes-groupes/{uuid}/entrees-cash/{euuid}/annuler        [président/trésorier]
 * GET    /mes-groupes/{uuid}/retraits/beneficiaire               [président/trésorier]
 * POST   /mes-groupes/{uuid}/retraits/beneficiaire               [président/trésorier]
 * POST   /mes-groupes/{uuid}/retraits                            [président/trésorier]
 * POST   /mes-groupes/{uuid}/retraits/{ruuid}/recheck            [président/trésorier]
 */
class MesGroupesController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly GroupeAccessService $access,
        private readonly GroupeCaisseService $caisse,
        private readonly GroupeCommuniqueService $communiques,
        private readonly GroupeCashService $cash,
        private readonly GroupeMouvementsService $mouvements,
        private readonly ContributionService $contributions,
        private readonly RetraitService $retraits,
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $groupes = $this->access->listPourMembre($request->user())
            ->map(fn (Groupe $g) => (new GroupeResource($g))->toArray($request))
            ->values();

        return $this->successResponse($groupes, 'Groupes récupérés avec succès.');
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        [$groupe] = $this->access->charger($request->user(), $uuid);

        $groupe = $request->user()->groupes()->where('groupes.id', $groupe->id)->withCount('membres')->first();

        return $this->successResponse(
            (new GroupeResource($groupe, $this->caisse->caisse($groupe), $this->responsables($groupe)))->toArray($request),
            'Groupe récupéré avec succès.'
        );
    }

    // ─── Communiqués ─────────────────────────────────────────────────────────

    public function communiques(Request $request, string $uuid): JsonResponse
    {
        [$groupe] = $this->access->charger($request->user(), $uuid);
        $stats = $this->communiques->statsParCommunique($groupe);

        $data = $this->communiques->listActifs($groupe)
            ->map(fn ($c) => (new CommuniqueResource($c, $stats[$c->id] ?? null))->toArray($request))
            ->values();

        return $this->successResponse($data, 'Communiqués récupérés avec succès.');
    }

    public function storeCommunique(StoreCommuniqueRequest $request, string $uuid): JsonResponse
    {
        [$groupe] = $this->access->chargerPresident($request->user(), $uuid);
        $communique = $this->communiques->creer($request->user(), $groupe, $request->validated())->load('auteur:id,nom');

        return $this->createdResponse((new CommuniqueResource($communique))->toArray($request), 'Communiqué publié avec succès.');
    }

    public function updateCommunique(UpdateCommuniqueRequest $request, string $uuid, string $communiqueUuid): JsonResponse
    {
        [$groupe] = $this->access->chargerPresident($request->user(), $uuid);
        $communique = $this->communiques->modifier($groupe, $communiqueUuid, $request->validated());
        $stats = $this->communiques->statsParCommunique($groupe)[$communique->id] ?? null;

        return $this->successResponse((new CommuniqueResource($communique, $stats))->toArray($request), 'Communiqué modifié avec succès.');
    }

    public function archiverCommunique(Request $request, string $uuid, string $communiqueUuid): JsonResponse
    {
        [$groupe] = $this->access->chargerPresident($request->user(), $uuid);
        $this->communiques->archiver($groupe, $communiqueUuid);

        return $this->successResponse(null, 'Communiqué retiré avec succès.');
    }

    /** Qui a contribué à ce communiqué — visible de tous les membres du groupe. */
    public function contributionsCommunique(Request $request, string $uuid, string $communiqueUuid): JsonResponse
    {
        [$groupe] = $this->access->charger($request->user(), $uuid);
        $communique = $this->communiques->trouver($groupe, $communiqueUuid, inclureArchives: true);

        $enLigne = Contribution::where('communiqueId', $communique->id)
            ->where('statut', Contribution::STATUT_COMPLETED)
            ->with('user:id,nom,photoUrl')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (Contribution $c) => [
                'nom'      => $c->user?->nom,
                'photoUrl' => $c->user?->photoUrl,
                'montant'  => $c->montant,
                'cash'     => false,
                'date'     => $c->created_at?->toIso8601String(),
            ]);

        // Argent remis en main propre pour ce communiqué (annulations exclues) :
        // au nom du membre choisi, à défaut du donateur saisi.
        $cash = $communique->entreesCash()
            ->whereNull('annuleAt')
            ->with('membre:id,nom,photoUrl')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($e) => [
                'nom'      => $e->membre?->nom ?: $e->donateur ?: 'Don en main propre',
                'photoUrl' => $e->membre?->photoUrl,
                'montant'  => $e->montant,
                'cash'     => true,
                'date'     => $e->created_at?->toIso8601String(),
            ]);

        $data = $enLigne->concat($cash)->sortByDesc('date')->values();

        return $this->successResponse($data, 'Contributions récupérées avec succès.');
    }

    // ─── Contributions et dons ───────────────────────────────────────────────

    public function contribuer(StoreGroupeContributionRequest $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        [$groupe] = $this->access->charger($user, $uuid);

        $communique = null;
        if ($request->filled('communiqueUuid')) {
            $communique = $this->communiques->trouver($groupe, $request->input('communiqueUuid'));
        }

        $this->rateLimitService->ensureWithinLimit('payment_initiation', (string) $user->id);
        $this->rateLimitService->incrementRateLimit('payment_initiation', (string) $user->id);

        $contribution = $this->contributions->initiateForGroupe($user, $groupe, $communique, $request->validated());

        return $this->createdResponse(
            (new ContributionResource($contribution))->toArray($request),
            'Paiement initié avec succès.'
        );
    }

    // ─── Caisse : historique, cash, retraits ─────────────────────────────────

    public function mouvements(Request $request, string $uuid): JsonResponse
    {
        [$groupe] = $this->access->charger($request->user(), $uuid);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        return $this->paginatedResponse(
            $this->mouvements->lister($groupe, max((int) $request->get('page', 1), 1), $perPage),
            'Historique de la caisse récupéré avec succès.'
        );
    }

    /** Membres du groupe (choix du donateur d'une entrée cash) — président ou trésorier. */
    public function membres(Request $request, string $uuid): JsonResponse
    {
        [$groupe] = $this->access->chargerResponsable($request->user(), $uuid);

        $data = $groupe->membres()
            ->orderBy('users.nom')
            ->get(['users.id', 'users.nom', 'users.photoUrl'])
            ->map(fn ($m) => ['id' => $m->id, 'nom' => $m->nom, 'photoUrl' => $m->photoUrl])
            ->values();

        return $this->successResponse($data, 'Membres récupérés avec succès.');
    }

    public function storeEntreeCash(StoreEntreeCashRequest $request, string $uuid): JsonResponse
    {
        [$groupe] = $this->access->chargerResponsable($request->user(), $uuid);
        $entree = $this->cash->enregistrer($request->user(), $groupe, $request->validated());

        return $this->createdResponse([
            'uuid'    => $entree->uuid,
            'montant' => $entree->montant,
            'caisse'  => $this->caisse->caisse($groupe),
        ], 'Entrée d\'argent enregistrée.');
    }

    public function annulerEntreeCash(AnnulerEntreeCashRequest $request, string $uuid, string $entreeUuid): JsonResponse
    {
        [$groupe] = $this->access->chargerResponsable($request->user(), $uuid);
        $this->cash->annuler($request->user(), $groupe, $entreeUuid, $request->validated()['motif']);

        return $this->successResponse(['caisse' => $this->caisse->caisse($groupe)], 'Entrée annulée.');
    }

    public function showBeneficiaire(Request $request, string $uuid): JsonResponse
    {
        $this->access->chargerResponsable($request->user(), $uuid);
        $beneficiaire = $this->retraits->getBeneficiaireActif($request->user());

        return $this->successResponse(
            $beneficiaire ? (new RetraitBeneficiaireResource($beneficiaire))->toArray($request) : null,
            'Compte de retrait récupéré avec succès.'
        );
    }

    public function storeBeneficiaire(StoreRetraitBeneficiaireRequest $request, string $uuid): JsonResponse
    {
        $this->access->chargerResponsable($request->user(), $uuid);
        $data = $request->validated();
        $beneficiaire = $this->retraits->registerBeneficiaire($request->user(), $data['operator'], $data['phone']);

        return $this->createdResponse(
            (new RetraitBeneficiaireResource($beneficiaire))->toArray($request),
            'Compte de retrait enregistré avec succès.'
        );
    }

    public function retirer(StoreRetraitGroupeRequest $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        [$groupe] = $this->access->chargerResponsable($user, $uuid);

        try {
            $this->rateLimitService->ensureWithinLimit('retrait_initiation', (string) $user->id);
            $this->rateLimitService->incrementRateLimit('retrait_initiation', (string) $user->id);
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), 429);
        }

        $data = $request->validated();
        $retrait = $this->retraits->initiateRetraitGroupe($user, $groupe, (int) $data['montant'], $data['motif']);

        return $this->createdResponse(
            (new RetraitResource($retrait))->toArray($request),
            $retrait->statut === Retrait::STATUT_FAILED
                ? 'Le retrait a échoué : ' . $retrait->motif_echec
                : 'Retrait initié avec succès.'
        );
    }

    public function recheckRetrait(Request $request, string $uuid, string $retraitUuid): JsonResponse
    {
        [$groupe] = $this->access->chargerResponsable($request->user(), $uuid);
        $retrait = $this->retraits->findByUuidForGroupe($retraitUuid, $groupe);

        return $this->successResponse(
            (new RetraitResource($this->retraits->recheck($retrait)))->toArray($request),
            'Statut revérifié avec succès.'
        );
    }

    /** @return array{president: ?string, tresorier: ?string} */
    private function responsables(Groupe $groupe): array
    {
        $membres = $groupe->membres()->get(['users.id', 'users.nom']);

        return [
            'president' => $membres->first(fn ($m) => $m->pivot->estPresident)?->nom,
            'tresorier' => $membres->first(fn ($m) => $m->pivot->estTresorier)?->nom,
        ];
    }
}
