<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function content(Project $project, string $status = 'idea'): Content
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

    public function test_revisao_e_append_only_sem_updated_at(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project);
        $user = User::factory()->create();

        $rev = ContentRevision::create([
            'content_id' => $content->id,
            'user_id' => $user->id,
            'from_status' => 'idea',
            'to_status' => 'production',
        ]);

        $this->assertFalse($rev->timestamps);
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'from_status' => 'idea',
            'to_status' => 'production',
        ]);
    }

    public function test_project_lista_seus_contents(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        $this->content($project);

        $this->assertCount(2, $project->contents()->get());
    }
}
