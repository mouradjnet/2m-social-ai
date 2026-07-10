<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O criterio de aceite da Fase 1.
 *
 * A distincao entre 404 e 403 e o ponto: um 403 confirmaria ao atacante que o
 * recurso existe. Um nao-membro deve receber a mesma resposta que receberia
 * para um id inexistente.
 */
class WorkspaceIsolationTest extends TestCase
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

    public function test_usuario_nao_ve_projeto_de_outro_workspace(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/projects/{$target->id}")->assertNotFound();
    }

    public function test_membro_ve_o_proprio_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $user = $this->memberOf($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
    }

    public function test_listagem_nao_vaza_projetos_de_outro_workspace(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $user = $this->memberOf($mine, WorkspaceRole::Viewer);
        Project::factory()->count(2)->create(['workspace_id' => $mine->id]);
        Project::factory()->count(3)->create(['workspace_id' => $theirs->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/workspaces/{$mine->id}/projects")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_nao_membro_recebe_404_ao_listar_projetos_do_workspace(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/workspaces/{$theirs->id}/projects")->assertNotFound();
    }

    public function test_viewer_nao_pode_criar_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->memberOf($workspace, WorkspaceRole::Viewer);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/projects", ['name' => 'Novo'])
            ->assertForbidden();
    }

    public function test_editor_pode_criar_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);

        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/projects", ['name' => 'Novo'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Novo');

        $this->assertDatabaseHas('projects', [
            'workspace_id' => $workspace->id,
            'name' => 'Novo',
        ]);
    }

    public function test_reviewer_esta_acima_de_editor(): void
    {
        $this->assertTrue(WorkspaceRole::Reviewer->atLeast(WorkspaceRole::Editor));
        $this->assertFalse(WorkspaceRole::Editor->atLeast(WorkspaceRole::Reviewer));
    }

    public function test_requisicao_sem_autenticacao_e_rejeitada(): void
    {
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}")->assertUnauthorized();
    }
}
