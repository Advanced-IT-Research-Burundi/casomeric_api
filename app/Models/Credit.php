<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Credit extends Model
{
    use HasFactory;

    protected $with = ['membre'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'membre_id',
        'montant_demande',
        'montant_accorde',
        'taux_interet',
        'duree_mois',
        'montant_total_rembourser',
        'montant_mensualite',
        'date_demande',
        'date_approbation',
        'date_fin',
        'statut',
        'motif',
        'commentaire',
        'user_id',
        'created_by',
        'approved_by',
        'rejected_by'
    ];

    public static function boot()
    {
        parent::boot();
        addColumnIfNotExists('credits', 'approved_by', 'foreignId', 'deleted_at');
        // add column created_by if it doesn't exist
        static::creating(function ($credit) {
            // Custom logic before creating a Credit
            addColumnIfNotExists('credits', 'user_id', 'foreignId', 'created_by');
            addColumnIfNotExists('credits', 'created_by', 'foreignId', 'deleted_at');
            addColumnIfNotExists('credits', 'commentaire', 'text', 'deleted_at');
            addColumnIfNotExists('credits', 'rejected_by', 'foreignId', 'deleted_at');
            addColumnIfNotExists('credits', 'approved_by', 'foreignId', 'deleted_at');
        });

        static::updating(function ($credit) {
            // Custom logic before updating a Credit
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'membre_id' => 'integer',
            'montant_demande' => 'decimal:2',
            'montant_accorde' => 'decimal:2',
            'taux_interet' => 'decimal:2',
            'montant_total_rembourser' => 'decimal:2',
            'montant_mensualite' => 'decimal:2',
            'date_demande' => 'date',
            'date_approbation' => 'date',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(Membre::class);
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class);
    }

    /**
     * Single source of truth for a credit's cost (simple interest, same formula as the UI):
     *   intérêt     = capital × taux × durée / (12 × 100)   (taux = % par an)
     *   total       = capital + intérêt                      (arrondi au franc)
     *   mensualité  = total / durée                          (arrondi au franc)
     * The last installment absorbs the rounding difference (CreditController::generateEcheances).
     *
     * @return array{montant_interet: float, montant_total_rembourser: float, montant_mensualite: float}
     */
    public static function calculerRemboursement(float $capital, float $tauxAnnuel, int $dureeMois): array
    {
        $dureeMois = max(1, $dureeMois);
        $interet = $capital * $tauxAnnuel * $dureeMois / (12 * 100);
        $total = round($capital + $interet);

        return [
            'montant_interet' => round($interet),
            'montant_total_rembourser' => $total,
            'montant_mensualite' => round($total / $dureeMois),
        ];
    }

    /** Default yearly rate from the configuration (taux_interet_credit), 3 % if not set. */
    public static function tauxParDefaut(): float
    {
        $valeur = Configuration::where('cle', 'taux_interet_credit')->value('valeur');

        return is_numeric($valeur) ? (float) $valeur : 3.0;
    }

    /** Capital the interest is computed on: the granted amount once set, otherwise the requested one. */
    public function capital(): float
    {
        return (float) ($this->montant_accorde > 0 ? $this->montant_accorde : $this->montant_demande);
    }

    /** Recompute total and monthly payment from capital, rate and duration (not saved). */
    public function recalculer(): static
    {
        $this->fill(array_intersect_key(
            self::calculerRemboursement($this->capital(), (float) $this->taux_interet, (int) $this->duree_mois),
            array_flip(['montant_total_rembourser', 'montant_mensualite'])
        ));

        return $this;
    }

    /*
     * Workflow helpers (same rules as the credits list in the UI).
     * "en_cours" is used both for "sent to the responsable" (no approval date yet)
     * and for "being repaid" (approval date set).
     */

    /** Requested, nothing decided yet. */
    public function isPending(): bool
    {
        return $this->statut === 'en_attente';
    }

    /** Approved by a gestionnaire, waiting for the responsable's final approval. */
    public function isWaitingResponsable(): bool
    {
        return $this->statut === 'en_cours' && empty($this->date_approbation);
    }

    /** Granted and not fully repaid yet. */
    public function isInRepayment(): bool
    {
        return $this->statut === 'approuve'
            || ($this->statut === 'en_cours' && !empty($this->date_approbation));
    }
}
