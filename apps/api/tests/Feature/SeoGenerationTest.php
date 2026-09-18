<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoGenerationTest extends TestCase
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
            'title' => 'Titulo do copywriter',
            'caption' => 'Legenda da peca.',
            'cta' => 'Fale com a gente',
            'hashtags' => ['#original'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function generate(Project $project): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/seo:generate");
    }

    private function scene(): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        return [$workspace, $project];
    }

    public function test_propoe_seo_sem_tocar_na_peca(): void
    {
        [, $project] = $this->scene();
        $content = $this->content($project);
        $ideia = $this->content($project, 'idea');

        $this->generate($project)->assertStatus(202)->assertJsonStructure(['ai_run_id']);

        $run = AiRun::first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('seo', $run->agent);

        $seo = ContentSeo::where('content_id', $content->id)->first();
        $this->assertNotNull($seo);
        $this->assertSame($run->id, $seo->ai_run_id);
        $this->assertNull($seo->applied_at);
        $this->assertNotEmpty($seo->keywords);

        // A sugestao NAO e a peca: o titulo continua sendo o do copywriter.
        $content->refresh();
        $this->assertSame('Titulo do copywriter', $content->title);
        $this->assertSame(['#original'], $content->hashtags);
        $this->assertSame('production', $content->status);

        $this->assertSame(0, ContentSeo::where('content_id', $ideia->id)->count());
    }

    public function test_aplicar_grava_o_titulo_as_hashtags_e_a_revisao(): void
    {
        [, $project] = $this->scene();
        $content = $this->content($project);

        $this->generate($project)->assertStatus(202);
        $sugerido = ContentSeo::first()->title;

        $this->postJson("/api/v1/contents/{$content->id}/seo:apply")
            ->assertOk()
            ->assertJsonPath('data.title', $sugerido);

        $content->refresh();
        $this->assertSame($sugerido, $content->title);
        $this->assertSame(['#marca', '#busca'], $content->hashtags);

        // A sugestao aplicada fica marcada.
        $this->assertNotNull(ContentSeo::first()->applied_at);

        // O texto antigo nao some sem rastro: revisao SEM status, com o de-para.
        $revisao = ContentRevision::where('content_id', $content->id)->latest('id')->first();
        $this->assertNull($revisao->from_status);
        $this->assertSame('Titulo do copywriter', $revisao->changes['title']['from']);
        $this->assertSame($sugerido, $revisao->changes['title']['to']);
        $this->assertSame(['#original'], $revisao->changes['hashtags']['from']);
    }

    public function test_aplicar_sem_sugestao_devolve_422(): void
    {
        [, $project] = $this->scene();
        $content = $this->content($project);

        $this->postJson("/api/v1/contents/{$content->id}/seo:apply")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta peça não tem sugestão de SEO para aplicar.');

        $this->assertSame('Titulo do copywriter', $content->fresh()->title);
    }

    public function test_viewer_nao_pode_aplicar(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project);
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Viewer));

        $this->postJson("/api/v1/contents/{$content->id}/seo:apply")->assertStatus(403);
    }

    public function test_sem_peca_em_producao_devolve_422(): void
    {
        [, $project] = $this->scene();
        $this->content($project, 'idea');

        $this->generate($project)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não há peça em produção para otimizar.');

        $this->assertSame(0, AiRun::count());
    }

    public function test_geracao_concorrente_devolve_409(): void
    {
        [$workspace, $project] = $this->scene();
        $this->content($project);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'seo',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'running',
            'input' => [],
            'created_by' => User::factory()->create()->id,
        ]);

        $this->generate($project)->assertStatus(409);
    }

    /** O designer pode estar rodando na mesma coluna: o indice e por-agente. */
    public function test_designer_rodando_nao_barra_o_seo(): void
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
}
