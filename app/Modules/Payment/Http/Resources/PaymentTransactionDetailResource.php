<?php

namespace App\Modules\Payment\Http\Resources;

use App\Core\Helpers\PhoneMasker;
use App\Models\PaymentTransaction;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentTransactionDetailResource — vue détaillée admin d'une PaymentTransaction
 * (11 champs), distincte de PaymentResource (contrat mobile existant, jamais
 * modifié). `map()` est statique et réutilisée telle quelle par
 * PaymentReceiptService pour générer le PDF — aucune divergence possible
 * entre ce que l'admin voit à l'écran et ce qui est exporté.
 */
class PaymentTransactionDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return self::map($this->resource);
    }

    public static function map(PaymentTransaction $transaction): array
    {
        $metadata = $transaction->payment_metadata ?? [];

        return [
            'id'                   => $transaction->id,
            'referenceInterne'     => 'SGN-' . str_pad((string) $transaction->id, 8, '0', STR_PAD_LEFT),
            'referenceNotchPay'    => $transaction->payment_reference,
            'transactionIdGateway' => data_get($metadata, 'transaction.id') ?? data_get($metadata, 'reference'),
            'createdAt'            => $transaction->created_at?->toISOString(),
            'confirmedAt'          => $transaction->confirmed_at?->toISOString(),
            'paymentMethod'        => $transaction->paymentMethod,
            'paymentOperator'      => $transaction->paymentOperator,
            'paymentNumberMasked'  => PhoneMasker::mask($transaction->paymentNumber),
            'fees'                 => $transaction->fees,
            'totalAmount'          => $transaction->totalAmount,
            'amountCharged'        => $transaction->amountCharged,
            // "Montant net" = montant réellement débité au client (décision
            // validée) — PAS totalAmount, qui exclut les frais.
            'netAmount'            => $transaction->amountCharged,
            'status'               => $transaction->status,
            'lastWebhookPayload'   => $metadata,
        ];
    }
}
