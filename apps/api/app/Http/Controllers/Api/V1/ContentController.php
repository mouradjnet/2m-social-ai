<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContentController extends Controller
{
    /**
     * O fluxo linear que o humano percorre. `scheduled` NAO entra aqui: agendar e do
     * social_media, porque exige uma data, e este PATCH so carrega status — se
     * `scheduled` fosse o quinto elemento, o botao "Avancar" de uma peca aprovada
     * habilitaria no board e o clique tomaria 422. `published` exige publicar, que
     * nao existe. `archived` e terminal, alcancavel de qualquer estado ativo.
     */
    private const FLOW = ['idea', 'production', 'review', 'approved'];

    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => $project->contents()->latest()->get(),
        ]);
    }

    public function update(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate(['status' => ['required', 'string']]);
        $from = $content->status;
        $to = $data['status'];

        if (! $this->isValidTransition($from, $to)) {
            return response()->json([
                'message' => "Transicao invalida de '{$from}' para '{$to}'.",
            ], 422);
        }

        DB::transaction(function () use ($content, $from, $to) {
            $content->update([
                'status' => $to,
                // Desagendar limpa a data: sem isto a peca voltaria para Aprovado
                // carregando uma data fantasma, e o board a mostraria agendada.
                ...$this->isDesagendamento($from, $to) ? ['scheduled_for' => null] : [],
            ]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'from_status' => $from,
                'to_status' => $to,
            ]);
        });

        return response()->json(['data' => $content->refresh()]);
    }

    /**
     * Arquivar de qualquer estado ativo, desagendar, ou mover +-1 passo no fluxo
     * linear. Agendar (`approved -> scheduled`) nao esta aqui de proposito: e o
     * social_media que agenda.
     */
    private function isValidTransition(string $from, string $to): bool
    {
        if ($to === 'archived') {
            return $from !== 'archived';
        }

        if ($this->isDesagendamento($from, $to)) {
            return true;
        }

        $i = array_search($from, self::FLOW, true);
        $j = array_search($to, self::FLOW, true);

        return $i !== false && $j !== false && abs($i - $j) === 1;
    }

    private function isDesagendamento(string $from, string $to): bool
    {
        return $from === 'scheduled' && $to === 'approved';
    }
}
