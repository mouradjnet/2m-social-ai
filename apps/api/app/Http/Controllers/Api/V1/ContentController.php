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
     * O fluxo linear. `scheduled`/`published` ficam de fora desta fatia: exigem
     * agendar e exportar, que nao existem. `archived` e terminal, alcancavel de
     * qualquer estado ativo — nao entra na sequencia.
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
            $content->update(['status' => $to]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'from_status' => $from,
                'to_status' => $to,
            ]);
        });

        return response()->json(['data' => $content->refresh()]);
    }

    /** Arquivar de qualquer estado ativo, ou mover +-1 passo no fluxo linear. */
    private function isValidTransition(string $from, string $to): bool
    {
        if ($to === 'archived') {
            return $from !== 'archived';
        }

        $i = array_search($from, self::FLOW, true);
        $j = array_search($to, self::FLOW, true);

        return $i !== false && $j !== false && abs($i - $j) === 1;
    }
}
