<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesignGenerationTest extends TestCase
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

    private function content(Project $project, string $status = 'production'): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Peca',
            'caption' => 'Legenda da peca.',
            'cta' => 'Fale com a gente',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function generate(Project $project): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/design:generate");
    }

    private function scene(): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        return [$workspace, $project];
    }

    public function test_escreve_o_image_prompt_do_lote_e_nao_move_as_pecas(): void
    {
        [, $project] = $this->scene();
        $a = $this->content($project);
        $b = $this->content($project);
        // Fora da coluna Producao: nao entra no lote.
        $ideia = $this->content($project, 'idea');

        $this->generate($project)->assertStatus(202)->assertJsonStructure(['ai_run_id']);

        $run = AiRun::first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('designer', $run->agent);

        foreach ([$a, $b] as $content) {
            $content->refresh();
            $this->assertNotNull($content->image_prompt);
            $this->assertStringContainsString('emerald', $content->image_prompt);
            // O designer nao promove.
            $this->assertSame('production', $content->status);
        }

        $this->assertNull($ideia->refresh()->image_prompt);
    }

    public function test_o_index_devolve_o_image_prompt(): void
    {
        [, $project] = $this->scene();
        $this->content($project);

        $this->generate($project)->assertStatus(202);

        $this->getJson("/api/v1/projects/{$project->id}/contents")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'production');

        $this->assertNotNull(Content::first()->image_prompt);
    }

    public function test_sem_peca_em_producao_devolve_422(): void
    {
        [, $project] = $this->scene();
        $this->content($project, 'idea');

        $this->generate($project)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nao ha peca em producao para desenhar.');

        $this->assertSame(0, AiRun::count());
    }

    public function test_geracao_concorrente_devolve_409(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'designer',
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

    public function test_viewer_nao_pode_gerar_imagens(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Viewer));

        $this->generate($project)->assertStatus(403);
    }
}
