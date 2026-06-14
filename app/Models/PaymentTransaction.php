<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modèle PaymentTransaction — Log paiements Campay
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'userId',
        'churchId',
        'paymentOperator',
        'paymentNumber',
        'amountPerMonth',
        'monthsCount',
        'totalAmount',
        'fees',
        'amountCharged',
        'months',
        'campay_reference',
        'status',
        'signatureType',
        'campay_metadata',
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
            'campay_metadata' => 'array',
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
