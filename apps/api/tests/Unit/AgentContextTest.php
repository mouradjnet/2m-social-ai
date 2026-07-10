<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentContextTest extends TestCase
{
    use RefreshDatabase;

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
}
