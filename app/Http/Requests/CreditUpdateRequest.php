<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreditUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'membre_id' => ['required', 'integer', 'exists:membres,id'],
            'montant_demande' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'montant_accorde' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'taux_interet' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:120'],
            // Calculés par le serveur (Credit::calculerRemboursement) : valeur envoyée ignorée.
            'montant_total_rembourser' => ['nullable', 'numeric'],
            'montant_mensualite' => ['nullable', 'numeric'],
            'date_demande' => ['required', 'date'],
            'date_approbation' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
            // Ignorés à la modification : le statut change seulement par approuver / refuser.
            'statut' => ['nullable', 'in:en_attente,approuve,rejete,en_cours,termine'],
            'motif' => ['required', 'string'],
            'commentaire' => ['nullable', 'string'],
        ];
    }
}
