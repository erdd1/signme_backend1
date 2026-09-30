<?php

namespace App\Modules\Evenement\Application\Services;

use App\Models\Evenement;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * ContributionPdfExportService — export PDF des contributions événement
 * (module "Contributions événement" — pasteur/trésorier uniquement).
 *
 * Statistiques pour un événement précis (filtre event_id) ou globales (tous
 * événements confondus) selon les mêmes filtres que le tableau de bord
 * mobile — même convention de séparation présentation/métier que
 * PaymentReceiptService.
 */
class ContributionPdfExportService
{
    public function __construct(
        private readonly ContributionService $contributionService
    ) {}

    public function generate(User $authUser, array $filters = []): DomPdf
    {
        $stats = $this->contributionService->getTreasuryStats($authUser, $filters);
        $contributions = $this->contributionService->listAllForTreasuryExport($authUser, $filters);

        $evenement = !empty($filters['event_id'])
            ? Evenement::find((int) $filters['event_id'])
            : null;

        return Pdf::loadView('pdf.contributions-evenement', [
            'church'        => $authUser->church,
            'evenementNom'  => $evenement?->titre,
            'stats'         => $stats,
            'contributions' => $contributions,
        ])->setPaper('a4', 'landscape');
    }
}
