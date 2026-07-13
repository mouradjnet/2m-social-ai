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
 * `PATCH /projects/{project}` — na pratica, corrigir o FUSO.
 *
 * O fuso e do PROJETO (uma agencia atende marcas em fusos diferentes) mas so podia
 * ser dito na CRIACAO: todo projeto existente ficou preso no default, sem tela para
 * mudar — inclusive o 2F AutoShop, em producao. E o `social_media` agenda no fuso do
 * projeto, entao o fuso errado publica na madrugada do publico (ja aconteceu: ver
 * 02ca459).
 */
class ProjectUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function membro(Workspace $workspace, WorkspaceRole $role): User
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

    public function test_editor_corrige_o_fuso_do_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'timezone' => 'America/Sao_Paulo',
        ]);

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/projects/{$project->id}", ['timezone' => 'America/Manaus'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'America/Manaus');

        $this->assertSame('America/Manaus', $project->refresh()->timezone);
    }

    /** Merge parcial: o que nao foi mandado nao e apagado. */
    public function test_campo_omitido_fica_como_estava(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Nome original',
            'segment' => 'Automotivo',
        ]);

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/projects/{$project->id}", ['timezone' => 'Europe/Lisbon'])
            ->assertOk();

        $project->refresh();

        $this->assertSame('Nome original', $project->name);
        $this->assertSame('Automotivo', $project->segment);
        $this->assertSame('Europe/Lisbon', $project->timezone);
    }

    /**
     * Fuso invalido e 422, nao 500 nem uma coluna com lixo: o `social_media` faz
     * conta com este valor, e uma string qualquer quebraria a geracao — la, longe
     * daqui, dentro de um job.
     */
    public function test_fuso_invalido_e_recusado(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'timezone' => 'America/Sao_Paulo',
        ]);

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/projects/{$project->id}", ['timezone' => 'Marte/Olympus'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('timezone');

        $this->assertSame('America/Sao_Paulo', $project->refresh()->timezone);
    }

    /** `viewer` LE. Trocar o fuso remarca o calendario inteiro do proximo lote. */
    public function test_viewer_nao_edita_o_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->membro($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'timezone' => 'America/Sao_Paulo',
        ]);

        Sanctum::actingAs($viewer);

        $this->patchJson("/api/v1/projects/{$project->id}", ['timezone' => 'America/Manaus'])
            ->assertForbidden();

        $this->assertSame('America/Sao_Paulo', $project->refresh()->timezone);
    }

    public function test_projeto_de_outro_workspace_nao_existe(): void
    {
        $meu = Workspace::factory()->create();
        $alheio = Workspace::factory()->create();
        $intruso = $this->membro($meu, WorkspaceRole::Owner);
        $alvo = Project::factory()->create([
            'workspace_id' => $alheio->id,
            'timezone' => 'America/Sao_Paulo',
        ]);

        Sanctum::actingAs($intruso);

        $this->patchJson("/api/v1/projects/{$alvo->id}", ['timezone' => 'America/Manaus'])
            ->assertNotFound();

        $this->assertSame('America/Sao_Paulo', $alvo->refresh()->timezone);
    }

    public function test_sem_autenticacao_nao_passa(): void
    {
        $project = Project::factory()->create();

        $this->patchJson("/api/v1/projects/{$project->id}", ['timezone' => 'America/Manaus'])
            ->assertUnauthorized();
    }
}
