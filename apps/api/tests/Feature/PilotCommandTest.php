<?php

namespace Tests\Feature;

use App\Console\Commands\PreparePilot2mSaudeFeminina as Piloto;
use App\Enums\WorkspaceRole;
use App\Models\Content;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotCommandTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        // A factory ja pode ter criado a associacao do dono; garante sem duplicar.
        WorkspaceMember::firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $owner->id],
            ['role' => WorkspaceRole::Owner, 'joined_at' => now()],
        );

        return $workspace;
    }

    public function test_monta_projeto_perfil_estrategia_e_ideias(): void
    {
        $workspace = $this->workspace();

        $this->artisan('pilot:2m-saude-feminina', ['workspace' => $workspace->slug])->assertSuccessful();

        $project = Project::withoutGlobalScopes()->where('name', Piloto::PROJECT_NAME)->sole();
        $this->assertSame('America/Sao_Paulo', $project->timezone);

        $perfil = $project->brandProfile;
        $this->assertContains('cura garantida', $perfil->forbidden_words);
        $this->assertStringContainsString('nunca diagnosticar', $perfil->tone_of_voice);

        $estrategia = Strategy::withoutGlobalScopes()->where('project_id', $project->id)->sole();
        $this->assertSame('active', $estrategia->status);
        $this->assertSame(100, array_sum(array_column($estrategia->pillars, 'weight')));

        // So ideias: nada aprovado, agendado ou publicado por um comando.
        $pecas = Content::withoutGlobalScopes()->where('project_id', $project->id)->get();
        $this->assertCount(12, $pecas);
        $this->assertSame(['idea'], $pecas->pluck('status')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            array_column($estrategia->pillars, 'name'),
            $pecas->pluck('pillar')->unique()->values()->all(),
        );
        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
    }

    public function test_rodar_de_novo_nao_duplica_nem_sobrescreve_o_que_foi_editado(): void
    {
        $workspace = $this->workspace();
        $this->artisan('pilot:2m-saude-feminina', ['workspace' => (string) $workspace->id])->assertSuccessful();

        $project = Project::withoutGlobalScopes()->where('name', Piloto::PROJECT_NAME)->sole();
        $project->brandProfile->update(['audience' => 'Editado na tela']);

        $this->artisan('pilot:2m-saude-feminina', ['workspace' => (string) $workspace->id])->assertSuccessful();

        $this->assertSame(1, Project::withoutGlobalScopes()->where('name', Piloto::PROJECT_NAME)->count());
        $this->assertSame(1, Strategy::withoutGlobalScopes()->where('project_id', $project->id)->count());
        $this->assertSame(12, Content::withoutGlobalScopes()->where('project_id', $project->id)->count());
        $this->assertSame('Editado na tela', $project->brandProfile->fresh()->audience);
    }

    public function test_responsavel_de_fora_do_workspace_e_recusado(): void
    {
        $workspace = $this->workspace();
        $estranho = User::factory()->create();

        $this->artisan('pilot:2m-saude-feminina', ['workspace' => $workspace->slug, '--owner' => $estranho->email])
            ->assertFailed();

        $this->assertSame(0, Project::withoutGlobalScopes()->count());
    }
}
