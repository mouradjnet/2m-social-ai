<?php

namespace Tests\Feature;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\CopywriterAgent;
use App\Ai\Agents\PlannerAgent;
use App\Ai\Exceptions\OutputRejectedException;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentPlan;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Roteiro;
use Tests\TestCase;

/**
 * Etapa 2c: o planner monta a semana (dia, hora, pilar, formato, tema) e o
 * copywriter escreve uma peca por horario. Agendar continua sendo do humano.
 */
class WeekPlanTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $editor;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->editor = $this->memberOf(WorkspaceRole::Editor);
        $this->strategy = $this->estrategia();
        Sanctum::actingAs($this->editor);
    }

    private function memberOf(WorkspaceRole $role, ?Workspace $workspace = null): User
    {
        $user = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function estrategia(string $status = 'active'): Strategy
    {
        return Strategy::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Estratégia',
            'pillars' => [
                ['name' => 'Educação em saúde', 'weight' => 60, 'description' => 'x'],
                ['name' => 'Prevenção e exames', 'weight' => 40, 'description' => 'y'],
            ],
            'status' => $status,
        ]);
    }

    private function amanha(): string
    {
        return CarbonImmutable::now('America/Sao_Paulo')->addDay()->toDateString();
    }

    private function planejar(array $body = []): ?ContentPlan
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/week-plan:generate", [
            'starts_on' => $this->amanha(), 'posts' => 3, ...$body,
        ])->assertStatus(202);

        return ContentPlan::latest('id')->first();
    }

    public function test_planner_monta_a_semana_e_o_plano_fica_visivel(): void
    {
        $plano = $this->planejar();

        $run = AiRun::where('agent', 'planner')->sole();
        $this->assertSame('succeeded', $run->status, (string) $run->error);
        $this->assertSame($this->strategy->id, $plano->strategy_id);
        $this->assertSame(3, $plano->posts_count);
        $this->assertSame($this->amanha(), $plano->period_start->toDateString());
        $this->assertCount(3, $plano->slots());
        // Em ordem cronologica: e a ordem em que o copywriter escreve.
        $this->assertSame($this->amanha(), $plano->slots()[0]['date']);

        $this->getJson("/api/v1/projects/{$this->project->id}/week-plan")
            ->assertOk()
            ->assertJsonPath('data.id', $plano->id)
            ->assertJsonPath('data.contents_count', 0)
            ->assertJsonCount(3, 'data.distribution.slots');
    }

    public function test_copywriter_escreve_uma_peca_por_horario_com_a_hora_do_plano(): void
    {
        $plano = $this->planejar();

        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(202);

        $pecas = Content::where('content_plan_id', $plano->id)->orderBy('id')->get();
        $this->assertCount(3, $pecas);

        foreach ($plano->slots() as $i => $slot) {
            $this->assertSame($slot['pillar'], $pecas[$i]->pillar);
            $this->assertSame($slot['format'], $pecas[$i]->format);
            $this->assertSame('idea', $pecas[$i]->status);
            // 19:00 em Sao Paulo = 22:00 UTC. A hora do plano e local.
            $this->assertSame(
                CarbonImmutable::parse("{$slot['date']} {$slot['time']}", 'America/Sao_Paulo')->utc()->toIso8601String(),
                $pecas[$i]->planned_for->toIso8601String(),
            );
            $this->assertNull($pecas[$i]->scheduled_for);
        }

        // Escrever o mesmo plano duas vezes duplicaria a semana.
        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(409);
        $this->getJson("/api/v1/projects/{$this->project->id}/week-plan")->assertJsonPath('data.contents_count', 3);
    }

    /** Um post publicado, com a coleta da Meta (ou sem, se `$metricas` for null). */
    private function publicado(string $titulo, string $pilar, string $formato, ?array $metricas): void
    {
        $conta = InstagramAccount::firstOrCreate(['project_id' => $this->project->id], [
            'workspace_id' => $this->workspace->id, 'ig_user_id' => '1784', 'username' => 'marca',
            'account_type' => 'BUSINESS', 'access_token' => 't', 'token_expires_at' => now()->addDays(50),
            'scopes' => [], 'status' => 'active', 'connected_by' => $this->editor->id, 'connected_at' => now(),
        ]);
        $c = Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'title' => $titulo,
            'pillar' => $pilar, 'hashtags' => [], 'format' => $formato, 'channel' => 'instagram',
            'status' => 'published', 'created_by' => $this->editor->id,
        ]);
        $p = Publication::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'content_id' => $c->id,
            'instagram_account_id' => $conta->id, 'caption' => 'x', 'scheduled_for' => now()->subDays(3),
            'status' => 'published', 'media_id' => "m{$c->id}", 'published_at' => now()->subDays(3),
        ]);

        if ($metricas !== null) {
            PublicationMetric::create(['publication_id' => $p->id, 'metrics' => $metricas, 'collected_at' => now()]);
        }
    }

    public function test_planner_recebe_os_resultados_reais_quando_ha_amostra(): void
    {
        $this->publicado('Mitos do preventivo', 'Educação em saúde', 'carousel', ['reach' => 2000, 'total_interactions' => 180]);
        $this->publicado('Agenda de exames', 'Prevenção e exames', 'post', ['reach' => 800, 'total_interactions' => 20]);
        $this->publicado('Rotina de autocuidado', 'Educação em saúde', 'reel', ['reach' => 1500, 'total_interactions' => 90]);
        $this->publicado('Sem numero ainda', 'Prevenção e exames', 'post', null);

        $this->planejar();

        $run = AiRun::where('agent', 'planner')->sole();
        $resultados = $run->input['results'];
        $this->assertSame(3, $resultados['measured']);
        // O melhor post por interacoes vem primeiro, e o pendente nao entra na media.
        $this->assertSame('Mitos do preventivo', $resultados['top'][0]['title']);
        $this->assertSame(['carousel', 'reel', 'post'], array_column($resultados['by_format'], 'name'));
        // A lista post a post fica fora: o planner le o resumo, nao o extrato.
        $this->assertArrayNotHasKey('posts', $resultados);

        $mensagem = (new PlannerAgent)->userMessage(AgentContext::forProject($this->project, $run->input));
        $this->assertStringContainsString('"results"', $mensagem);
        $this->assertStringContainsString('Mitos do preventivo', $mensagem);
    }

    public function test_planner_sem_amostra_minima_nao_recebe_resultados(): void
    {
        $this->publicado('A', 'Educação em saúde', 'post', ['reach' => 100, 'total_interactions' => 5]);
        $this->publicado('B', 'Prevenção e exames', 'post', ['reach' => 100, 'total_interactions' => 5]);

        $this->planejar();

        // Com 2 posts medidos a comparacao seria palpite (mesmo minimo do agente results).
        $this->assertArrayNotHasKey('results', AiRun::where('agent', 'planner')->sole()->input);
    }

    public function test_plano_de_estrategia_que_saiu_de_cena_nao_e_escrito(): void
    {
        $plano = $this->planejar();
        $this->strategy->update(['status' => 'archived']);
        $this->estrategia();

        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'não está mais ativa'));
    }

    public function test_plano_de_outro_projeto_nao_e_escrito_aqui(): void
    {
        $outro = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $alheia = Strategy::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $outro->id,
            'title' => 'x', 'pillars' => [], 'status' => 'active',
        ]);
        $plano = ContentPlan::create(['strategy_id' => $alheia->id, 'period_start' => $this->amanha(), 'period_end' => $this->amanha(), 'distribution' => ['slots' => []]]);

        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(422);
    }

    public function test_semana_no_passado_sem_estrategia_ou_posts_demais_e_recusada(): void
    {
        $url = "/api/v1/projects/{$this->project->id}/week-plan:generate";

        $this->postJson($url, ['starts_on' => CarbonImmutable::now('America/Sao_Paulo')->subDay()->toDateString()])
            ->assertStatus(422);
        $this->postJson($url, ['starts_on' => $this->amanha(), 'posts' => 8])->assertStatus(422);

        $this->strategy->update(['status' => 'archived']);
        $this->postJson($url, ['starts_on' => $this->amanha()])->assertStatus(422);

        $this->assertSame(0, AiRun::count());
    }

    public function test_planejamento_em_andamento_devolve_409(): void
    {
        AiRun::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'agent' => 'planner', 'provider' => 'mock', 'model' => 'x', 'status' => 'running',
            'input' => [], 'created_by' => $this->editor->id,
        ]);

        $this->postJson("/api/v1/projects/{$this->project->id}/week-plan:generate", ['starts_on' => $this->amanha()])
            ->assertStatus(409);
    }

    /** CP-03: sem `posts`, vale a frequencia semanal que a estrategia aprovada recomendou. */
    public function test_sem_numero_de_posts_usa_a_frequencia_da_estrategia(): void
    {
        $this->strategy->update(['guidelines' => ['weekly_frequency' => 5]]);

        $this->postJson("/api/v1/projects/{$this->project->id}/week-plan:generate", ['starts_on' => $this->amanha()])
            ->assertStatus(202);

        $plano = ContentPlan::latest('id')->first();
        $this->assertSame(5, $plano->posts_count);
        // Cada horario traz objetivo e CTA.
        foreach ($plano->slots() as $slot) {
            $this->assertNotSame('', $slot['objective']);
            $this->assertNotSame('', $slot['cta']);
        }
    }

    /** CP-03: o horario de uma peca ja planejada nao e oferecido de novo. */
    public function test_planejamento_evita_horario_ja_ocupado(): void
    {
        $ocupado = CarbonImmutable::parse($this->amanha().' 19:00', 'America/Sao_Paulo');
        Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'title' => 'Já marcada', 'format' => 'post', 'channel' => 'instagram',
            'status' => 'idea', 'planned_for' => $ocupado->utc(), 'created_by' => $this->editor->id,
        ]);

        $plano = $this->planejar();

        $this->assertSame('succeeded', AiRun::where('agent', 'planner')->sole()->status);
        $horarios = array_map(fn ($s) => "{$s['date']} {$s['time']}", $plano->slots());
        $this->assertNotContains($this->amanha().' 19:00', $horarios);
    }

    public function test_quem_e_de_fora_nao_ve_o_plano(): void
    {
        $this->planejar();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Owner, Workspace::factory()->create()));
        $this->getJson("/api/v1/projects/{$this->project->id}/week-plan")->assertNotFound();
    }

    // --- As regras que o prompt manda e o validate() confere ---

    private function contexto(array $extra = []): AgentContext
    {
        return new AgentContext(...[
            'projectId' => 1,
            'projectName' => 'x',
            'segment' => null,
            'brandProfile' => [],
            'activeStrategy' => ['pillars' => [['name' => 'A', 'weight' => 50], ['name' => 'B', 'weight' => 50]]],
            ...$extra,
        ]);
    }

    private function slot(array $extra = []): array
    {
        return ['date' => '2026-10-05', 'time' => '19:00', 'pillar' => 'A', 'format' => 'post', 'channel' => 'instagram', 'theme' => 't', 'objective' => 'Educar', 'cta' => 'Salve este post', 'rationale' => 'r', ...$extra];
    }

    public function test_planner_recusa_data_fora_da_semana_hora_repetida_e_pilar_inventado(): void
    {
        $ctx = $this->contexto(['weekWindow' => ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-11', 'posts' => 2, 'timezone' => 'America/Sao_Paulo']]);
        $agent = new PlannerAgent;

        $agent->validate(['summary' => 's', 'slots' => [$this->slot(), $this->slot(['date' => '2026-10-11'])]], $ctx);

        foreach ([
            [$this->slot(), $this->slot(['date' => '2026-10-12'])],
            [$this->slot(), $this->slot()],
            [$this->slot(), $this->slot(['date' => '2026-10-06', 'pillar' => 'Inventado'])],
            [$this->slot(), $this->slot(['date' => '2026-10-06', 'time' => '24:00'])],
            [$this->slot()],
        ] as $slots) {
            try {
                $agent->validate(['summary' => 's', 'slots' => $slots], $ctx);
                $this->fail('Devia recusar: '.json_encode($slots));
            } catch (OutputRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** CP-03: objetivo e CTA por horario, e nada em cima de horario ja ocupado. */
    public function test_planner_recusa_horario_ocupado_e_horario_sem_objetivo_ou_cta(): void
    {
        $ctx = $this->contexto(['weekWindow' => [
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-11', 'posts' => 1,
            'timezone' => 'America/Sao_Paulo', 'taken' => ['2026-10-05 19:00'],
        ]]);
        $agent = new PlannerAgent;

        $agent->validate(['summary' => 's', 'slots' => [$this->slot(['time' => '20:00'])]], $ctx);

        foreach ([
            [$this->slot()],
            [$this->slot(['time' => '20:00', 'objective' => ' '])],
            [$this->slot(['time' => '20:00', 'cta' => ''])],
        ] as $slots) {
            try {
                $agent->validate(['summary' => 's', 'slots' => $slots], $ctx);
                $this->fail('Devia recusar: '.json_encode($slots));
            } catch (OutputRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_planner_nao_deixa_de_fora_o_pilar_mais_atrasado(): void
    {
        $ctx = $this->contexto([
            'weekWindow' => ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-11', 'posts' => 1, 'timezone' => 'America/Sao_Paulo'],
            'pillarAdherence' => ['pilares' => [['nome' => 'A', 'desvio' => 10], ['nome' => 'B', 'desvio' => -30]]],
        ]);

        $this->expectException(OutputRejectedException::class);
        (new PlannerAgent)->validate(['summary' => 's', 'slots' => [$this->slot(['pillar' => 'A'])]], $ctx);
    }

    public function test_copywriter_com_plano_nao_muda_pilar_formato_nem_canal(): void
    {
        $ctx = $this->contexto(['planSlots' => [$this->slot(), $this->slot(['pillar' => 'B', 'format' => 'carousel'])]]);
        $peca = fn (array $extra = []) => ['title' => uniqid(), 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram', 'pillar' => 'A', 'structure' => Roteiro::valido(), ...$extra];
        $agent = new CopywriterAgent(5);

        // Com plano, o tamanho do lote e o do plano (2), nao o batch_size (5).
        $agent->validate(['pieces' => [$peca(), $peca(['pillar' => 'B', 'format' => 'carousel'])]], $ctx);

        $this->expectException(OutputRejectedException::class);
        $agent->validate(['pieces' => [$peca(), $peca(['pillar' => 'B', 'format' => 'post'])]], $ctx);
    }
}
