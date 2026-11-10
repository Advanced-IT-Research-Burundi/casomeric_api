<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssistanceStoreRequest extends FormRequest
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
        // Statut et dates de décision : ignorés ici, ils changent par approuver / refuser / verser.
        return [
            'membre_id' => ['required', 'integer', 'exists:membres,id'],
            'type_assistance_id' => ['required', 'integer', 'exists:type_assistances,id'],
            'montant' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'date_demande' => ['nullable', 'date'],
            'motif' => ['nullable', 'string', 'max:2000'],
            'justificatif' => ['required', 'file', 'mimes:jpeg,png,jpg,webp,pdf', 'max:5120'],
            'date_approbation' => ['nullable', 'date'],
            'date_versement' => ['nullable', 'date'],
            'statut' => ['nullable', 'in:en_attente,en_cours,approuve,rejete,verse'],
            'motif_rejet' => ['nullable', 'string'],
        ];
    }
}
