<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SortieFinanciere extends Model {
    const TYPE_SIMPLE = 'simple';
    const TYPE_FRAIS  = 'frais';
    const STATUT_PENDING  = 'pending';
    const STATUT_APPROVED = 'approved';
    const STATUT_REJECTED = 'rejected';

    protected $table = 'sorties_financieres';
    protected $fillable = [
        'churchId', 'userId', 'type', 'statut', 'montant', 'description',
        'reference', 'motif_rejet', 'validated_by', 'validated_at', 'solde_apres',
    ];
    protected $casts = [
        'montant'      => 'integer',
        'validated_at' => 'datetime',
        'solde_apres'  => 'integer',
    ];

    protected static function booted(): void {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    // Relations
    public function church()    { return $this->belongsTo(Church::class,  'churchId'); }
    public function auteur()    { return $this->belongsTo(User::class,    'userId'); }
    public function validatedBy() { return $this->belongsTo(User::class,  'validated_by'); }

    // Scopes
    public function scopeApproved($q) { return $q->where('statut', self::STATUT_APPROVED); }
    public function scopePending($q)  { return $q->where('statut', self::STATUT_PENDING); }
    public function scopeForChurch($q, int $churchId) { return $q->where('churchId', $churchId); }

    // Helpers business
    public function isSimple(): bool  { return $this->type   === self::TYPE_SIMPLE; }
    public function isFrais(): bool   { return $this->type   === self::TYPE_FRAIS; }
    public function isPending(): bool { return $this->statut === self::STATUT_PENDING; }
    public function isApproved(): bool{ return $this->statut === self::STATUT_APPROVED; }
    public function isRejected(): bool{ return $this->statut === self::STATUT_REJECTED; }

    /**
     * Une sortie peut être supprimée si :
     *  - type simple + statut approved (CRUD libre)
     *  - type frais + statut pending (annulation avant validation admin)
     */
    public function isDeletableBy(User $user): bool {
        return $this->userId === $user->id &&
               (($this->isSimple() && $this->isApproved()) || ($this->isFrais() && $this->isPending()));
    }

    /** Seule une sortie simple approved peut être modifiée. */
    public function isEditableBy(User $user): bool {
        return $this->userId === $user->id && $this->isSimple() && $this->isApproved();
    }
}
