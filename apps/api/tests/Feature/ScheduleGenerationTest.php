<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleGenerationTest extends TestCase
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

    private function content(Project $project, string $status = 'approved'): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Peca',
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function generate(Project $project, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/schedule:generate", $body);
    }

    /** Workspace + projeto + editor autenticado. */
    private function scene(): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        return [$workspace, $project];
    }

    public function test_agenda_as_pecas_aprovadas_e_registra_a_revisao(): void
    {
        [, $project] = $this->scene();
        $a = $this->content($project);
        $b = $this->content($project);
        // Nao aprovada: nao entra no lote.
        $ideia = $this->content($project, 'idea');

        $response = $this->generate($project, ['starts_on' => '2026-08-01', 'days' => 14]);

        $response->assertStatus(202)->assertJsonStructure(['ai_run_id']);

        // QUEUE_CONNECTION=sync no phpunit: o job ja rodou.
        $this->assertSame('succeeded', AiRun::first()->status);

        foreach ([$a, $b] as $content) {
            $content->refresh();
            $this->assertSame('scheduled', $content->status);
            $this->assertNotNull($content->scheduled_for);
            $this->assertTrue($content->scheduled_for->between('2026-08-01', '2026-08-15'));
        }

        $this->assertSame('idea', $ideia->refresh()->status);
        $this->assertNull($ideia->scheduled_for);

        $revisoes = ContentRevision::whereIn('content_id', [$a->id, $b->id])->get();
        $this->assertCount(2, $revisoes);
        $this->assertSame('approved', $revisoes[0]->from_status);
        $this->assertSame('scheduled', $revisoes[0]->to_status);
    }

    public function test_a_janela_tem_default_quando_o_corpo_vem_vazio(): void
    {
        [, $project] = $this->scene();
        $content = $this->content($project);

        $this->generate($project)->assertStatus(202);

        $content->refresh();
        $this->assertSame('scheduled', $content->status);
        // Default: comeca amanha e dura 14 dias.
        $this->assertTrue($content->scheduled_for->gte(now()->addDay()->startOfDay()));
        $this->assertTrue($content->scheduled_for->lt(now()->addDays(15)->startOfDay()));
    }

    public function test_sem_peca_aprovada_devolve_422(): void
    {
        [, $project] = $this->scene();
        $this->content($project, 'idea');

        $this->generate($project)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Aprove pelo menos uma peca antes de agendar.');

        $this->assertSame(0, AiRun::count());
    }

    public function test_geracao_concorrente_do_mesmo_agente_devolve_409(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'social_media',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'running',
            'input' => [],
            'created_by' => auth()->id() ?? User::factory()->create()->id,
        ]);

        $this->generate($project)->assertStatus(409);
    }

    /** O indice de run ativo e por-agente: uma copy rodando nao barra o agendamento. */
    public function test_geracao_de_outro_agente_nao_barra(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'copywriter',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'running',
            'input' => [],
            'created_by' => User::factory()->create()->id,
        ]);

        $this->generate($project)->assertStatus(202);
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

    public function test_days_fora_do_intervalo_e_recusado(): void
    {
        [, $project] = $this->scene();
        $this->content($project);

        $this->generate($project, ['days' => 0])->assertStatus(422);
        $this->generate($project, ['days' => 90])->assertStatus(422);
        $this->assertSame(0, AiRun::count());
    }

    public function test_viewer_nao_pode_agendar(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Viewer));

        $this->generate($project)->assertStatus(403);
    }
}
