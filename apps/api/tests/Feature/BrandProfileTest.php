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

    /** Preenche todos os campos de todos os passos obrigatorios: leva a 100%. */
    private function todosObrigatorios(): array
    {
        return [
            'brand_name' => 'Acme',
            'description' => 'Loja de teste',
            'audience' => 'Clinicas de pequeno porte',
            'persona' => 'Dra. Ana, 40 anos, dona de clinica',
            'tone_of_voice' => 'Direto e acolhedor',
            'differentiators' => 'Atendimento humano e rapido',
            'products' => ['Consulta', 'Exame'],
            'services' => ['Agendamento online'],
        ];
    }

    /** Extrai o bloco de um passo da resposta de completude. */
    private function stepFrom(array $completion, string $id): array
    {
        foreach ($completion['steps'] as $step) {
            if ($step['id'] === $id) {
                return $step;
            }
        }

        throw new \RuntimeException("passo {$id} ausente na resposta");
    }

    public function test_perfil_nasce_vazio_com_zero_por_cento(): void
    {
        $project = $this->actAs(WorkspaceRole::Viewer);

        $response = $this->getJson("/api/v1/projects/{$project->id}/brand-profile")
            ->assertOk()
            ->assertJsonPath('data.brand_name', null)
            ->assertJsonPath('completion.percent', 0);

        $identity = $this->stepFrom($response->json('completion'), 'identity');
        $this->assertFalse($identity['complete']);
        $this->assertTrue($identity['required']);
    }

    public function test_patch_parcial_nao_apaga_os_passos_anteriores(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // identity exige brand_name E description: com so um, nao completa.
        $r1 = $this->patchJson($url, ['brand_name' => 'Acme', 'description' => 'Loja'])
            ->assertOk()
            ->assertJsonPath('completion.percent', 25);
        $this->assertTrue($this->stepFrom($r1->json('completion'), 'identity')['complete']);

        // O passo audience nao menciona brand_name. Ele deve sobreviver.
        $this->patchJson($url, ['audience' => 'Clinicas', 'persona' => 'Dra. Ana'])
            ->assertOk()
            ->assertJsonPath('data.brand_name', 'Acme')
            ->assertJsonPath('completion.percent', 50);
    }

    public function test_wizard_completo_chega_a_cem_por_cento(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        $this->patchJson($url, $this->todosObrigatorios())
            ->assertOk()
            ->assertJsonPath('completion.percent', 100);
    }

    public function test_passo_obrigatorio_com_so_um_campo_nao_completa(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // audience exige audience E persona.
        $response = $this->patchJson($url, ['audience' => 'Clinicas'])->assertOk();

        $this->assertFalse($this->stepFrom($response->json('completion'), 'audience')['complete']);
        $this->assertSame(0, $response->json('completion.percent'));
    }

    public function test_array_vazio_nao_conta_como_preenchido(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // offer exige products E services nao-vazios.
        $response = $this->patchJson($url, ['products' => [], 'services' => []])->assertOk();

        $this->assertFalse($this->stepFrom($response->json('completion'), 'offer')['complete']);
    }

    public function test_offer_completa_com_products_e_services(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        $response = $this->patchJson($url, [
            'products' => ['Carros'],
            'services' => ['Financiamento'],
        ])->assertOk();

        $this->assertTrue($this->stepFrom($response->json('completion'), 'offer')['complete']);
        $this->assertSame(25, $response->json('completion.percent'));
    }

    public function test_passos_opcionais_nao_movem_o_percent(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // Preenche so os dois opcionais: vocabulary e social.
        $response = $this->patchJson($url, [
            'forbidden_words' => ['imperdivel'],
            'website' => 'https://acme.com.br',
        ])->assertOk();

        $completion = $response->json('completion');
        $this->assertSame(0, $completion['percent']);
        $this->assertTrue($this->stepFrom($completion, 'vocabulary')['complete']);
        $this->assertTrue($this->stepFrom($completion, 'social')['complete']);
        $this->assertFalse($this->stepFrom($completion, 'vocabulary')['required']);
        $this->assertFalse($this->stepFrom($completion, 'social')['required']);
    }

    public function test_a_ordem_dos_passos_e_estavel(): void
    {
        $project = $this->actAs(WorkspaceRole::Viewer);

        $response = $this->getJson("/api/v1/projects/{$project->id}/brand-profile")->assertOk();

        $ids = array_column($response->json('completion.steps'), 'id');
        $this->assertSame(
            ['identity', 'audience', 'positioning', 'offer', 'vocabulary', 'social'],
            $ids,
        );
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
