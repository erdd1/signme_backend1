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
        'groupeCollecteId',
        'communiqueId',
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

    /**
     * Paiement par carte déclaré échoué PAR EXPIRATION (aucune confirmation à
     * temps) : le lien de paiement peut encore être payé après coup, ce
     * paiement doit alors être rattrapé — jamais perdu.
     */
    public function estExpiree(): bool
    {
        return $this->statut === self::STATUT_FAILED
            && $this->paymentGateway === self::GATEWAY_FLUTTERWAVE
            && ($this->payment_metadata['expired'] ?? false) === true;
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

    /** Groupe DESTINATAIRE de l'argent (caisse de groupe) — distinct de `groupe`. */
    public function groupeCollecte(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupeCollecteId');
    }

    public function communique(): BelongsTo
    {
        return $this->belongsTo(GroupeCommunique::class, 'communiqueId');
    }

    public function estVisibleNotePar(User $user): bool
    {
        return $this->userId === $user->id
            || $user->isAdmin()
            || in_array($user->role, self::ROLES_GESTION_FINANCIERE, true);
    }
}
