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
 * Gère cinq types de badges (onglets du fil d'actualité Home + Historique) :
 *  - annonces              : Annonces de l'église non lues (onglet Home)
 *  - meditations            : Méditations de l'église non lues (onglet Home)
 *  - evenements             : Événements de l'église non lus (onglet Home)
 *  - historique             : Signatures completed de l'utilisateur depuis sa dernière visite
 *  - historique_signatures  : Signatures effectuées PAR l'utilisateur (ancien/pasteur) depuis sa dernière visite
 */
class BadgeService
{
    public const TYPE_ANNONCES                 = 'annonces';
    public const TYPE_MEDITATIONS               = 'meditations';
    public const TYPE_EVENEMENTS               = 'evenements';
    public const TYPE_HISTORIQUE               = 'historique';
    public const TYPE_HISTORIQUE_SIGNATURES    = 'historique_signatures';

    /**
     * Retourne les compteurs de badges pour l'utilisateur connecté.
     *
     * @return array{annonces: int, meditations: int, evenements: int, historique: int, historique_signatures: int}
     */
    public function getBadgeCounts(User $user): array
    {
        return [
            self::TYPE_ANNONCES              => $this->countUnreadPublications($user, Publication::TYPE_ANNONCE),
            self::TYPE_MEDITATIONS            => $this->countUnreadPublications($user, Publication::TYPE_MEDITATION),
            self::TYPE_EVENEMENTS             => $this->countUnreadEvenements($user),
            self::TYPE_HISTORIQUE            => $this->countUnreadHistorique($user),
            self::TYPE_HISTORIQUE_SIGNATURES => $this->countUnreadHistoriqueSignatures($user),
        ];
    }

    /**
     * Marque un type de badge comme lu pour l'utilisateur.
     *
     * @param string $type annonces | meditations | evenements | historique | historique_signatures
     */
    public function markAsRead(User $user, string $type): void
    {
        match ($type) {
            self::TYPE_ANNONCES              => $this->markPublicationsAsRead($user, Publication::TYPE_ANNONCE),
            self::TYPE_MEDITATIONS            => $this->markPublicationsAsRead($user, Publication::TYPE_MEDITATION),
            self::TYPE_EVENEMENTS             => $this->markEvenementsAsRead($user),
            self::TYPE_HISTORIQUE            => $this->markHistoriqueAsRead($user),
            self::TYPE_HISTORIQUE_SIGNATURES => $this->markHistoriqueSignaturesAsRead($user),
            default                          => null,
        };
    }

    // ─── Publications (Annonces / Méditations) ─────────────────────────────────

    /**
     * Compte les publications d'un type donné non lues de l'église de l'utilisateur.
     * "Non lue" = aucun enregistrement dans publication_reads pour ce (userId, publicationId).
     */
    private function countUnreadPublications(User $user, string $type): int
    {
        if ($user->isAdmin()) {
            return 0; // L'admin ne voit pas de badge publication
        }

        // IDs des publications déjà lues
        $readIds = PublicationRead::where('userId', $user->id)
            ->pluck('publicationId');

        return Publication::where('churchId', $user->churchId)
            ->where('type', $type)
            ->whereNotIn('id', $readIds)
            ->count();
    }

    /**
     * Insère un enregistrement publication_reads pour chaque publication d'un
     * type donné non encore lue par cet utilisateur.
     */
    private function markPublicationsAsRead(User $user, string $type): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $unreadIds = Publication::where('churchId', $user->churchId)
            ->where('type', $type)
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
