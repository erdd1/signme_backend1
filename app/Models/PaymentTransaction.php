<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modèle PaymentTransaction — Log paiements (NotchPay)
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    public const METHOD_MOBILE_MONEY = 'mobile_money';

    protected $table = 'payment_transactions';

    protected $fillable = [
        'userId',
        'churchId',
        'paymentOperator',
        'paymentNumber',
        'paymentMethod',
        'amountPerMonth',
        'monthsCount',
        'totalAmount',
        'fees',
        'amountCharged',
        'months',
        'payment_reference',
        'status',
        'signatureType',
        'payment_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amountPerMonth' => 'integer',
            'monthsCount'    => 'integer',
            'totalAmount'    => 'integer',
            'fees'           => 'integer',
            'amountCharged'  => 'integer',
            'months'         => 'array',
            'payment_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function signatures(): BelongsToMany
    {
        return $this->belongsToMany(
            Signature::class,
            'signature_payment',
            'payment_transaction_id',
            'signature_id'
        );
    }
}
