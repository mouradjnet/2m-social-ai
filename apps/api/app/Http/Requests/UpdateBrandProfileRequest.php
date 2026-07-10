<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Merge parcial: o wizard envia so os campos do passo atual. Por isso todos os
 * campos sao `sometimes` — e por isso a rota e PATCH, nao PUT. Um PUT exigiria
 * o payload completo, que o wizard nunca tem antes do passo 4.
 */
class UpdateBrandProfileRequest extends FormRequest
{
    /** Autorizacao fica na ProjectPolicy, via Gate no controller. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $url = ['nullable', 'url:https', 'max:255'];

        return [
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'products' => ['sometimes', 'array'],
            'products.*' => ['string', 'max:120'],
            'services' => ['sometimes', 'array'],
            'services.*' => ['string', 'max:120'],

            'audience' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'tone_of_voice' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'differentiators' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'competitors' => ['sometimes', 'array'],
            'competitors.*.name' => ['required', 'string', 'max:120'],
            'competitors.*.url' => ['nullable', 'url:https', 'max:255'],
            'required_words' => ['sometimes', 'array'],
            'required_words.*' => ['string', 'max:60'],
            'forbidden_words' => ['sometimes', 'array'],
            'forbidden_words.*' => ['string', 'max:60'],

            'website' => ['sometimes', ...$url],
            'instagram' => ['sometimes', ...$url],
            'facebook' => ['sometimes', ...$url],
            'linkedin' => ['sometimes', ...$url],
            'tiktok' => ['sometimes', ...$url],
            'youtube' => ['sometimes', ...$url],

            'colors' => ['sometimes', 'array'],
            'colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
