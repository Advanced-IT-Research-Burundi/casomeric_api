<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membre extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'matricule',
        'nom',
        'prenom',
        'email',
        'telephone',
        'categorie_id',
        'statut',
        'date_adhesion',
    ];

    protected $appends = ['full_name'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'categorie_id' => 'integer',
            'date_adhesion' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieMembre::class);
    }

    public function cotisations()
    {
        return $this->hasMany(Cotisation::class);
    }

    public function credits()
    {
        return $this->hasMany(Credit::class);
    }

    public function assistances()
    {
        return $this->hasMany(Assistance::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->nom} {$this->prenom}";
    }
}
