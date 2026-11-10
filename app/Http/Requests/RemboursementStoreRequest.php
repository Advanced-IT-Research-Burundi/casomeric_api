<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual repayment: an amount paid on a credit. The schedule, the amount still
 * owed and the status are updated by RemboursementService, not sent by the form.
 */
class RemboursementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credit_id' => ['required', 'integer', 'exists:credits,id'],
            'montant_paye' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'date_paiement' => ['nullable', 'date'],
            'preuve_paiement' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'credit_id.required' => 'Choisissez le crédit à rembourser.',
            'montant_paye.required' => 'Le montant payé est obligatoire.',
            'montant_paye.min' => 'Le montant payé doit être supérieur à 0.',
        ];
    }
}
