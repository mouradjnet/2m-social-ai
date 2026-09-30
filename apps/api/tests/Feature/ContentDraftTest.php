<?php

namespace Tests\Feature;

use App\Domain\Publishing\PublishGate;
use App\Enums\WorkspaceRole;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentDraftTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    }

    /** @return array{Workspace, Project} */
    private function scene(): array
    {
        $workspace = Workspace::factory()->create();

        return [$workspace, Project::factory()->create(['workspace_id' => $workspace->id])];
    }

    private function content(Project $project, string $status): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
            'title' => 'Titulo', 'caption' => 'Legenda antiga', 'cta' => 'CTA', 'hashtags' => ['#a'],
            'format' => 'post', 'channel' => 'instagram', 'status' => $status,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_editor_corrige_o_texto_e_a_revisao_guarda_o_de_para(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'review');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        $this->patchJson("/api/v1/contents/{$content->id}/draft", [
            'caption' => 'Legenda nova', 'hashtags' => ['#saude', 'bemestar'],
        ])->assertOk()->assertJsonPath('data.caption', 'Legenda nova');

        $revisao = ContentRevision::where('content_id', $content->id)->sole();
        $this->assertSame('Legenda antiga', $revisao->changes['caption']['from']);
        $this->assertSame(['#saude', 'bemestar'], $revisao->changes['hashtags']['to']);
        $this->assertArrayNotHasKey('title', $revisao->changes);
    }

    public function test_sem_mudanca_nao_grava_revisao(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'idea');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['title' => 'Titulo'])->assertOk();

        $this->assertSame(0, ContentRevision::count());
    }

    /**
     * Aprovada, a peca nao muda o texto. Devolvida e editada, a aprovacao anterior nao
     * cobre o texto novo: o PublishGate recusa ate alguem aprovar de novo.
     */
    public function test_texto_editado_derruba_a_aprovacao_anterior(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'review');
        $reviewer = $this->memberOf($workspace, WorkspaceRole::Reviewer);
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/v1/contents/{$content->id}/approve", ['version' => $content->fresh()->version])->assertOk();
        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['caption' => 'x'])->assertStatus(422);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'review'])->assertOk();
        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['caption' => 'Outra'])->assertOk();

        // Forca a peca a parecer aprovada e agendada sem passar pela aprovacao de novo.
        $content->refresh()->update([
            'status' => 'scheduled', 'scheduled_for' => now(),
            'approved_by' => $reviewer->id, 'approved_at' => now(),
        ]);

        $this->assertSame('A aprovação não vale para esta versão da peça (o conteúdo mudou, ou foi aprovada antes do controle de versões). Aprove de novo.', PublishGate::refusal($content->fresh()));
    }

    public function test_valida_limites_do_instagram(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'production');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['hashtags' => array_fill(0, 31, '#a')])
            ->assertStatus(422)->assertJsonValidationErrors('hashtags');
        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['hashtags' => ['com espaco']])
            ->assertStatus(422);
        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['caption' => str_repeat('a', 2201)])
            ->assertStatus(422)->assertJsonValidationErrors('caption');
    }

    public function test_viewer_nao_edita_e_peca_alheia_nao_existe(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'idea');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Viewer));
        $this->patchJson("/api/v1/contents/{$content->id}/draft", ['title' => 'x'])->assertForbidden();

        $alheia = $this->content(Project::factory()->create(), 'idea');
        $this->patchJson("/api/v1/contents/{$alheia->id}/draft", ['title' => 'x'])->assertNotFound();
    }
}
