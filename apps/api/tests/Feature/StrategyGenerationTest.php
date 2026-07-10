<?php

namespace Tests\Feature;

use App\Ai\Budget;
use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Exceptions\LlmRefusedException;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\LlmRequest;
use App\Ai\Providers\LlmResponse;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Criterio de aceite da Fase 3. A geracao nao bloqueia: devolve 202 e o id da
 * execucao (ADR-07). Como QUEUE_CONNECTION=sync no phpunit.xml, o RunAgentJob
 * roda dentro da propria requisicao — as assercoes sobre `ai_runs` valem logo
 * apos o POST.
 *
 * AI_PROVIDER=mock: nenhum teste aqui chama a API real.
 */
class StrategyGenerationTest extends TestCase
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

    private function generate(Project $project): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/strategies:generate");
    }

    /**
     * Liga um provider fake no container, no lugar do MockProvider.
     * O closure devolve a MESMA instancia — testes que contam chamadas dependem
     * disso (ver test_saida_fora_das_regras_e_rejeitada_depois_de_duas_tentativas).
     */
    private function bindProvider(LlmProvider $provider): void
    {
        $this->app->bind(LlmProvider::class, fn () => $provider);
    }

    public function test_geracao_devolve_202_e_grava_a_estrategia_como_rascunho(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);

        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('succeeded', $run->status);
        $this->assertSame('strategist', $run->agent);
        $this->assertSame('mock', $run->provider);
        $this->assertNull($run->error);

        // MockProvider devolve 1400 tokens de entrada e 900 de saida.
        $this->assertSame(1400, $run->input_tokens);
        $this->assertSame(900, $run->output_tokens);
        $this->assertSame(3, $run->cost_cents);
        $this->assertNotNull($run->latency_ms);

        $strategy = Strategy::withoutGlobalScopes()->where('project_id', $project->id)->sole();

        $this->assertSame('draft', $strategy->status);
        $this->assertSame($run->id, $strategy->ai_run_id);
        $this->assertSame($workspace->id, $strategy->workspace_id);
        $this->assertSame(100, array_sum(array_column($strategy->pillars, 'weight')));
    }

    /**
     * Regressao do caminho de recusa: o usuario precisa ver que o modelo recusou,
     * nao a mensagem generica de "tente novamente" — que o faria repetir um
     * prompt fadado a ser recusado de novo.
     */
    public function test_recusa_do_modelo_chega_ao_ai_run_como_mensagem_de_recusa(): void
    {
        $this->app->bind(LlmProvider::class, fn () => new class implements LlmProvider
        {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new LlmRefusedException;
            }
        });

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);

        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('O modelo recusou esta requisicao.', $run->error);
        $this->assertStringNotContainsString('Tente novamente', $run->error);

        $this->assertSame(0, Strategy::withoutGlobalScopes()->count());
    }

    public function test_orcamento_mensal_estourado_devolve_402_e_nao_enfileira(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => ['project_id' => $project->id],
            'cost_cents' => config('ai.workspace_monthly_budget_cents'),
            'created_by' => $editor->id,
        ]);

        Sanctum::actingAs($editor);

        $this->generate($project)
            ->assertStatus(402)
            ->assertJsonPath('limit_cents', config('ai.workspace_monthly_budget_cents'));

        // A execucao antiga continua sendo a unica: nada novo foi enfileirado.
        $this->assertSame(1, AiRun::withoutGlobalScopes()->count());
        $this->assertSame(0, Strategy::withoutGlobalScopes()->count());
    }

    public function test_gasto_do_mes_passado_nao_conta_para_o_orcamento(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $antiga = AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'cost_cents' => config('ai.workspace_monthly_budget_cents') * 10,
            'created_by' => $editor->id,
        ]);

        $antiga->forceFill(['created_at' => now()->subMonth()->startOfMonth()])->saveQuietly();

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(202);
    }

    public function test_orcamento_de_outro_workspace_nao_bloqueia_este(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $editor = $this->memberOf($mine, WorkspaceRole::Editor);
        $stranger = $this->memberOf($theirs, WorkspaceRole::Owner);
        $project = Project::factory()->create(['workspace_id' => $mine->id]);

        AiRun::create([
            'workspace_id' => $theirs->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'cost_cents' => config('ai.workspace_monthly_budget_cents') * 10,
            'created_by' => $stranger->id,
        ]);

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(202);
    }

    public function test_viewer_nao_pode_gerar_estrategia(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->memberOf($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($viewer);

        $this->generate($project)->assertForbidden();

        $this->assertSame(0, AiRun::withoutGlobalScopes()->count());
    }

    public function test_projeto_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);

        Sanctum::actingAs($intruder);

        $this->generate($target)->assertNotFound();
    }

    public function test_polling_do_ai_run_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $stranger = $this->memberOf($theirs, WorkspaceRole::Owner);
        $project = Project::factory()->create(['workspace_id' => $theirs->id]);

        $run = AiRun::create([
            'workspace_id' => $theirs->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'created_by' => $stranger->id,
        ]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/ai-runs/{$run->id}")->assertNotFound();
    }

    public function test_polling_do_proprio_ai_run_devolve_o_estado(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $runId = $this->generate($project)->assertStatus(202)->json('ai_run_id');

        $this->getJson("/api/v1/ai-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('agent', 'strategist')
            ->assertJsonPath('cost_cents', 3)
            ->assertJsonPath('error', null);
    }

    public function test_listagem_nao_vaza_estrategia_de_outro_workspace(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $editor = $this->memberOf($mine, WorkspaceRole::Editor);
        $meuProjeto = Project::factory()->create(['workspace_id' => $mine->id]);
        $projetoAlheio = Project::factory()->create(['workspace_id' => $theirs->id]);

        Sanctum::actingAs($editor);
        $this->generate($meuProjeto)->assertStatus(202);

        Strategy::withoutGlobalScopes()->create([
            'workspace_id' => $theirs->id,
            'project_id' => $projetoAlheio->id,
            'title' => 'Alheia',
            'pillars' => [],
            'status' => 'draft',
        ]);

        $this->getJson("/api/v1/projects/{$meuProjeto->id}/strategies")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['title' => 'Alheia']);
    }

    public function test_execucao_bem_sucedida_nao_tem_error_code(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('succeeded', $run->status);

        // assertDatabaseHas, e nao assertNull($run->error_code): o Eloquent
        // devolve null para atributo inexistente, sem erro. Este assert toca a
        // coluna no SQL, entao falha de verdade enquanto ela nao existir.
        $this->assertDatabaseHas('ai_runs', ['id' => $run->id, 'error_code' => null]);
    }

    public function test_polling_expoe_o_error_code(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $run = AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'failed',
            'input' => [],
            'error' => 'O modelo recusou esta requisicao.',
            'error_code' => 'refused',
            'created_by' => $editor->id,
        ]);

        Sanctum::actingAs($editor);

        $this->getJson("/api/v1/ai-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('error_code', 'refused');
    }

    public function test_recusa_do_modelo_grava_error_code_refused(): void
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

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('refused', $run->error_code);
    }

    public function test_falha_do_provedor_grava_error_code_provider_failed(): void
    {
        $this->bindProvider(new class implements LlmProvider
        {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new LlmFailedException('a rede caiu');
            }
        });

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_failed', $run->error_code);
        // A mensagem crua do provedor nunca vaza para o usuario.
        $this->assertStringNotContainsString('a rede caiu', $run->error);
    }

    /** Cria uma execucao parada no estado dado, sem passar pelo job. */
    private function runEmAndamento(Workspace $workspace, Project $project, User $user, string $status): AiRun
    {
        return AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => $status,
            'input' => [],
            'created_by' => $user->id,
        ]);
    }

    public function test_geracao_concorrente_devolve_409_e_nao_cria_execucao(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->runEmAndamento($workspace, $project, $editor, 'running');

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(409);

        // A execucao em andamento continua sendo a unica.
        $this->assertSame(1, AiRun::withoutGlobalScopes()->count());
        $this->assertSame(0, Strategy::withoutGlobalScopes()->count());
    }

    public function test_execucao_apenas_enfileirada_tambem_bloqueia(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->runEmAndamento($workspace, $project, $editor, 'queued');

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(409);
        $this->assertSame(1, AiRun::withoutGlobalScopes()->count());
    }

    /**
     * A ordem importa: uma execucao em andamento ainda nao gravou `cost_cents`,
     * entao o Budget nao a enxerga. Se o 402 viesse primeiro, o usuario receberia
     * "orcamento esgotado" quando o problema real e que ja existe uma geracao
     * rodando — e resolveria esperando o mes virar, em vez de esperar 10 segundos.
     */
    public function test_409_vem_antes_do_402_quando_as_duas_condicoes_valem(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $run = $this->runEmAndamento($workspace, $project, $editor, 'running');
        $run->update(['cost_cents' => config('ai.workspace_monthly_budget_cents')]);

        Sanctum::actingAs($editor);

        $this->assertTrue(Budget::exceeded($workspace->refresh()));

        $this->generate($project)->assertStatus(409);
    }

    public function test_execucao_terminada_nao_bloqueia_uma_nova(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->runEmAndamento($workspace, $project, $editor, 'succeeded');
        $this->runEmAndamento($workspace, $project, $editor, 'failed');

        Sanctum::actingAs($editor);

        $this->generate($project)->assertStatus(202);
    }

    public function test_execucao_em_andamento_de_outro_projeto_nao_bloqueia(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $meu = Project::factory()->create(['workspace_id' => $workspace->id]);
        $outro = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->runEmAndamento($workspace, $outro, $editor, 'running');

        Sanctum::actingAs($editor);

        $this->generate($meu)->assertStatus(202);
    }

    /**
     * O 409 do controller e uma checagem antes do insert: duas requisicoes
     * simultaneas passariam as duas. O indice parcial unico e a garantia real.
     */
    public function test_o_banco_impede_duas_execucoes_ativas_no_mesmo_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->runEmAndamento($workspace, $project, $editor, 'running');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->runEmAndamento($workspace, $project, $editor, 'queued');
    }

    public function test_saida_fora_das_regras_e_rejeitada_depois_de_duas_tentativas(): void
    {
        // Pilares somando 99: passa no JSON Schema, falha no validate() do agente.
        $provider = new class implements LlmProvider
        {
            public int $chamadas = 0;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->chamadas++;

                return new LlmResponse(
                    output: [
                        'title' => 't',
                        'summary' => 's',
                        'editorial_line' => 'e',
                        'pillars' => [
                            ['name' => 'a', 'weight' => 40, 'description' => 'd'],
                            ['name' => 'b', 'weight' => 35, 'description' => 'd'],
                            ['name' => 'c', 'weight' => 24, 'description' => 'd'],
                        ],
                    ],
                    model: 'claude-opus-4-8',
                    inputTokens: 10,
                    outputTokens: 10,
                );
            }
        };

        $this->bindProvider($provider);

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('rejected_output', $run->error_code);
        $this->assertStringContainsString('A soma dos pesos deve ser 100', $run->error);

        // O job tenta uma segunda vez antes de desistir.
        $this->assertSame(2, $provider->chamadas);

        // Nada foi persistido: a estrategia so nasce depois do validate().
        $this->assertSame(0, Strategy::withoutGlobalScopes()->count());
    }
}
