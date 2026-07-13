<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    /** A autorizacao vive no controller: `Gate::authorize('update', $project)`. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Merge parcial: manda-se so o que muda.
     *
     * `owner_user_id` e `status` ficam DE FORA de proposito. Trocar o dono e mover um
     * projeto para arquivado nao sao "editar o projeto" — sao gestos com consequencia
     * propria (quem pode fazer? o que acontece com o conteudo agendado?), e um PATCH
     * generico os esconderia atras de um campo. Quando existirem, existirao com nome.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'company' => ['sometimes', 'nullable', 'string', 'max:160'],
            'segment' => ['sometimes', 'nullable', 'string', 'max:80'],
            // O motivo desta rota existir. O fuso e do PROJETO (uma agencia atende
            // marcas em fusos diferentes) e so podia ser dito na CRIACAO — todo
            // projeto existente ficou preso no default, sem tela para corrigir, e o
            // social_media agenda nele: errar o fuso e publicar na madrugada.
            'timezone' => ['sometimes', 'timezone'],
            'description' => ['sometimes', 'nullable', 'string'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
