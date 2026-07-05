<?php

namespace App\Modules\Payment\Application\Services;

use App\Models\Contribution;
use App\Models\PaymentEvent;
use App\Models\PaymentTransaction;
use App\Modules\Evenement\Http\Resources\ContributionDetailResource;
use App\Modules\Payment\Http\Resources\PaymentTransactionDetailResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * PaymentReceiptService — génère le PDF de reçu/export (même document pour
 * les deux actions, seul le Content-Disposition diffère). Réutilise
 * strictement les mêmes mappings que PaymentTransactionDetailResource /
 * ContributionDetailResource (`::map()`) : aucune divergence possible entre
 * ce que l'admin voit à l'écran et ce qui est exporté.
 */
class PaymentReceiptService
{
    public function generateForPaymentTransaction(PaymentTransaction $transaction): DomPdf
    {
        $detail = PaymentTransactionDetailResource::map($transaction);
        $events = PaymentEvent::where('payment_transaction_id', $transaction->id)
            ->orderBy('created_at')
            ->get();

        return $this->render('signature', $detail, $events);
    }

    public function generateForContribution(Contribution $contribution): DomPdf
    {
        $detail = ContributionDetailResource::map($contribution);
        $events = PaymentEvent::where('contribution_id', $contribution->id)
            ->orderBy('created_at')
            ->get();

        return $this->render('contribution', $detail, $events);
    }

    private function render(string $type, array $detail, $events): DomPdf
    {
        return Pdf::loadView('pdf.payment-receipt', [
            'type'   => $type,
            'detail' => $detail,
            'events' => $events,
        ]);
    }
}
