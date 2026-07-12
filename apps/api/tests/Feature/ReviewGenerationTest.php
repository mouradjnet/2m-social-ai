<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewGenerationTest extends TestCase
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

    private function content(Project $project, string $status = 'review'): Content
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
        return $this->postJson("/api/v1/projects/{$project->id}/review:generate");
    }

    /** Workspace + projeto + editor autenticado. */
    private function scene(): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        return [$workspace, $project];
    }

    public function test_revisa_o_lote_em_revisao_e_nao_move_as_pecas(): void
    {
        [, $project] = $this->scene();
        $a = $this->content($project);
        $b = $this->content($project);
        // Fora da coluna Revisao: nao entra no lote.
        $ideia = $this->content($project, 'idea');

        $this->generate($project)->assertStatus(202)->assertJsonStructure(['ai_run_id']);

        // QUEUE_CONNECTION=sync no phpunit: o job ja rodou.
        $run = AiRun::first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('reviewer', $run->agent);

        $this->assertSame(2, ContentReview::count());
        $this->assertSame(0, ContentReview::where('content_id', $ideia->id)->count());

        // O reviewer nao promove: a IA propoe, o humano promove.
        $this->assertSame('review', $a->fresh()->status);
        $this->assertSame('review', $b->fresh()->status);

        // O mock reprova a primeira e aprova a segunda.
        $primeira = ContentReview::where('content_id', $a->id)->first();
        $this->assertSame('fail', $primeira->verdict);
        $this->assertCount(1, $primeira->violations);
        $this->assertSame('tom de voz', $primeira->violations[0]['rule']);
        $this->assertSame($run->id, $primeira->ai_run_id);

        $segunda = ContentReview::where('content_id', $b->id)->first();
        $this->assertSame('pass', $segunda->verdict);
        $this->assertSame([], $segunda->violations);
    }

    public function test_o_index_devolve_a_ultima_review_de_cada_peca(): void
    {
        [, $project] = $this->scene();
        $content = $this->content($project);

        $this->generate($project)->assertStatus(202);

        $this->getJson("/api/v1/projects/{$project->id}/contents")
            ->assertOk()
            ->assertJsonPath('data.0.latest_review.verdict', 'fail')
            ->assertJsonPath('data.0.latest_review.violations.0.rule', 'tom de voz');
    }

    public function test_peca_nunca_revisada_tem_latest_review_nulo(): void
    {
        [, $project] = $this->scene();
        $this->content($project, 'idea');

        $this->getJson("/api/v1/projects/{$project->id}/contents")
            ->assertOk()
            ->assertJsonPath('data.0.latest_review', null);
    }

    public function test_sem_peca_em_revisao_devolve_422(): void
    {
        [, $project] = $this->scene();
        $this->content($project, 'idea');

        $this->generate($project)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nao ha peca em revisao para revisar.');

        $this->assertSame(0, AiRun::count());
    }

    public function test_revisao_concorrente_devolve_409(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'reviewer',
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

    public function test_viewer_nao_pode_revisar(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Viewer));

        $this->generate($project)->assertStatus(403);
    }
}
