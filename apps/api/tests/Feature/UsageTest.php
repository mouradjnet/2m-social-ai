<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageTest extends TestCase
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

    private function execucao(Workspace $workspace, ?Project $project, User $user, string $agent, ?int $cents, $quando = null): AiRun
    {
        $run = AiRun::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project?->id,
            'agent' => $agent,
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'cost_cents' => $cents,
            'created_by' => $user->id,
        ]);

        if ($quando !== null) {
            $run->forceFill(['created_at' => $quando])->save();
        }

        return $run;
    }

    public function test_admin_ve_o_consumo_do_mes_por_agente_e_por_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->memberOf($workspace, WorkspaceRole::Admin);
        $a = Project::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Saúde']);
        $b = Project::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Loja']);

        $this->execucao($workspace, $a, $admin, 'copywriter', 6);
        $this->execucao($workspace, $a, $admin, 'copywriter', 9);
        $this->execucao($workspace, $b, $admin, 'reviewer', 4);
        // Sem custo (falhou antes de chamar a API) conta como execucao, com 0.
        $this->execucao($workspace, $b, $admin, 'reviewer', null);
        // Mes passado e outro workspace ficam de fora.
        $this->execucao($workspace, $a, $admin, 'copywriter', 500, now()->subMonthNoOverflow()->startOfMonth());
        $outro = Workspace::factory()->create();
        $this->execucao($outro, null, $admin, 'strategist', 700);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.month', now()->format('Y-m'))
            ->assertJsonPath('data.spent_cents', 19)
            ->assertJsonPath('data.limit_cents', 5000)
            ->assertJsonPath('data.limit_source', 'default')
            ->assertJsonPath('data.by_agent', [
                ['agent' => 'copywriter', 'runs' => 2, 'cost_cents' => 15],
                ['agent' => 'reviewer', 'runs' => 2, 'cost_cents' => 4],
            ])
            ->assertJsonPath('data.by_project.0.name', 'Saúde')
            ->assertJsonPath('data.by_project.0.cost_cents', 15)
            ->assertJsonPath('data.by_project.1.name', 'Loja')
            ->assertJsonPath('data.by_project.1.runs', 2);
    }

    public function test_editor_nao_ve_o_consumo_e_quem_e_de_fora_nao_ve_o_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));
        $this->getJson("/api/v1/workspaces/{$workspace->id}/usage")->assertForbidden();

        Sanctum::actingAs($this->memberOf(Workspace::factory()->create(), WorkspaceRole::Owner));
        $this->getJson("/api/v1/workspaces/{$workspace->id}/usage")->assertNotFound();
    }

    public function test_teto_do_workspace_vence_o_padrao_e_barra_a_geracao(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->execucao($workspace, $project, $editor, 'copywriter', 100);

        // Com o padrao (5000) ainda ha folga; o operador baixa o teto para 100.
        $this->artisan('workspace:budget', ['workspace' => $workspace->slug, 'cents' => 100])
            ->expectsOutputToContain('gasto 100¢ de 100¢')
            ->assertSuccessful();

        Sanctum::actingAs($editor);
        $this->postJson("/api/v1/projects/{$project->id}/strategies:generate")
            ->assertStatus(402)
            ->assertJsonPath('limit_cents', 100);

        $this->artisan('workspace:budget', ['workspace' => (string) $workspace->id, '--default' => true])
            ->expectsOutputToContain('teto padrão')
            ->assertSuccessful();
        $this->assertNull($workspace->fresh()->monthly_budget_cents);
        $this->postJson("/api/v1/projects/{$project->id}/strategies:generate")->assertStatus(202);
    }

    public function test_o_teto_nao_entra_por_atribuicao_em_massa(): void
    {
        $workspace = Workspace::create([
            'name' => 'X', 'slug' => 'x-1', 'owner_id' => User::factory()->create()->id,
            'monthly_budget_cents' => 999999,
        ]);

        $this->assertNull($workspace->fresh()->monthly_budget_cents);
    }

    public function test_comando_recusa_teto_que_nao_e_numero(): void
    {
        $workspace = Workspace::factory()->create();

        $this->artisan('workspace:budget', ['workspace' => $workspace->slug, 'cents' => '30 dolares'])->assertFailed();
        $this->artisan('workspace:budget', ['workspace' => 'nao-existe'])->assertFailed();
    }
}
