<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\AnalyticsReport;
use App\Models\Content;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsGenerationTest extends TestCase
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

    private function content(Project $project, array $overrides = []): Content
    {
        return Content::create(array_merge([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Peca',
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => [],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => 'idea',
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ], $overrides));
    }

    private function generate(Project $project): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/analytics:generate");
    }

    private function scene(): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        return [$workspace, $project];
    }

    public function test_gera_o_relatorio_com_pontuacao_insights_e_o_snapshot_das_metricas(): void
    {
        [, $project] = $this->scene();
        $this->content($project, ['pillar' => 'Educacao']);
        $this->content($project, ['status' => 'approved']);

        $this->generate($project)->assertStatus(202)->assertJsonStructure(['ai_run_id']);

        $run = AiRun::first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('analytics', $run->agent);

        $report = AnalyticsReport::first();
        $this->assertSame(72, $report->score);
        $this->assertNotEmpty($report->insights);
        $this->assertArrayHasKey('action', $report->insights[0]);

        // O snapshot: o relatorio continua explicavel mesmo depois de o calendario mudar.
        $this->assertSame(2, $report->metrics['volume']['total']);
    }

    public function test_o_show_devolve_o_ultimo_relatorio_e_os_numeros_de_agora(): void
    {
        [, $project] = $this->scene();
        $this->content($project);

        $this->getJson("/api/v1/projects/{$project->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('metrics.volume.total', 1);

        $this->generate($project)->assertStatus(202);

        $this->getJson("/api/v1/projects/{$project->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.score', 72)
            ->assertJsonPath('metrics.volume.total', 1);
    }

    public function test_sem_conteudo_devolve_422(): void
    {
        [, $project] = $this->scene();

        $this->generate($project)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não há conteúdo para analisar.');

        $this->assertSame(0, AiRun::count());
    }

    public function test_analise_concorrente_devolve_409(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'analytics',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'running',
            'input' => [],
            'created_by' => User::factory()->create()->id,
        ]);

        $this->generate($project)->assertStatus(409);
    }

    public function test_orcamento_estourado_devolve_402(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'cost_cents' => config('ai.workspace_monthly_budget_cents') + 1,
            'created_by' => User::factory()->create()->id,
        ]);

        $this->generate($project)->assertStatus(402);
    }

    public function test_viewer_nao_pode_gerar_relatorio(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Viewer));

        $this->generate($project)->assertStatus(403);
    }

    /** O copywriter agora grava o pilar — sem ele nao ha aderencia. */
    public function test_o_copywriter_persiste_o_pilar(): void
    {
        [, $project] = $this->scene();

        Strategy::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'E', 'summary' => 's', 'editorial_line' => 'e',
            'pillars' => [['name' => 'Educacao', 'weight' => 100, 'description' => 'd']],
            'status' => 'active',
        ]);

        $this->postJson("/api/v1/projects/{$project->id}/copy:generate")->assertStatus(202);

        $this->assertSame(5, Content::whereNotNull('pillar')->count());
        $this->assertContains(Content::first()->pillar, ['Educacao', 'Prova social', 'Bastidores']);
    }
}
