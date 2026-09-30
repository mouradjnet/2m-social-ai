<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Editorial\Approval;
use App\Domain\Editorial\ApprovalConflict;
use App\Domain\Editorial\ApprovalOutcome;
use App\Domain\Editorial\EditorialTimeline;
use App\Domain\Editorial\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CP-04/04A/04B: a Central de Aprovacao no servidor. Toda decisao e HUMANA (sem agente
 * aqui), de quem tem a permissao `approve` (revisor+), sobre a versao que a pessoa viu,
 * com uma `request_key` (idempotencia).
 *
 * Ordem das portas, igual nas tres acoes: autenticado (401, middleware) -> a peca
 * existe no escopo de quem pede (404, cobre marca alheia) -> permissao (403) ->
 * payload (422) -> estado e versao (409). Quem decide e a marca vem do servidor;
 * nada do corpo e autoridade.
 */
class ContentDecisionController extends Controller
{
    public function approve(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('approve', $content->project);
        $data = $this->validar($request, motivoObrigatorio: false);

        return $this->responder($content, fn () => Approval::approve(
            $content, $data['versao'], $request->user(), $data['request_key'],
        ));
    }

    /** CP-04B: tira a peca do fluxo, com motivo. Nao volta direto para aprovada. */
    public function reject(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('approve', $content->project);
        $data = $this->validar($request, motivoObrigatorio: true);

        return $this->responder($content, fn () => Approval::reject(
            $content, $data['versao'], $request->user(), $data['reason'], $data['request_key'],
        ));
    }

    /** CP-04B: devolve a peca para producao, com o que precisa mudar. */
    public function requestChanges(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('approve', $content->project);
        $data = $this->validar($request, motivoObrigatorio: true);

        return $this->responder($content, fn () => Approval::requestChanges(
            $content, $data['versao'], $request->user(), $data['reason'], $data['request_key'],
        ));
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
                'request_key' => $d->request_key,
                'user' => $d->user,
                'at' => $d->created_at,
            ]);

        return response()->json([
            'data' => [
                'content_id' => $content->id,
                'project_id' => $content->project_id,
                'version' => $content->version,
                'approval_valid' => Approval::validApproval($content) !== null,
                'approved_version' => Approval::validApproval($content)?->version,
                'events' => EditorialTimeline::for($content),
                'decisions' => $decisoes,
                'revisions' => $revisoes,
            ],
        ]);
    }

    /** @return array{versao: int, request_key: string, reason: ?string} */
    private function validar(Request $request, bool $motivoObrigatorio): array
    {
        $data = $request->validate([
            'expected_version' => ['required_without:version', 'integer', 'min:1'],
            'version' => ['required_without:expected_version', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
            // `min` conta depois do trim (middleware): so espacos nao e justificativa.
            'reason' => [$motivoObrigatorio ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
        ], [
            'request_key.required' => 'Falta a chave da requisição (request_key).',
            'reason.required' => 'Diga o motivo: ele fica no histórico e orienta o ajuste.',
        ]);

        return [
            'versao' => (int) ($data['expected_version'] ?? $data['version']),
            'request_key' => $data['request_key'],
            'reason' => $data['reason'] ?? null,
        ];
    }

    /** @param  callable(): ApprovalOutcome  $decidir */
    private function responder(Content $content, callable $decidir): JsonResponse
    {
        try {
            $resultado = $decidir();
        } catch (ApprovalConflict $e) {
            return response()->json(['message' => $e->getMessage(), 'version' => $content->fresh()?->version], 409);
        } catch (IdempotencyConflict $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['request_key' => [$e->getMessage()]],
            ], 422);
        }

        $decisao = $resultado->decision->load('user');

        return response()->json([
            'data' => $content->fresh()->load(['approver', 'latestDecision', 'latestReview', 'latestTextRevision']),
            'decision' => [
                ...$decisao->only(['id', 'decision', 'version', 'reason', 'snapshot_hash', 'created_at']),
                'user' => $decisao->user,
            ],
            // Mesma chave, mesmo pedido: a decisao original, sem gravar outra.
            'replayed' => $resultado->replayed,
        ]);
    }
}
