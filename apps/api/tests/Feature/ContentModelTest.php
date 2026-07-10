<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_uma_peca_com_hashtags_como_array(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $user = User::factory()->create();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Peca de teste',
            'caption' => 'Legenda',
            'cta' => 'Fale conosco',
            'hashtags' => ['#carros', '#recife'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => 'idea',
            'source' => 'ai',
            'created_by' => $user->id,
        ]);

        $this->assertSame(['#carros', '#recife'], $content->fresh()->hashtags);
        $this->assertDatabaseHas('contents', ['id' => $content->id, 'source' => 'ai']);
    }
}
