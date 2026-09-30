<?php

namespace Tests\Support;

use App\Domain\Editorial\Approval;
use App\Domain\Editorial\EditorialState;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * CP-04/CP-04A: uma peca "aprovada" so e aprovada com decisao humana presa a versao
 * (snapshot + hash), dada em `pending_approval` (a IA revisou e aprovou ESTA versao),
 * com uma request_key. Testes que precisam de uma peca aprovada passam por aqui — pelo
 * MESMO servico da Central —, e nao por `status => approved` no banco.
 */
final class Aprovar
{
    public static function peca(Content $content, ?User $quem = null): Content
    {
        $payload = self::pedido($content);

        Approval::approve($content, $payload['expected_version'], $quem ?? User::factory()->create(), $payload['request_key']);

        return $content->fresh();
    }

    /**
     * O corpo do POST /approve para a peca como esta agora, ja em `pending_approval`:
     * em revisao e revisada pela IA (veredito `pass`) nesta versao.
     *
     * @return array{expected_version: int, request_key: string}
     */
    public static function pedido(Content $content): array
    {
        $content->refresh();

        if (in_array($content->status, ['idea', 'production'], true)) {
            $content->update(['status' => 'review']);
        }

        if ($content->status === 'review' && EditorialState::for($content->fresh()) !== 'pending_approval') {
            self::revisadaPelaIa($content);
        }

        return ['expected_version' => (int) $content->fresh()->version, 'request_key' => (string) Str::uuid()];
    }

    /** Um veredito `pass` do revisor de IA, mais novo que qualquer mudanca de texto. */
    public static function revisadaPelaIa(Content $content): void
    {
        $run = AiRun::create([
            'workspace_id' => $content->workspace_id, 'project_id' => $content->project_id, 'agent' => 'reviewer',
            'provider' => 'mock', 'model' => 'm', 'status' => 'succeeded', 'input' => [],
            'created_by' => $content->created_by,
        ]);

        $review = ContentReview::create([
            'content_id' => $content->id, 'ai_run_id' => $run->id, 'verdict' => 'pass',
            'summary' => 'Sem violações.', 'violations' => [],
        ]);

        // Mais nova que a ultima revisao de texto: o veredito vale para esta versao.
        $review->forceFill(['created_at' => now()->addSecond()])->save();
    }
}
