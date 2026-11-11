<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Contribution — Contribution financière d'un paroissien à un Evenement.
 *
 * Aucune route update/destroy exposée : une fois `completed`, les montants
 * ne doivent plus jamais être modifiés (immutabilité financière).
 */
class Contribution extends Model
{
    use HasFactory;

    public const STATUT_PENDING   = 'pending';
    public const STATUT_COMPLETED = 'completed';
    public const STATUT_FAILED    = 'failed';

    public const METHOD_MOBILE_MONEY = 'mobile_money';
    public const METHOD_CARD         = 'card';

    public const GATEWAY_NOTCHPAY    = 'notchpay';
    public const GATEWAY_FLUTTERWAVE = 'flutterwave';

    public const STATUTS = [
        self::STATUT_PENDING,
        self::STATUT_COMPLETED,
        self::STATUT_FAILED,
    ];

    /** Rôles autorisés à voir la note et le détail complet d'une contribution */
    public const ROLES_GESTION_FINANCIERE = ['pasteur', 'secretaire', 'tresorier'];

    protected $fillable = [
        'uuid',
        'evenementId',
        'userId',
        'groupeId',
        'montant',
        'frais',
        'montantTotal',
        'note',
        'paymentOperator',
        'paymentNumber',
        'paymentMethod',
        'paymentGateway',
        'payment_reference',
        'statut',
        'payment_metadata',
    ];

    protected function casts(): array
    {
        return [
            'montant'          => 'integer',
            'frais'            => 'integer',
            'montantTotal'     => 'integer',
            'payment_metadata'  => 'array',
        ];
    }

    public function evenement(): BelongsTo
    {
        return $this->belongsTo(Evenement::class, 'evenementId');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupeId');
    }

    public function estVisibleNotePar(User $user): bool
    {
        return $this->userId === $user->id
            || $user->isAdmin()
            || in_array($user->role, self::ROLES_GESTION_FINANCIERE, true);
    }
}
