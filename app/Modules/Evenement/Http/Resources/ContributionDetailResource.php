<?php

namespace App\Modules\Evenement\Http\Resources;

use App\Core\Helpers\PhoneMasker;
use App\Models\Contribution;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ContributionDetailResource — vue détaillée admin d'une Contribution
 * (11 champs), distincte de ContributionResource (contrat mobile existant,
 * jamais modifié). `map()` est statique et réutilisée telle quelle par
 * PaymentReceiptService pour générer le PDF — aucune divergence possible
 * entre ce que l'admin voit à l'écran et ce qui est exporté.
 */
class ContributionDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return self::map($this->resource);
    }

    public static function map(Contribution $contribution): array
    {
        $metadata = $contribution->payment_metadata ?? [];

        return [
            'id'                   => $contribution->id,
            'referenceInterne'     => 'CTB-' . $contribution->uuid,
            'referenceNotchPay'    => $contribution->payment_reference,
            'transactionIdGateway' => data_get($metadata, 'transaction.id') ?? data_get($metadata, 'reference'),
            'createdAt'            => $contribution->created_at?->toISOString(),
            'confirmedAt'          => $contribution->confirmed_at?->toISOString(),
            'paymentMethod'        => $contribution->paymentMethod,
            'paymentOperator'      => $contribution->paymentOperator,
            'paymentNumberMasked'  => PhoneMasker::mask($contribution->paymentNumber),
            'fees'                 => $contribution->frais,
            'totalAmount'          => $contribution->montant,
            'amountCharged'        => $contribution->montantTotal,
            // "Montant net" = montant réellement débité au client (décision
            // validée) — PAS montant, qui exclut les frais.
            'netAmount'            => $contribution->montantTotal,
            'status'               => $contribution->statut,
            'lastWebhookPayload'   => $metadata,
        ];
    }
}
