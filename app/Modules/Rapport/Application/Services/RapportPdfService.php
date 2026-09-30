<?php

namespace App\Modules\Rapport\Application\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * RapportPdfService — export PDF des rapports secrétaire et financier annuel.
 *
 * Service dédié à la présentation PDF, séparé de RapportService (logique
 * métier de calcul) — même convention que PaymentReceiptService. Réutilise
 * strictement les mêmes calculs que les endpoints JSON déjà affichés à
 * l'écran : aucune divergence possible entre ce que l'utilisateur voit et
 * ce qui est exporté.
 */
class RapportPdfService
{
    public function __construct(
        private readonly RapportService $rapportService
    ) {}

    /**
     * Rapport secrétaire d'un mois donné : montant collecté, qui a signé /
     * qui n'a pas signé (paroissiens non communiants dissociés du suivi).
     */
    public function generateSecretairePdf(User $authUser, string $monthKey): DomPdf
    {
        $data = $this->rapportService->rapportSecretaire($authUser, $monthKey);

        return Pdf::loadView('pdf.rapport-secretaire', [
            'church'     => $authUser->church,
            'data'       => $data,
            'monthLabel' => $this->monthLabel($monthKey),
        ]);
    }

    private function monthLabel(string $monthKey): string
    {
        $moisNoms = [
            1  => 'Janvier',   2  => 'Février',   3  => 'Mars',
            4  => 'Avril',     5  => 'Mai',        6  => 'Juin',
            7  => 'Juillet',   8  => 'Août',       9  => 'Septembre',
            10 => 'Octobre',   11 => 'Novembre',   12 => 'Décembre',
        ];

        [$year, $month] = explode('-', $monthKey);

        return ($moisNoms[(int) $month] ?? $monthKey) . ' ' . $year;
    }

    /**
     * Rapport financier annuel : détail des 12 mois + totaux annuels.
     */
    public function generateFinancesAnnuelPdf(User $authUser, int $year): DomPdf
    {
        $data = $this->rapportService->rapportFinancierAnnuel($authUser, $year);

        return Pdf::loadView('pdf.finances-annuel', [
            'church' => $authUser->church,
            'data'   => $data,
        ]);
    }
}
