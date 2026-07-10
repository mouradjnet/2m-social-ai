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

class BrandProfileTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(WorkspaceRole $role): Project
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return Project::factory()->create(['workspace_id' => $workspace->id]);
    }

    public function test_perfil_nasce_vazio_com_zero_por_cento(): void
    {
        $project = $this->actAs(WorkspaceRole::Viewer);

        $this->getJson("/api/v1/projects/{$project->id}/brand-profile")
            ->assertOk()
            ->assertJsonPath('data.brand_name', null)
            ->assertJsonPath('completion.percent', 0)
            ->assertJsonPath('completion.identity', false);
    }

    public function test_patch_parcial_nao_apaga_os_passos_anteriores(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        $this->patchJson($url, ['brand_name' => 'Acme'])
            ->assertOk()
            ->assertJsonPath('completion.identity', true)
            ->assertJsonPath('completion.percent', 25);

        // O passo 2 nao menciona brand_name. Ele deve sobreviver.
        $this->patchJson($url, ['audience' => 'Clinicas de pequeno porte'])
            ->assertOk()
            ->assertJsonPath('data.brand_name', 'Acme')
            ->assertJsonPath('completion.audience', true)
            ->assertJsonPath('completion.percent', 50);
    }

    public function test_wizard_completo_chega_a_cem_por_cento(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        $this->patchJson($url, ['brand_name' => 'Acme']);
        $this->patchJson($url, ['audience' => 'Clinicas', 'persona' => 'Dra. Ana']);
        $this->patchJson($url, ['tone_of_voice' => 'Direto e acolhedor']);

        $this->patchJson($url, ['website' => 'https://acme.com.br'])
            ->assertOk()
            ->assertJsonPath('completion.percent', 100);
    }

    public function test_viewer_nao_pode_editar_o_perfil(): void
    {
        $project = $this->actAs(WorkspaceRole::Viewer);

        $this->patchJson("/api/v1/projects/{$project->id}/brand-profile", ['brand_name' => 'Acme'])
            ->assertForbidden();
    }

    public function test_perfil_de_outro_workspace_devolve_404(): void
    {
        $this->actAs(WorkspaceRole::Owner);
        $alheio = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$alheio->id}/brand-profile")->assertNotFound();
        $this->patchJson("/api/v1/projects/{$alheio->id}/brand-profile", ['brand_name' => 'x'])
            ->assertNotFound();
    }

    public function test_url_sem_https_e_rejeitada(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);

        $this->patchJson("/api/v1/projects/{$project->id}/brand-profile", ['website' => 'acme'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('website');
    }

    public function test_campos_jsonb_persistem_como_array(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);

        $this->patchJson("/api/v1/projects/{$project->id}/brand-profile", [
            'forbidden_words' => ['barato', 'promocao'],
            'competitors' => [['name' => 'Rival', 'url' => 'https://rival.com']],
        ])->assertOk()
            ->assertJsonPath('data.forbidden_words', ['barato', 'promocao'])
            ->assertJsonPath('data.competitors.0.name', 'Rival');
    }
}
