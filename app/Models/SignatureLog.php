<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle SignatureLog — Audit/Traçabilité des signatures
 * 
 * Enregistre TOUTES les tentatives de signature:
 * - pending: Paiement initié
 * - completed: Paiement confirmé + signatures créées
 * - failed: Erreur ou paiement annulé
 */
class SignatureLog extends Model
{
    use HasFactory;

    protected $table = 'signature_logs';

    protected $fillable = [
        'userId',
        'churchId',
        'months',
        'amountPerMonth',
        'totalAmount',
        'fees',
        'paymentMethod',
        'phoneNumber',
        'signatureType',
        'status',
        'payment_reference',
        'transaction_id',
        'ancienId',
        'ancienNom',
        'errorMessage',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'months'            => 'array',
            'amountPerMonth'    => 'integer',
            'totalAmount'       => 'integer',
            'fees'              => 'integer',
            'metadata'          => 'array',
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

    public function ancien(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancienId');
    }
}
