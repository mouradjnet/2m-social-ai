<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Models\Content;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentContextTest extends TestCase
{
    use RefreshDatabase;

    private function content(Project $project, string $status): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => "Peca {$status}",
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

    private function strategy(Project $project, string $status): Strategy
    {
        return Strategy::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => "Estrategia {$status}",
            'summary' => 's',
            'editorial_line' => 'e',
            'pillars' => [['name' => 'a', 'weight' => 100, 'description' => 'd']],
            'status' => $status,
        ]);
    }

    public function test_carrega_a_estrategia_ativa_do_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->strategy($project, 'active');

        $context = AgentContext::forProject($project);

        $this->assertNotNull($context->activeStrategy);
        $this->assertSame('Estrategia active', $context->activeStrategy['title']);
        $this->assertArrayHasKey('active_strategy', $context->toArray());
    }

    public function test_ignora_estrategias_draft_e_archived(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->strategy($project, 'draft');
        $this->strategy($project, 'archived');

        $context = AgentContext::forProject($project);

        $this->assertNull($context->activeStrategy);
    }

    public function test_sem_estrategia_active_e_null(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->assertNull(AgentContext::forProject($project)->activeStrategy);
    }

    public function test_com_janela_carrega_so_as_pecas_aprovadas(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $aprovada = $this->content($project, 'approved');
        $this->content($project, 'idea');
        $this->content($project, 'archived');

        $context = AgentContext::forProject($project, ['starts_on' => '2026-08-01', 'days' => 14]);

        $this->assertSame(['starts_on' => '2026-08-01', 'days' => 14], $context->scheduleWindow);
        $this->assertCount(1, $context->approvedContents);
        $this->assertSame($aprovada->id, $context->approvedContents[0]['id']);

        $data = $context->toArray();
        $this->assertArrayHasKey('schedule_window', $data);
        $this->assertArrayHasKey('approved_contents', $data);
    }

    /**
     * O strategist e o copywriter foram verificados contra a API real. O prompt
     * deles nao pode ganhar chaves — nem custo de tokens — por causa de um agente
     * que eles nao conhecem.
     */
    public function test_sem_janela_o_contexto_nao_muda(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project, 'approved');

        $context = AgentContext::forProject($project);

        $this->assertNull($context->scheduleWindow);
        $this->assertNull($context->approvedContents);
        $this->assertSame(
            ['project_id', 'project_name', 'segment', 'brand_profile', 'active_strategy'],
            array_keys($context->toArray()),
        );
    }
}
