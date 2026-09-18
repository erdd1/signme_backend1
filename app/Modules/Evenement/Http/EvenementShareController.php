<?php

namespace App\Modules\Evenement\Http;

use App\Models\Evenement;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * EvenementShareController — page de partage publique d'un événement.
 *
 * Sert la même URL que le lien partagé (WhatsApp, etc.) et que le lien
 * intercepté par Android App Links / iOS Universal Links :
 *   https://<domaine>/evenements/{uuid}
 *
 * - Toujours accessible sans authentification (nécessaire pour que les
 *   robots d'aperçu WhatsApp/Instagram/LinkedIn/Facebook puissent lire les
 *   balises Open Graph, et pour inviter des personnes qui n'ont pas encore
 *   l'app).
 * - N'expose que des informations non sensibles (titre, description, image,
 *   dates, église) — jamais les montants/contributions.
 * - Si l'app est installée et le domaine vérifié, l'OS n'affiche même
 *   jamais cette page : il ouvre l'app directement sur l'événement. Cette
 *   page n'est vue que si l'app n'est pas installée, la vérification n'a
 *   pas encore eu lieu, ou sur un navigateur desktop.
 */
class EvenementShareController
{
    public function show(string $uuid): View
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée (ex: lien de
        // partage corrompu/deviné) — voir le commentaire équivalent dans
        // SortieFinanciereService::findByUuid(). Comportement identique à
        // "aucune correspondance" : $evenement reste null, la vue gère déjà
        // ce cas.
        $evenement = Str::isUuid($uuid)
            ? Evenement::with('church:id,nom')
                ->where('uuid', $uuid)
                ->whereIn('statut', [Evenement::STATUT_ACTIF, Evenement::STATUT_TERMINE])
                ->first()
            : null;

        return view('evenements.share', [
            'evenement' => $evenement,
            'shareUrl'  => url("/evenements/{$uuid}"),
        ]);
    }
}
