<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MembreStoreRequest extends FormRequest
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
        $createUser = $this->boolean('create_user');

        return [
            'matricule' => ['required', 'string', 'max:50', 'unique:membres,matricule'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => $createUser
                ? ['required', 'email', 'max:150', 'unique:users,email']
                : ['required', 'email', 'max:150'],
            'telephone' => ['required', 'string', 'max:20'],
            'categorie_id' => ['required', 'exists:categorie_membres,id'],
            'statut' => ['required', 'in:actif,inactif,suspendu'],
            'date_adhesion' => ['required', 'date'],
            'create_user' => ['sometimes', 'boolean'],
        ];
    }
}
