<?php

namespace Tests\Feature;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\RewriterAgent;
use App\Ai\Exceptions\OutputRejectedException;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O 8o agente fecha o ciclo do revisor.
 *
 * Ate aqui ele era um juiz que so condena: reprovava, e o fluxo parava — o humano
 * arquivava a peca e pedia um LOTE inteiro novo, jogando fora as outras quatro (que
 * estavam boas) e pagando 6 centavos para consertar uma.
 *
 * AI_PROVIDER=mock: nenhum teste aqui chama a API real.
 */
class RewriteGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function membro(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function peca(Project $project, string $status = 'review'): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'O Ricardo comprou depois de tres semanas',
            'caption' => 'O Ricardo chegou desconfiado, ja tinha levado prejuizo...',
            'cta' => 'Agende seu test drive.',
            'hashtags' => ['#depoimento'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => 'Historias',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function reprova(Content $content): ContentReview
    {
        $run = AiRun::create([
            'workspace_id' => $content->workspace_id,
            'project_id' => $content->project_id,
            'agent' => 'reviewer',
            'provider' => 'mock',
            'model' => 'x',
            'status' => 'succeeded',
            'input' => [],
            'created_by' => User::factory()->create()->id,
        ]);

        return ContentReview::create([
            'content_id' => $content->id,
            'ai_run_id' => $run->id,
            'verdict' => 'fail',
            'summary' => 'Depoimento fabricado.',
            'violations' => [[
                'rule' => 'Depoimento fabricado',
                'excerpt' => 'O Ricardo chegou desconfiado',
                'suggestion' => 'Use um caso real, com consentimento.',
            ]],
        ]);
    }

    private function rewrite(Content $content): TestResponse
    {
        return $this->postJson("/api/v1/contents/{$content->id}/rewrite:generate");
    }

    public function test_reescreve_a_peca_no_lugar_e_guarda_o_texto_antigo(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project);
        $this->reprova($peca);

        $antes = $peca->caption;

        Sanctum::actingAs($editor);

        $response = $this->rewrite($peca)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('succeeded', $run->status);
        $this->assertSame('rewriter', $run->agent);

        $peca->refresh();

        // NO LUGAR: mesmo id, texto novo.
        $this->assertNotSame($antes, $peca->caption);
        $this->assertSame(1, Content::withoutGlobalScopes()->count());

        // Formato, canal e pilar NAO mudam: a peca ja tem lugar no calendario.
        $this->assertSame('post', $peca->format);
        $this->assertSame('instagram', $peca->channel);
        $this->assertSame('Historias', $peca->pillar);

        // O texto antigo nao some sem rastro (append-only, sem transicao de status).
        $revisao = ContentRevision::where('content_id', $peca->id)->sole();

        $this->assertNull($revisao->from_status);
        $this->assertNull($revisao->to_status);
        $this->assertSame($antes, $revisao->changes['de']['caption']);
        $this->assertSame($peca->caption, $revisao->changes['para']['caption']);
    }

    /** A peca reprovada e o veredito DELA chegam ao agente. */
    public function test_o_contexto_leva_a_peca_e_o_veredito_que_a_reprovou(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project);
        $this->reprova($peca);

        $context = AgentContext::forProject($project, ['rewrite_content_id' => $peca->id]);

        $this->assertSame($peca->id, $context->rewriteTarget['id']);
        $this->assertSame('fail', $context->rewriteTarget['verdict']);
        $this->assertSame('Depoimento fabricado', $context->rewriteTarget['violations'][0]['rule']);

        // O trecho VAI (diferente do past_violations): e o proprio texto sendo
        // consertado, e a frase exata e o que torna a correcao cirurgica.
        $this->assertSame('O Ricardo chegou desconfiado', $context->rewriteTarget['violations'][0]['excerpt']);

        $this->assertArrayHasKey('rewrite_target', $context->toArray());
    }

    /** Peca sem reprovacao nao tem o que corrigir: reescrever seria adivinhar. */
    public function test_peca_nao_reprovada_e_recusada(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project);

        Sanctum::actingAs($editor);

        $this->rewrite($peca)->assertStatus(422);

        $this->assertSame(0, AiRun::withoutGlobalScopes()->where('agent', 'rewriter')->count());
    }

    /** Um `pass` tambem nao: aprovado nao se conserta. */
    public function test_peca_aprovada_e_recusada(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project);

        $run = AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'reviewer',
            'provider' => 'mock',
            'model' => 'x',
            'status' => 'succeeded',
            'input' => [],
            'created_by' => $editor->id,
        ]);

        ContentReview::create([
            'content_id' => $peca->id,
            'ai_run_id' => $run->id,
            'verdict' => 'pass',
            'summary' => 'Coerente.',
            'violations' => [],
        ]);

        Sanctum::actingAs($editor);

        $this->rewrite($peca)->assertStatus(422);
    }

    /**
     * A fraude obvia de uma reescrita: devolver o mesmo texto. O modelo "concorda" com
     * o revisor e nao muda nada — e a peca seguiria reprovada, agora com um run pago.
     */
    public function test_reescrita_identica_a_reprovada_e_rejeitada(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project);
        $this->reprova($peca);

        $context = AgentContext::forProject($project, ['rewrite_content_id' => $peca->id]);

        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('identica a reprovada');

        (new RewriterAgent)->validate([
            'title' => $peca->title,
            'caption' => $peca->caption,
            'cta' => $peca->cta,
            'hashtags' => $peca->hashtags,
        ], $context);
    }

    public function test_viewer_nao_reescreve(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->membro($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project);
        $this->reprova($peca);

        Sanctum::actingAs($viewer);

        $this->rewrite($peca)->assertForbidden();
    }

    public function test_peca_de_outro_workspace_nao_existe(): void
    {
        $meu = Workspace::factory()->create();
        $alheio = Workspace::factory()->create();
        $intruso = $this->membro($meu, WorkspaceRole::Owner);
        $peca = $this->peca(Project::factory()->create(['workspace_id' => $alheio->id]));
        $this->reprova($peca);

        Sanctum::actingAs($intruso);

        $this->rewrite($peca)->assertNotFound();
    }

    public function test_sem_autenticacao_nao_passa(): void
    {
        $workspace = Workspace::factory()->create();
        $peca = $this->peca(Project::factory()->create(['workspace_id' => $workspace->id]));

        $this->rewrite($peca)->assertUnauthorized();
    }
}
