<?php

namespace App\Modules\Badge\Application\Services;

use App\Models\Evenement;
use App\Models\Publication;
use App\Models\PublicationRead;
use App\Models\Signature;
use App\Models\User;

/**
 * BadgeService — Calcul des compteurs de badges non lus
 *
 * Gère quatre types de badges :
 *  - publications       : Publications de l'église non lues par l'utilisateur (onglet Home)
 *  - evenements         : Événements de l'église non lus par l'utilisateur (onglet Home, combiné avec publications)
 *  - historique         : Signatures completed de l'utilisateur depuis sa dernière visite (onglet Historique)
 *  - historique_signatures : Signatures effectuées PAR l'utilisateur (ancien/pasteur) depuis sa dernière visite
 */
class BadgeService
{
    public const TYPE_PUBLICATIONS             = 'publications';
    public const TYPE_EVENEMENTS               = 'evenements';
    public const TYPE_HISTORIQUE               = 'historique';
    public const TYPE_HISTORIQUE_SIGNATURES    = 'historique_signatures';

    /**
     * Retourne les compteurs de badges pour l'utilisateur connecté.
     *
     * @return array{publications: int, evenements: int, historique: int, historique_signatures: int}
     */
    public function getBadgeCounts(User $user): array
    {
        return [
            self::TYPE_PUBLICATIONS          => $this->countUnreadPublications($user),
            self::TYPE_EVENEMENTS             => $this->countUnreadEvenements($user),
            self::TYPE_HISTORIQUE            => $this->countUnreadHistorique($user),
            self::TYPE_HISTORIQUE_SIGNATURES => $this->countUnreadHistoriqueSignatures($user),
        ];
    }

    /**
     * Marque un type de badge comme lu pour l'utilisateur.
     *
     * @param string $type publications | evenements | historique | historique_signatures
     */
    public function markAsRead(User $user, string $type): void
    {
        match ($type) {
            self::TYPE_PUBLICATIONS          => $this->markPublicationsAsRead($user),
            self::TYPE_EVENEMENTS             => $this->markEvenementsAsRead($user),
            self::TYPE_HISTORIQUE            => $this->markHistoriqueAsRead($user),
            self::TYPE_HISTORIQUE_SIGNATURES => $this->markHistoriqueSignaturesAsRead($user),
            default                          => null,
        };
    }

    // ─── Publications ─────────────────────────────────────────────────────────

    /**
     * Compte les publications non lues de l'église de l'utilisateur.
     * "Non lue" = aucun enregistrement dans publication_reads pour ce (userId, publicationId).
     */
    private function countUnreadPublications(User $user): int
    {
        if ($user->isAdmin()) {
            return 0; // L'admin ne voit pas de badge publication
        }

        // IDs des publications déjà lues
        $readIds = PublicationRead::where('userId', $user->id)
            ->pluck('publicationId');

        return Publication::where('churchId', $user->churchId)
            ->whereNotIn('id', $readIds)
            ->count();
    }

    /**
     * Insère un enregistrement publication_reads pour chaque publication
     * non encore lue par cet utilisateur.
     */
    private function markPublicationsAsRead(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        // Publications de l'église non encore lues
        $unreadIds = Publication::where('churchId', $user->churchId)
            ->whereNotIn('id', function ($query) use ($user) {
                $query->select('publicationId')
                    ->from('publication_reads')
                    ->where('userId', $user->id);
            })
            ->pluck('id');

        if ($unreadIds->isEmpty()) {
            return;
        }

        $now    = now();
        $rows   = $unreadIds->map(fn($pubId) => [
            'userId'        => $user->id,
            'publicationId' => $pubId,
            'readAt'        => $now,
        ])->all();

        // insertOrIgnore évite les doublons en cas d'appels concurrents
        PublicationRead::insertOrIgnore($rows);
    }

    // ─── Événements ─────────────────────────────────────────────────────────

    /**
     * Compte les événements de l'église (actif/terminé, comme le fil
     * d'actualité — cf. EvenementService::list()) publiés après la dernière
     * visite de l'utilisateur, en excluant ses propres événements (un auteur
     * ne doit pas voir un badge pour son propre contenu).
     */
    private function countUnreadEvenements(User $user): int
    {
        if ($user->isAdmin()) {
            return 0; // Cohérent avec le badge publications : pas de badge pour l'admin
        }

        $query = Evenement::where('churchId', $user->churchId)
            ->whereIn('statut', [Evenement::STATUT_ACTIF, Evenement::STATUT_TERMINE])
            ->whereNotNull('publishedAt')
            ->where('auteurId', '!=', $user->id);

        if ($user->last_read_evenements_at !== null) {
            $query->where('publishedAt', '>', $user->last_read_evenements_at);
        }

        return $query->count();
    }

    /**
     * Met à jour last_read_evenements_at = now() pour l'utilisateur.
     */
    private function markEvenementsAsRead(User $user): void
    {
        $user->update(['last_read_evenements_at' => now()]);
    }

    // ─── Historique Personnel ─────────────────────────────────────────────────

    /**
     * Compte les signatures completed de l'utilisateur
     * créées après sa dernière visite de l'onglet Historique.
     */
    private function countUnreadHistorique(User $user): int
    {
        $query = Signature::where('userId', $user->id)
            ->where('status', Signature::STATUS_COMPLETED);

        if ($user->last_read_historique_at !== null) {
            $query->where('created_at', '>', $user->last_read_historique_at);
        }

        return $query->count();
    }

    /**
     * Met à jour last_read_historique_at = now() pour l'utilisateur.
     */
    private function markHistoriqueAsRead(User $user): void
    {
        $user->update(['last_read_historique_at' => now()]);
    }

    // ─── Historique Signatures (Ancien/Pasteur) ───────────────────────────────

    /**
     * Compte les signatures effectuées PAR l'utilisateur (en tant qu'ancien/pasteur)
     * créées après sa dernière visite de l'onglet Historique Signatures.
     * Retourne 0 si l'utilisateur n'est ni ancien ni pasteur.
     */
    private function countUnreadHistoriqueSignatures(User $user): int
    {
        if (!$user->isAncien() && !$user->isPasteur()) {
            return 0;
        }

        $query = Signature::where('ancienId', $user->id);

        if ($user->last_read_historique_signatures_at !== null) {
            $query->where('created_at', '>', $user->last_read_historique_signatures_at);
        }

        return $query->count();
    }

    /**
     * Met à jour last_read_historique_signatures_at = now() pour l'utilisateur.
     */
    private function markHistoriqueSignaturesAsRead(User $user): void
    {
        $user->update(['last_read_historique_signatures_at' => now()]);
    }

    /**
     * Marque les publications d'une église comme non lues pour tous les utilisateurs
     * sauf l'auteur. Appelé après création d'une nouvelle publication.
     *
     * Note : on n'insère rien ici — le badge se calcule via l'absence de lecture.
     * On insère juste l'entrée pour l'auteur (pour qu'il ne voie pas son propre badge).
     */
    public function markPublicationReadByAuthor(int $publicationId, int $authorId): void
    {
        PublicationRead::insertOrIgnore([
            'userId'        => $authorId,
            'publicationId' => $publicationId,
            'readAt'        => now(),
        ]);
    }
}
