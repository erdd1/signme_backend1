<?php

namespace App\Modules\Evenement\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Core\Helpers\DbCompat;
use App\Core\Services\ImageStorageService;
use App\Models\Evenement;
use App\Models\EvenementStateTransition;
use App\Models\User;
use App\Modules\Push\Application\Jobs\SendNewContentPushJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service Evenement — Module collecte de fonds (crowdfunding interne église)
 *
 * Droits :
 * - Lecture : tous les rôles, filtrée par visibilité (brouillon = auteur uniquement,
 *   planifié = auteur uniquement tant que non publié, actif/terminé = tous, archivé = disparaît du fil)
 * - Création/modification/transitions d'état : pasteur, secrétaire (+ admin, sans restriction d'auteur)
 */
class EvenementService
{
    public const MODE_BROUILLON = 'brouillon';
    public const MODE_IMMEDIATE = 'immediate';
    public const MODE_PLANIFIE  = 'planifie';

    public function __construct(
        private readonly ImageStorageService $imageStorageService,
    ) {}

    /**
     * Liste les événements visibles par l'utilisateur (fil d'actualité).
     */
    public function list(User $authUser, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        // Auto-archivage paresseux : ne dépend pas uniquement du scheduler/job
        // planifié (cf. ArchiveExpiredEvenementsJob) — un événement "terminé"
        // depuis plus de JOURS_AVANT_ARCHIVAGE doit disparaître du fil dès la
        // prochaine consultation, même si le job planifié n'a pas encore tourné.
        $this->archiveOverdueEvenements();

        $query = Evenement::query()
            ->collectes()
            ->with(['auteur', 'church'])
            ->orderByDesc('publishedAt')
            ->orderByDesc('dateDebut');

        if (!$authUser->isAdmin()) {
            $query->where('churchId', $authUser->churchId);
        } elseif (!empty($filters['church_id'])) {
            $query->where('churchId', $filters['church_id']);
        }

        // Visibilité : actif/terminé pour tous ; brouillon/planifié réservés à l'auteur (ou admin)
        $query->where(function ($q) use ($authUser) {
            $q->whereIn('statut', [Evenement::STATUT_ACTIF, Evenement::STATUT_TERMINE]);

            if (!$authUser->isAdmin()) {
                $q->orWhere('auteurId', $authUser->id);
            }
        });

        if (!empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Vue "Gérer les événements" — pasteur/secrétaire/trésorier (droit vérifié
     * par le middleware de route). Contrairement à list(), aucune restriction
     * de visibilité par statut : les événements ACTIF et ARCHIVE (et tout
     * autre statut) sont visibles, avec le montant collecté et le nombre de
     * contributeurs distincts par événement.
     */
    public function listForGestion(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $this->archiveOverdueEvenements();

        $query = Evenement::query()
            ->collectes()
            ->with(['auteur', 'church'])
            ->selectSub(
                \App\Models\Contribution::selectRaw('COUNT(DISTINCT ' . DbCompat::quoteColumn('userId') . ')')
                    ->whereColumn('evenementId', 'evenements.id')
                    ->where('statut', \App\Models\Contribution::STATUT_COMPLETED),
                'nombreContributeurs'
            )
            ->addSelect('evenements.*')
            ->orderByDesc('publishedAt')
            ->orderByDesc('dateDebut');

        if (!$authUser->isAdmin()) {
            $query->where('churchId', $authUser->churchId);
        } elseif (!empty($filters['church_id'])) {
            $query->where('churchId', $filters['church_id']);
        }

        if (!empty($filters['statut'])) {
            // Accepte une liste séparée par des virgules (ex: "termine,archive"
            // pour l'onglet "Archivés" qui regroupe aussi les événements
            // terminés pas encore quittés du fil d'actualité).
            $statuts = array_filter(array_map('trim', explode(',', (string) $filters['statut'])));
            $query->whereIn('statut', $statuts);
        }

        return $query->paginate($perPage);
    }

    public function findByUuid(User $authUser, string $uuid): Evenement
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Evenement');
        }

        $evenement = Evenement::where('uuid', $uuid)->with(['auteur', 'church'])->first();

        if (!$evenement) {
            throw ApiException::notFound('Evenement');
        }

        if (!$authUser->isAdmin() && $evenement->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Accès refusé à cet événement.');
        }

        if (!$evenement->estVisiblePour($authUser)) {
            throw ApiException::notFound('Evenement');
        }

        return $evenement;
    }

    /**
     * Crée un événement (brouillon, publication immédiate ou planifiée).
     *
     * @throws ApiException
     */
    public function create(User $authUser, array $data, ?UploadedFile $image = null): Evenement
    {
        $this->assertPeutGererEvenements($authUser);

        $churchId = $authUser->isAdmin()
            ? ($data['churchId'] ?? $authUser->churchId)
            : $authUser->churchId;

        if (!$churchId) {
            throw ApiException::badRequest('Église introuvable pour cet utilisateur.');
        }

        $dateDebut = \Carbon\Carbon::parse($data['dateDebut'])->startOfDay();
        if ($dateDebut->lt(now()->startOfDay())) {
            throw ApiException::badRequest('La date de début ne peut pas être dans le passé.');
        }

        $dateFin = null;
        if (!empty($data['dateFin'])) {
            $dateFin = \Carbon\Carbon::parse($data['dateFin']);
            if ($dateFin->lte($dateDebut)) {
                throw ApiException::badRequest('La date de fin doit être supérieure à la date de début.');
            }
        }

        $mode = $data['mode'] ?? self::MODE_BROUILLON;
        [$statut, $publishedAt, $scheduledPublishAt] = $this->resolvePublicationMode($mode, $data);

        $imageUrl = null;
        if ($image) {
            $imageUrl = $this->imageStorageService->uploadEvenementImage(
                $image,
                $this->imageStorageService->generateEvenementFilename()
            );
        }

        return DB::transaction(function () use (
            $authUser, $churchId, $data, $dateDebut, $dateFin, $statut, $publishedAt, $scheduledPublishAt, $imageUrl
        ) {
            $evenement = Evenement::create([
                'uuid'               => (string) Str::uuid(),
                'churchId'           => $churchId,
                'auteurId'           => $authUser->id,
                'titre'              => $data['titre'],
                'description'        => $data['description'],
                'imageUrl'           => $imageUrl,
                'statut'             => $statut,
                'dateDebut'          => $dateDebut,
                'dateFin'            => $dateFin,
                'publishedAt'        => $publishedAt,
                'scheduledPublishAt' => $scheduledPublishAt,
                'objectifMontant'    => $data['objectifMontant'] ?? null,
            ]);

            $this->logTransition($evenement, null, $statut, $authUser, 'creation');

            if ($statut === Evenement::STATUT_ACTIF) {
                SendNewContentPushJob::dispatch(
                    (int) $evenement->churchId,
                    (int) $evenement->auteurId,
                    'Nouvel événement',
                    $evenement->titre,
                    ['type' => 'evenement', 'id' => $evenement->uuid],
                );
            }

            return $evenement;
        });
    }

    /**
     * @return array{0: string, 1: \Carbon\Carbon|null, 2: \Carbon\Carbon|null}
     */
    private function resolvePublicationMode(string $mode, array $data): array
    {
        return match ($mode) {
            self::MODE_IMMEDIATE => [Evenement::STATUT_ACTIF, now(), null],
            self::MODE_PLANIFIE => (function () use ($data) {
                if (empty($data['scheduledPublishAt'])) {
                    throw ApiException::badRequest('La date/heure de publication planifiée est requise.');
                }
                $scheduledAt = \Carbon\Carbon::parse($data['scheduledPublishAt']);
                if ($scheduledAt->lte(now())) {
                    throw ApiException::badRequest('La date de publication planifiée doit être dans le futur.');
                }
                return [Evenement::STATUT_PLANIFIE, null, $scheduledAt];
            })(),
            self::MODE_BROUILLON => [Evenement::STATUT_BROUILLON, null, null],
            default => throw ApiException::badRequest('Mode de publication invalide.'),
        };
    }

    /**
     * Met à jour un événement. Seul l'auteur (ou admin) peut modifier, et seulement
     * si l'événement n'est ni terminé ni archivé.
     */
    public function update(User $authUser, string $uuid, array $data, ?UploadedFile $image = null): Evenement
    {
        $evenement = $this->findByUuid($authUser, $uuid);
        $this->assertEstAuteurOuAdmin($authUser, $evenement);

        if (in_array($evenement->statut, [Evenement::STATUT_TERMINE, Evenement::STATUT_ARCHIVE], true)) {
            throw ApiException::forbidden(
                'Impossible de modifier un événement terminé ou archivé. Désarchivez-le d\'abord si nécessaire.'
            );
        }

        $updates = array_filter([
            'titre'           => $data['titre'] ?? null,
            'description'     => $data['description'] ?? null,
            'objectifMontant' => array_key_exists('objectifMontant', $data) ? $data['objectifMontant'] : null,
        ], fn ($v) => !is_null($v));

        if (!empty($data['dateFin'])) {
            $dateFin = \Carbon\Carbon::parse($data['dateFin']);
            if ($dateFin->lte($evenement->dateDebut)) {
                throw ApiException::badRequest('La date de fin doit être supérieure à la date de début.');
            }
            $updates['dateFin'] = $dateFin;
        }

        $previousImageUrl = $evenement->imageUrl;

        if ($image) {
            $updates['imageUrl'] = $this->imageStorageService->uploadEvenementImage(
                $image,
                $this->imageStorageService->generateEvenementFilename()
            );
        }

        $evenement->update($updates);

        // Supprimer l'ancienne image APRÈS la mise à jour réussie (jamais avant :
        // si l'upload ou la sauvegarde échoue, l'ancienne image doit rester intacte).
        if ($image && $previousImageUrl) {
            $this->imageStorageService->deleteByUrl($previousImageUrl);
        }

        return $evenement->fresh();
    }

    /**
     * Publie immédiatement un événement brouillon.
     */
    /**
     * Publie immédiatement un événement en brouillon OU un événement déjà
     * planifié pas encore publié (l'auteur peut changer d'avis à tout moment
     * et publier avant la date/heure prévue — le minuteur planifié n'a alors
     * plus lieu d'être et est nettoyé).
     */
    public function publish(User $authUser, string $uuid): Evenement
    {
        $evenement = $this->findByUuid($authUser, $uuid);
        $this->assertEstAuteurOuAdmin($authUser, $evenement);

        $statutsPubliables = [Evenement::STATUT_BROUILLON, Evenement::STATUT_PLANIFIE];
        if (!in_array($evenement->statut, $statutsPubliables, true) || $evenement->publishedAt !== null) {
            throw ApiException::badRequest('Seul un événement en brouillon ou planifié (pas encore publié) peut être publié.');
        }

        return DB::transaction(function () use ($evenement, $authUser) {
            $ancien = $evenement->statut;
            $raison = $ancien === Evenement::STATUT_PLANIFIE
                ? 'publication anticipée (planification avancée manuellement)'
                : 'publication manuelle';
            $evenement->update([
                'statut'             => Evenement::STATUT_ACTIF,
                'publishedAt'        => now(),
                'scheduledPublishAt' => null,
            ]);
            $this->logTransition($evenement, $ancien, Evenement::STATUT_ACTIF, $authUser, $raison);

            SendNewContentPushJob::dispatch(
                (int) $evenement->churchId,
                (int) $evenement->auteurId,
                'Nouvel événement',
                $evenement->titre,
                ['type' => 'evenement', 'id' => $evenement->uuid],
            );

            return $evenement->fresh();
        });
    }

    /**
     * Annule une publication planifiée (uniquement si pas encore publiée).
     */
    /**
     * Annule un événement planifié pas encore publié — suppression définitive.
     *
     * Contrairement aux autres événements (jamais supprimés, pour raisons
     * légales/comptables), un événement planifié qui n'a jamais été publié
     * n'a jamais été visible de personne d'autre que l'auteur et ne peut avoir
     * aucune contribution (celles-ci exigent un événement ACTIF) : sa
     * suppression physique ne pose donc aucun risque de traçabilité.
     *
     * @throws ApiException
     */
    public function cancelScheduledPublication(User $authUser, string $uuid): void
    {
        $evenement = $this->findByUuid($authUser, $uuid);
        $this->assertEstAuteurOuAdmin($authUser, $evenement);

        if ($evenement->statut !== Evenement::STATUT_PLANIFIE || $evenement->publishedAt !== null) {
            throw ApiException::badRequest('Impossible d\'annuler : cet événement est déjà publié ou n\'est pas planifié.');
        }

        $evenement->delete();
    }

    /**
     * Termine un événement (manuel).
     */
    public function terminer(User $authUser, string $uuid): Evenement
    {
        $evenement = $this->findByUuid($authUser, $uuid);
        $this->assertEstAuteurOuAdmin($authUser, $evenement);

        if ($evenement->statut !== Evenement::STATUT_ACTIF) {
            throw ApiException::badRequest('Seul un événement actif peut être terminé.');
        }

        return DB::transaction(function () use ($evenement, $authUser) {
            $ancien = $evenement->statut;
            $evenement->update(['statut' => Evenement::STATUT_TERMINE, 'termineAt' => now()]);
            $this->logTransition($evenement, $ancien, Evenement::STATUT_TERMINE, $authUser, 'terminé manuellement');
            return $evenement->fresh();
        });
    }

    /**
     * Archive manuellement un événement terminé. Pasteur/Secrétaire (pas restreint à l'auteur).
     */
    public function archiver(User $authUser, string $uuid): Evenement
    {
        $evenement = $this->findByUuid($authUser, $uuid);
        $this->assertPeutGererEvenements($authUser);
        $this->assertEstUneCollecte($evenement);

        if ($evenement->statut !== Evenement::STATUT_TERMINE) {
            throw ApiException::badRequest('Seul un événement terminé peut être archivé.');
        }

        return DB::transaction(function () use ($evenement, $authUser) {
            $ancien = $evenement->statut;
            $evenement->update(['statut' => Evenement::STATUT_ARCHIVE, 'archivedAt' => now()]);
            $this->logTransition($evenement, $ancien, Evenement::STATUT_ARCHIVE, $authUser, 'archivage manuel');
            return $evenement->fresh();
        });
    }

    /**
     * Désarchive un événement (auteur uniquement, ou admin) → redevient actif.
     */
    public function desarchiver(User $authUser, string $uuid): Evenement
    {
        $evenement = $this->findByUuid($authUser, $uuid);
        $this->assertEstAuteurOuAdmin($authUser, $evenement);

        if ($evenement->statut !== Evenement::STATUT_ARCHIVE) {
            throw ApiException::badRequest('Seul un événement archivé peut être désarchivé.');
        }

        return DB::transaction(function () use ($evenement, $authUser) {
            $ancien = $evenement->statut;
            $evenement->update([
                'statut'      => Evenement::STATUT_ACTIF,
                'archivedAt'  => null,
                'termineAt'   => null,
            ]);
            $this->logTransition($evenement, $ancien, Evenement::STATUT_ACTIF, $authUser, 'désarchivage');
            return $evenement->fresh();
        });
    }

    /**
     * Archive automatiquement les événements terminés depuis plus de
     * Evenement::JOURS_AVANT_ARCHIVAGE jours (termine -> archive).
     *
     * Appelé paresseusement à chaque list()/listForGestion() (voir plus haut)
     * en plus du job planifié {@see \App\Modules\Evenement\Application\Jobs\ArchiveExpiredEvenementsJob}
     * — cette redondance garantit que la transition a bien lieu même si le
     * scheduler/worker de queue n'est pas actif (ex. environnement de dev),
     * au lieu de dépendre uniquement d'une tâche planifiée externe.
     */
    public function archiveOverdueEvenements(): void
    {
        $seuil = now()->subDays(Evenement::JOURS_AVANT_ARCHIVAGE);

        $evenements = Evenement::where('statut', Evenement::STATUT_TERMINE)
            ->whereNotNull('termineAt')
            ->where('termineAt', '<=', $seuil)
            ->get();

        foreach ($evenements as $evenement) {
            DB::transaction(function () use ($evenement) {
                $evenement->update(['statut' => Evenement::STATUT_ARCHIVE, 'archivedAt' => now()]);
                $this->logTransition(
                    $evenement,
                    Evenement::STATUT_TERMINE,
                    Evenement::STATUT_ARCHIVE,
                    null,
                    'archivage automatique (3 jours après la fin)'
                );
            });
        }
    }

    private function assertPeutGererEvenements(User $authUser): void
    {
        if ($authUser->isAdmin() || in_array($authUser->role, ['pasteur', 'secretaire'], true)) {
            return;
        }

        throw ApiException::forbidden('Seuls le pasteur et le secrétaire peuvent gérer les événements.');
    }

    /** L'événement « Dons libres » est géré automatiquement : aucune action manuelle. */
    private function assertEstUneCollecte(Evenement $evenement): void
    {
        if ($evenement->estDonLibre()) {
            throw ApiException::forbidden('L\'événement « Dons libres » est géré automatiquement.');
        }
    }

    /**
     * Toute action réservée à l'auteur (modifier, publier, terminer, archiver…)
     * est aussi refusée sur l'événement « Dons libres », jamais modifiable.
     */
    private function assertEstAuteurOuAdmin(User $authUser, Evenement $evenement): void
    {
        $this->assertEstUneCollecte($evenement);

        if ($authUser->isAdmin() || $evenement->auteurId === $authUser->id) {
            return;
        }

        throw ApiException::forbidden('Seul l\'auteur de cet événement peut effectuer cette action.');
    }

    private function logTransition(Evenement $evenement, ?string $ancien, string $nouveau, ?User $user, string $raison): void
    {
        EvenementStateTransition::create([
            'evenementId'   => $evenement->id,
            'userId'        => $user?->id,
            'ancienStatut'  => $ancien,
            'nouveauStatut' => $nouveau,
            'raison'        => $raison,
        ]);
    }
}
