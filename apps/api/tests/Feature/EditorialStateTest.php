<?php

namespace Tests\Feature;

use App\Domain\Editorial\EditorialState;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP-03: os estados editoriais explicitos, derivados do status + ultimo veredito,
 * sem coluna nova. Nenhum deles aprova nem publica.
 */
class EditorialStateTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->autor = User::factory()->create();
    }

    private function peca(string $status): Content
    {
        return Content::create([
            'workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id,
            'title' => 't', 'caption' => 'c', 'format' => 'post', 'channel' => 'instagram',
            'status' => $status, 'created_by' => $this->autor->id,
        ]);
    }

    private function veredito(Content $peca, string $verdict, string $quando): void
    {
        $run = AiRun::create([
            'workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id,
            'agent' => 'reviewer', 'provider' => 'mock', 'model' => 'm', 'status' => 'succeeded',
            'input' => [], 'created_by' => $this->autor->id,
        ]);
        $review = ContentReview::create([
            'content_id' => $peca->id, 'ai_run_id' => $run->id, 'verdict' => $verdict, 'summary' => 's',
            'violations' => $verdict === 'fail' ? [['rule' => 'r', 'excerpt' => 'e', 'suggestion' => 's']] : [],
        ]);
        $review->forceFill(['created_at' => $quando])->save();
    }

    private function estado(Content $peca): string
    {
        return EditorialState::for(Content::with(['latestReview', 'latestTextRevision'])->findOrFail($peca->id));
    }

    public function test_ideia_e_producao_sao_rascunho(): void
    {
        $this->assertSame('draft', $this->estado($this->peca('idea')));
        $this->assertSame('draft', $this->estado($this->peca('production')));
    }

    public function test_em_revisao_sem_veredito(): void
    {
        $this->assertSame('in_review', $this->estado($this->peca('review')));
    }

    public function test_reprovada_precisa_de_revisao_e_aprovada_pelo_revisor_fica_pronta(): void
    {
        $reprovada = $this->peca('review');
        $this->veredito($reprovada, 'fail', '2026-09-30 10:00:00');
        $this->assertSame('needs_revision', $this->estado($reprovada));

        $aprovada = $this->peca('review');
        $this->veredito($aprovada, 'pass', '2026-09-30 10:00:00');
        $this->assertSame('ready_for_approval', $this->estado($aprovada));
    }

    /** O texto mudou DEPOIS do veredito (reescrita): o veredito fala de outro texto. */
    public function test_reescrita_depois_da_reprovacao_volta_para_em_revisao(): void
    {
        $peca = $this->peca('review');
        $this->veredito($peca, 'fail', '2026-09-30 10:00:00');

        $revisao = ContentRevision::create([
            'content_id' => $peca->id, 'user_id' => $this->autor->id,
            'changes' => ['de' => ['caption' => 'c'], 'para' => ['caption' => 'nova']],
        ]);
        $revisao->forceFill(['created_at' => '2026-09-30 11:00:00'])->save();

        $this->assertSame('in_review', $this->estado($peca));
    }

    public function test_estados_do_fluxo_humano_passam_como_estao(): void
    {
        foreach (['approved', 'scheduled', 'published', 'archived'] as $status) {
            $this->assertSame($status, $this->estado($this->peca($status)));
        }
    }

    public function test_estado_vai_na_resposta_json_da_peca(): void
    {
        $this->assertSame('draft', $this->peca('idea')->fresh()->toArray()['editorial_state']);
    }
}
