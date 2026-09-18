<?php

namespace Tests\Feature;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\CopywriterAgent;
use App\Ai\Exceptions\LlmRefusedException;
use App\Ai\Exceptions\OutputRejectedException;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\LlmRequest;
use App\Ai\Providers\LlmResponse;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CopyGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
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

    private function withActiveStrategy(Project $project): Strategy
    {
        return Strategy::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Estrategia ativa',
            'summary' => 's',
            'editorial_line' => 'e',
            'pillars' => [
                ['name' => 'Educacao', 'weight' => 50, 'description' => 'd'],
                ['name' => 'Prova social', 'weight' => 50, 'description' => 'd'],
            ],
            'status' => 'active',
        ]);
    }

    private function generate(Project $project): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/copy:generate");
    }

    private function bindProvider(LlmProvider $provider): void
    {
        $this->app->bind(LlmProvider::class, fn () => $provider);
    }

    public function test_o_mockprovider_devolve_cinco_pecas_para_o_schema_do_copywriter(): void
    {
        $agent = new CopywriterAgent;
        $request = new LlmRequest(
            model: 'claude-opus-4-8',
            instructions: 'x',
            userMessage: 'x',
            schema: $agent->schema(),
        );

        $response = app(LlmProvider::class)->generate($request);

        $this->assertCount(5, $response->output['pieces']);
        // A saida do mock satisfaz o validate do agente.
        $agent->validate($response->output);
    }

    public function test_gera_202_e_grava_cinco_pecas_em_contents(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('succeeded', $run->status);
        $this->assertSame('copywriter', $run->agent);

        $pecas = Content::withoutGlobalScopes()->where('project_id', $project->id)->get();
        $this->assertCount(5, $pecas);
        foreach ($pecas as $peca) {
            $this->assertSame('idea', $peca->status);
            $this->assertSame('ai', $peca->source);
            $this->assertSame($run->id, $peca->origin_ai_run_id);
            $this->assertSame($editor->id, $peca->created_by);
        }
    }

    /**
     * O bug de producao: o copywriter nao recebia as pecas existentes e regenerou um
     * titulo IDENTICO ao de uma peca que ja estava no board.
     */
    public function test_o_contexto_leva_as_pecas_que_ja_existem(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);
        $this->peca($project, 'Ja escrevi sobre isso', 'Educacao');

        $context = AgentContext::forProject($project, [
            'with_existing_contents' => true,
        ]);

        $this->assertSame(
            [['title' => 'Ja escrevi sobre isso', 'pillar' => 'Educacao']],
            $context->existingContents,
        );
        $this->assertArrayHasKey('existing_contents', $context->toArray());
    }

    /** Peca arquivada nao conta: uma reprovada PODE e DEVE ser reescrita. */
    public function test_peca_arquivada_nao_entra_no_inventario(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->peca($project, 'Peca viva', 'Educacao');
        $this->peca($project, 'Peca arquivada', 'Educacao', 'archived');

        $context = AgentContext::forProject($project, ['with_existing_contents' => true]);

        $this->assertSame([['title' => 'Peca viva', 'pillar' => 'Educacao']], $context->existingContents);
    }

    /** O prompt manda nao repetir; o validate CONFERE. Em producao o modelo repetiu. */
    public function test_titulo_repetido_e_recusado(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);
        $this->peca($project, 'Como ler um laudo cautelar', 'Educacao');

        $context = AgentContext::forProject($project, ['with_existing_contents' => true]);

        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessageMatches('/já existe no projeto/');

        (new CopywriterAgent)->validate([
            'pieces' => array_fill(0, 5, [
                // Mesmo titulo da peca existente, com caixa diferente.
                'title' => 'COMO LER UM LAUDO CAUTELAR',
                'caption' => 'c', 'cta' => 'x', 'hashtags' => [],
                'format' => 'post', 'channel' => 'instagram', 'pillar' => 'Educacao',
            ]),
        ], $context);
    }

    /** Um pilar de peso baixo nunca era sorteado: 15% de 5 pecas da 0,75 => zero. */
    public function test_pilar_alvo_faz_o_lote_inteiro_sair_dele(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);
        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/projects/{$project->id}/copy:generate", [
            'pillar' => 'Prova social',
        ])->assertStatus(202);

        $pecas = Content::withoutGlobalScopes()->where('project_id', $project->id)->get();
        $this->assertCount(5, $pecas);
        foreach ($pecas as $peca) {
            $this->assertSame('Prova social', $peca->pillar);
        }
    }

    public function test_pilar_fora_da_estrategia_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        $this->postJson("/api/v1/projects/{$project->id}/copy:generate", [
            'pillar' => 'Pilar inventado',
        ])->assertStatus(422)->assertJsonPath('pillars', ['Educacao', 'Prova social']);

        $this->assertSame(0, AiRun::withoutGlobalScopes()->count());
    }

    /** O validate recusa peca de outro pilar quando um alvo foi pedido. */
    public function test_peca_fora_do_pilar_alvo_e_recusada(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        $context = AgentContext::forProject($project, ['pillar' => 'Prova social']);

        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessageMatches('/Pedido o pilar/');

        (new CopywriterAgent)->validate([
            'pieces' => array_fill(0, 5, [
                'title' => 't', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [],
                'format' => 'post', 'channel' => 'instagram', 'pillar' => 'Educacao',
            ]),
        ], $context);
    }

    /**
     * O bug de producao: o reviewer reprovou "Depoimento fabricado" (a persona interna
     * vendida como cliente real) e, no lote seguinte, o copywriter escreveu o MESMO
     * erro com outro titulo. O guard de titulo nao pega — o erro e do conteudo.
     */
    public function test_o_contexto_leva_as_violacoes_que_o_reviewer_ja_reprovou(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $peca = $this->peca($project, 'Peca reprovada', 'Educacao');

        ContentReview::create([
            'content_id' => $peca->id,
            'ai_run_id' => $this->reviewRun($project)->id,
            'verdict' => 'fail',
            'summary' => 's',
            'violations' => [[
                'rule' => 'Depoimento fabricado',
                'excerpt' => 'O Ricardo chegou desconfiado...',
                'suggestion' => 'Use um caso real, com consentimento.',
            ]],
        ]);

        $context = AgentContext::forProject($project, ['with_past_violations' => true]);

        // Vai a regra e a correcao. O `excerpt` NAO vai: e o erro por extenso, e
        // mandar o texto errado convida o modelo a imita-lo.
        $this->assertSame([[
            'rule' => 'Depoimento fabricado',
            'suggestion' => 'Use um caso real, com consentimento.',
        ]], $context->pastViolations);

        $this->assertArrayHasKey('past_violations', $context->toArray());
    }

    /** Uma regra violada por 3 pecas entra uma vez: o prompt nao precisa da repeticao. */
    public function test_a_mesma_regra_violada_varias_vezes_entra_uma_vez_so(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        foreach (['A', 'B'] as $titulo) {
            ContentReview::create([
                'content_id' => $this->peca($project, $titulo, 'Educacao')->id,
                'ai_run_id' => $this->reviewRun($project)->id,
                'verdict' => 'fail',
                'summary' => 's',
                'violations' => [['rule' => 'Promessa exagerada', 'suggestion' => 'Seja concreto.']],
            ]);
        }

        $context = AgentContext::forProject($project, ['with_past_violations' => true]);

        $this->assertCount(1, $context->pastViolations);
    }

    /** Veredito `pass` nao tem o que ensinar: so as reprovacoes viram memoria. */
    public function test_review_aprovada_nao_vira_violacao(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        ContentReview::create([
            'content_id' => $this->peca($project, 'Peca aprovada', 'Educacao')->id,
            'ai_run_id' => $this->reviewRun($project)->id,
            'verdict' => 'pass',
            'summary' => 's',
            'violations' => [],
        ]);

        $context = AgentContext::forProject($project, ['with_past_violations' => true]);

        $this->assertSame([], $context->pastViolations);
    }

    /** `content_reviews.ai_run_id` e NOT NULL: toda review nasce de uma execucao. */
    private function reviewRun(Project $project): AiRun
    {
        return AiRun::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'agent' => 'reviewer',
            'provider' => 'mock',
            'model' => 'x',
            'status' => 'succeeded',
            'input' => [],
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function peca(Project $project, string $title, string $pillar, string $status = 'idea'): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => $title,
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => $pillar,
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_lote_invalido_nao_grava_nenhuma_peca(): void
    {
        // Provider que devolve 4 pecas: falha no validate do agente.
        $this->bindProvider(new class implements LlmProvider
        {
            public function generate(LlmRequest $request): LlmResponse
            {
                $peca = [
                    'title' => 't', 'caption' => 'c', 'cta' => 'x',
                    'hashtags' => [], 'format' => 'post', 'channel' => 'instagram', 'pillar' => 'p',
                ];

                return new LlmResponse(
                    output: ['pieces' => [$peca, $peca, $peca, $peca]],
                    model: 'claude-opus-4-8',
                    inputTokens: 10,
                    outputTokens: 10,
                );
            }
        });

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('rejected_output', $run->error_code);
        // Transacao: nenhuma peca meio-gravada.
        $this->assertSame(0, Content::withoutGlobalScopes()->count());
    }

    public function test_sem_estrategia_ativa_devolve_422_e_nao_enfileira(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(422);
        $this->assertSame(0, AiRun::withoutGlobalScopes()->count());
    }

    public function test_estrategia_draft_nao_habilita_copy(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Strategy::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id,
            'title' => 'rascunho', 'summary' => 's', 'editorial_line' => 'e',
            'pillars' => [], 'status' => 'draft',
        ]);

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(422);
    }

    public function test_copy_em_andamento_devolve_409(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        AiRun::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id,
            'agent' => 'copywriter', 'provider' => 'mock', 'model' => 'claude-opus-4-8',
            'status' => 'running', 'input' => [], 'created_by' => $editor->id,
        ]);

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(409);
    }

    public function test_estrategia_em_andamento_nao_bloqueia_copy(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        // Um strategist rodando nao impede o copy (indice por-agente + emAndamento por agente).
        AiRun::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id,
            'agent' => 'strategist', 'provider' => 'mock', 'model' => 'claude-opus-4-8',
            'status' => 'running', 'input' => [], 'created_by' => $editor->id,
        ]);

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(202);
    }

    public function test_orcamento_estourado_devolve_402(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        AiRun::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id,
            'agent' => 'strategist', 'provider' => 'mock', 'model' => 'claude-opus-4-8',
            'status' => 'succeeded', 'input' => [],
            'cost_cents' => config('ai.workspace_monthly_budget_cents'),
            'created_by' => $editor->id,
        ]);

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(402);
    }

    public function test_projeto_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);
        $this->withActiveStrategy($target);

        Sanctum::actingAs($intruder);

        $this->generate($target)->assertNotFound();
    }

    public function test_recusa_do_modelo_nao_grava_pecas(): void
    {
        $this->bindProvider(new class implements LlmProvider
        {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new LlmRefusedException;
            }
        });

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('refused', $run->error_code);
        $this->assertSame(0, Content::withoutGlobalScopes()->count());
    }

    /**
     * O loop que faltava: o analytics MEDIA o pilar zerado e o numero nao chegava a
     * agente nenhum. Agora chega — e e o mesmo calculo, feito em PHP.
     */
    public function test_o_contexto_leva_a_aderencia_de_cada_pilar(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        // A estrategia pede 50/50; as 2 pecas que existem sao as duas de Educacao.
        $this->peca($project, 'A', 'Educacao');
        $this->peca($project, 'B', 'Educacao');

        $context = AgentContext::forProject($project, ['with_pillar_adherence' => true]);

        $porNome = collect($context->pillarAdherence['pilares'])->keyBy('nome');

        $this->assertSame(50, $porNome['Educacao']['peso_pedido']);
        $this->assertSame(100, $porNome['Educacao']['peso_real']);
        $this->assertSame(50, $porNome['Educacao']['desvio']);

        $this->assertSame(0, $porNome['Prova social']['peso_real']);
        $this->assertSame(-50, $porNome['Prova social']['desvio']);

        $this->assertArrayHasKey('pillar_adherence', $context->toArray());
        $this->assertSame('Prova social', $context->mostDeficientPillar());
    }

    /** Nenhum pilar em deficit: nao ha buraco a cobrir, e o guard nao tem alvo. */
    public function test_sem_pilar_atrasado_nao_ha_alvo_a_cobrir(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        $this->peca($project, 'A', 'Educacao');
        $this->peca($project, 'B', 'Prova social');

        $context = AgentContext::forProject($project, ['with_pillar_adherence' => true]);

        $this->assertNull($context->mostDeficientPillar());
    }

    /**
     * O guard. O prompt manda cobrir o pilar mais atrasado; sem isto, prompt e
     * torcida — e o modelo distribui pelo peso puro, deixando o pilar leve zerado.
     */
    public function test_lote_que_ignora_o_pilar_mais_atrasado_e_rejeitado(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        $this->peca($project, 'A', 'Educacao');
        $this->peca($project, 'B', 'Educacao');

        $context = AgentContext::forProject($project, ['with_pillar_adherence' => true]);

        $lote = ['pieces' => array_map(fn (int $i) => [
            'title' => "Peca {$i}",
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            // Todas de Educacao — o pilar que JA estourou o peso. "Prova social",
            // que esta 50pp atrasado, nao recebeu nenhuma.
            'pillar' => 'Educacao',
        ], range(0, 4))];

        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('"Prova social" é o mais atrasado');

        (new CopywriterAgent(5))->validate($lote, $context);
    }

    /** Uma peca do pilar atrasado basta: o resto do lote segue os pesos. */
    public function test_lote_que_cobre_o_pilar_mais_atrasado_passa(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        $this->peca($project, 'A', 'Educacao');
        $this->peca($project, 'B', 'Educacao');

        $context = AgentContext::forProject($project, ['with_pillar_adherence' => true]);

        $lote = ['pieces' => array_map(fn (int $i) => [
            'title' => "Peca {$i}",
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => $i === 0 ? 'Prova social' : 'Educacao',
        ], range(0, 4))];

        (new CopywriterAgent(5))->validate($lote, $context);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Com pilar escolhido a mao, o humano JA disse qual buraco cobrir. Cobrar dele
     * tambem o pilar mais atrasado tornaria o lote impossivel: o guard de
     * `target_pillar` exige que TODAS as pecas sejam do pilar pedido.
     */
    public function test_pilar_escolhido_a_mao_desliga_o_guard_de_aderencia(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->withActiveStrategy($project);

        $this->peca($project, 'A', 'Educacao');
        $this->peca($project, 'B', 'Educacao');

        // "Prova social" e o mais atrasado, mas o humano pediu "Educacao".
        $context = AgentContext::forProject($project, [
            'with_pillar_adherence' => true,
            'pillar' => 'Educacao',
        ]);

        $lote = ['pieces' => array_map(fn (int $i) => [
            'title' => "Peca {$i}",
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => 'Educacao',
        ], range(0, 4))];

        (new CopywriterAgent(5))->validate($lote, $context);

        $this->expectNotToPerformAssertions();
    }
}
