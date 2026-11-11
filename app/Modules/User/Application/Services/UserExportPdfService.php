<?php

namespace App\Modules\User\Application\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * UserExportPdfService — export PDF de la liste des membres d'une église
 * (module "Gérer utilisateurs" — pasteur/secrétaire uniquement).
 *
 * Service dédié à la présentation PDF, séparé de UserService (logique
 * métier CRUD) — même convention que PaymentReceiptService.
 */
class UserExportPdfService
{
    /**
     * Génère le PDF de la liste complète des membres de l'église de
     * l'utilisateur connecté (tous rôles, actifs et inactifs).
     */
    public function generate(User $authUser): DomPdf
    {
        $membres = User::with(['groupes', 'quartier', 'church'])
            ->where('churchId', $authUser->churchId)
            ->orderBy('nom')
            ->get();

        return Pdf::loadView('pdf.users-list', [
            'church'  => $authUser->church,
            'membres' => $membres,
            'total'   => $membres->count(),
        ])->setPaper('a4', 'landscape');
    }
}
