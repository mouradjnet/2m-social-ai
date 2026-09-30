<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Editorial\Approval;
use App\Domain\Editorial\ApprovalConflict;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CP-04: a Central de Aprovacao no servidor. Toda decisao e HUMANA (sem agente
 * aqui), de quem revisa ou acima, sobre a `version` que a pessoa viu. A peca de outro
 * workspace nem existe (404, pelo escopo); a versao velha e 409.
 */
class ContentDecisionController extends Controller
{
    public function approve(Request $request, Content $content): JsonResponse
    {
        $data = $this->validar($request, motivoObrigatorio: false);

        return $this->decidir($request, $content, fn () => Approval::approve($content, $data['version'], $request->user()));
    }

    public function reject(Request $request, Content $content): JsonResponse
    {
        $data = $this->validar($request, motivoObrigatorio: true);

        return $this->decidir($request, $content, fn () => Approval::reject($content, $data['version'], $request->user(), $data['reason']));
    }

    public function requestChanges(Request $request, Content $content): JsonResponse
    {
        $data = $this->validar($request, motivoObrigatorio: true);

        return $this->decidir($request, $content, fn () => Approval::requestChanges($content, $data['version'], $request->user(), $data['reason']));
    }

    /** O historico da peca: movimentos, mudancas de conteudo e decisoes humanas. */
    public function history(Content $content): JsonResponse
    {
        Gate::authorize('view', $content->project);

        $revisoes = ContentRevision::where('content_id', $content->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ContentRevision $r) => [
                'type' => $r->changes ? 'change' : 'status',
                'from_status' => $r->from_status,
                'to_status' => $r->to_status,
                'fields' => $r->changes ? array_values(array_diff(array_keys($r->changes), ['motivo', 'ai_run_id'])) : [],
                'user_id' => $r->user_id,
                'at' => $r->created_at,
            ]);

        $decisoes = ContentDecision::where('content_id', $content->id)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (ContentDecision $d) => [
                'id' => $d->id,
                'decision' => $d->decision,
                'version' => $d->version,
                'reason' => $d->reason,
                'from_status' => $d->from_status,
                'to_status' => $d->to_status,
                'snapshot_hash' => $d->snapshot_hash,
                'user' => $d->user,
                'at' => $d->created_at,
            ]);

        return response()->json([
            'data' => [
                'content_id' => $content->id,
                'project_id' => $content->project_id,
                'version' => $content->version,
                'approval_valid' => Approval::validApproval($content) !== null,
                'decisions' => $decisoes,
                'revisions' => $revisoes,
            ],
        ]);
    }

    private function validar(Request $request, bool $motivoObrigatorio): array
    {
        return $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => [$motivoObrigatorio ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
        ], [
            'reason.required' => 'Diga o motivo: ele fica no histórico e orienta o ajuste.',
        ]);
    }

    private function decidir(Request $request, Content $content, callable $decisao): JsonResponse
    {
        Gate::authorize('update', $content->project);

        // Fail-closed: sem papel de revisao explicito, nao decide.
        if (! $content->project->workspace->roleFor($request->user())?->atLeast(WorkspaceRole::Reviewer)) {
            return response()->json(['message' => 'Só quem revisa pode decidir sobre uma peça.'], 403);
        }

        try {
            $registro = $decisao();
        } catch (ApprovalConflict $e) {
            return response()->json(['message' => $e->getMessage(), 'version' => $content->fresh()?->version], 409);
        }

        return response()->json([
            'data' => $content->fresh()->load(['approver', 'latestDecision', 'latestReview', 'latestTextRevision']),
            'decision' => $registro->only(['id', 'decision', 'version', 'reason', 'snapshot_hash', 'created_at']),
        ]);
    }
}
