<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /** A autorizacao vive no middleware `workspace:editor`. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'segment' => ['nullable', 'string', 'max:80'],
            // Omitido = America/Sao_Paulo (default da coluna). E o fuso em que o
            // social_media agenda: o horario do publico da marca.
            'timezone' => ['sometimes', 'timezone'],
            'description' => ['nullable', 'string'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'archived'])],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
