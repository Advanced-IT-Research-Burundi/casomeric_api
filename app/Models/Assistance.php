<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assistance extends Model
{
    use HasFactory;

    protected $with = ['membre', 'typeAssistance'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'membre_id',
        'type_assistance_id',
        'montant',
        'date_demande',
        'date_approbation',
        'date_versement',
        'statut',
        'justificatif',
        'preuve_versement',
        'motif_rejet',
        'motif',
    ];

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
            'type_assistance_id' => 'integer',
            'montant' => 'decimal:2',
            'date_demande' => 'date',
            'date_approbation' => 'date',
            'date_versement' => 'date',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(Membre::class);
    }

    public function typeAssistance(): BelongsTo
    {
        return $this->belongsTo(TypeAssistance::class);
    }

    /*
     * Workflow (same steps as credits):
     *   en_attente -> (gestionnaire) en_cours "attente responsable" -> (responsable/admin) approuve
     *   -> (staff) verse ; rejete is possible while en_attente / en_cours.
     */
    public function isPending(): bool
    {
        return $this->statut === 'en_attente';
    }

    public function isWaitingResponsable(): bool
    {
        return $this->statut === 'en_cours';
    }

    /** Link to the supporting document, whichever place it was stored in. */
    public function getJustificatifAttribute($value): ?string
    {
        return self::fileUrl($value);
    }

    /** Link to the proof of payment (versement). */
    public function getPreuveVersementAttribute($value): ?string
    {
        return self::fileUrl($value);
    }

    /**
     * Files are now always saved under public/uploads/... ; older staff entries were
     * saved on the "public" storage disk (storage/app/public/justificatif/...).
     */
    public static function fileUrl(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        if (str_starts_with($value, 'uploads/')) {
            return asset($value);
        }

        return asset('storage/'.ltrim($value, '/'));
    }
}
