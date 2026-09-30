<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Editorial\Approval;
use App\Domain\Editorial\ApprovalConflict;
use App\Domain\Editorial\VersionConflict;
use App\Domain\Editorial\Versioning;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CP-04C: versoes de uma peca — listar, ver, comparar, restaurar. A peca vem do
 * binding com o escopo de tenant (marca alheia = 404); a permissao, da Policy do
 * projeto. Nada do corpo e autoridade: quem restaura e o usuario da sessao.
 *
 * Versao historica nao se edita: restaurar cria uma versao NOVA, que volta a revisao
 * e aprovacao (a aprovacao antiga nunca e reaproveitada).
 */
class ContentVersionController extends Controller
{
    public function index(Content $content): JsonResponse
    {
        Gate::authorize('view', $content->project);

        $aprovada = Approval::validApproval($content);

        return response()->json([
            'data' => [
                'content_id' => $content->id,
                'current_version' => (int) $content->version,
                'approved_version' => $aprovada?->version,
                'versions' => $content->versions()->with('user')->get()
                    ->map(fn (ContentVersion $v) => $this->resumo($v, $aprovada?->snapshot_hash)),
            ],
        ]);
    }

    public function show(Content $content, int $version): JsonResponse
    {
        Gate::authorize('view', $content->project);

        $v = $this->versao($content, $version);

        return response()->json(['data' => [
            ...$this->resumo($v, Approval::validApproval($content)?->snapshot_hash),
            'snapshot' => $v->snapshot,
            'media' => $v->media,
        ]]);
    }

    public function compare(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('view', $content->project);

        $data = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'min:1'],
        ]);

        $de = $this->versao($content, (int) $data['from']);
        $para = $this->versao($content, (int) $data['to']);

        return response()->json(['data' => [
            'from' => $de->version,
            'to' => $para->version,
            'fields' => Versioning::compare($de, $para),
        ]]);
    }

    public function restore(Request $request, Content $content, int $version): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']], [
            'expected_version.required' => 'Falta a versão atual que você viu (expected_version).',
        ]);

        try {
            $nova = Versioning::restore($content, $version, (int) $data['expected_version'], $request->user());
        } catch (VersionConflict $e) {
            return $e->render();
        } catch (ApprovalConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => $content->refresh(),
            'version' => $this->resumo($nova->load('user'), null),
            'message' => "Versão {$version} restaurada como versão {$nova->version}. Ela precisa passar de novo por revisão e aprovação.",
        ]);
    }

    private function versao(Content $content, int $version): ContentVersion
    {
        return ContentVersion::where('content_id', $content->id)->where('version', $version)->with('user')->firstOrFail();
    }

    private function resumo(ContentVersion $v, ?string $hashAprovado): array
    {
        return [
            'version' => $v->version,
            'origin' => $v->origin,
            'restored_from_version' => $v->restored_from_version,
            'invalidated_approval' => $v->invalidated_approval,
            'snapshot_hash' => $v->snapshot_hash,
            'is_approved' => $hashAprovado !== null && hash_equals($hashAprovado, $v->snapshot_hash),
            'ai_run_id' => $v->ai_run_id,
            'user' => $v->user,
            'title' => $v->snapshot['title'] ?? null,
            'at' => $v->created_at,
        ];
    }
}
