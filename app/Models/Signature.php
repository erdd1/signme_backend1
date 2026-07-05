<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modèle Signature — EEC SignCard
 *
 * Fusion des collections Firestore : gifts + signatures
 * Représente une signature de carte Sainte Cène (présentiel ou à distance).
 *
 * @property int         $id
 * @property int         $userId
 * @property string      $nomParoissien
 * @property int         $churchId
 * @property int         $monthIndex       0 à 11
 * @property string      $month            YYYY-MM
 * @property int         $year
 * @property int         $amount           FCFA
 * @property int         $fees             0 si présentiel
 * @property string|null $paymentMethod
 * @property string|null $phoneNumber
 * @property string      $signatureType    presentiel|distance
 * @property string      $status           pending|completed|failed
 * @property int|null    $ancienId
 * @property string|null $ancienNom
 * @property string|null $payment_reference
 */
class Signature extends Model
{
    use HasFactory;

    public const TYPE_PRESENTIEL = 'presentiel';
    public const TYPE_DISTANCE   = 'distance';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const PAYMENT_ESPECES     = 'ESPECES';
    public const PAYMENT_ORANGE      = 'ORANGE MONEY';
    public const PAYMENT_MTN         = 'MTN MOBILE MONEY';

    protected $fillable = [
        'userId',
        'nomParoissien',
        'churchId',
        'monthIndex',
        'month',
        'year',
        'amount',
        'fees',
        'paymentMethod',
        'phoneNumber',
        'signatureType',
        'status',
        'ancienId',
        'ancienNom',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'integer',
            'fees'       => 'integer',
            'monthIndex' => 'integer',
            'year'       => 'integer',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

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

    public function paymentTransactions(): BelongsToMany
    {
        return $this->belongsToMany(
            PaymentTransaction::class,
            'signature_payment',
            'signature_id',
            'payment_transaction_id'
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getTotalAttribute(): int
    {
        return $this->amount + $this->fees;
    }
}
