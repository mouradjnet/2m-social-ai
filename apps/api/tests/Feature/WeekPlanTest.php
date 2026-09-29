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
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
        return ['date' => '2026-10-05', 'time' => '19:00', 'pillar' => 'A', 'format' => 'post', 'channel' => 'instagram', 'theme' => 't', 'rationale' => 'r', ...$extra];
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
        $peca = fn (array $extra = []) => ['title' => uniqid(), 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram', 'pillar' => 'A', ...$extra];
        $agent = new CopywriterAgent(5);

        // Com plano, o tamanho do lote e o do plano (2), nao o batch_size (5).
        $agent->validate(['pieces' => [$peca(), $peca(['pillar' => 'B', 'format' => 'carousel'])]], $ctx);

        $this->expectException(OutputRejectedException::class);
        $agent->validate(['pieces' => [$peca(), $peca(['pillar' => 'B', 'format' => 'post'])]], $ctx);
    }
}
