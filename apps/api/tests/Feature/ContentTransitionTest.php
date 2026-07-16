<?php

namespace Tests\Feature;

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

    public function test_index_lista_as_pecas_do_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        $this->content($project);

        Sanctum::actingAs($editor);

        $this->getJson("/api/v1/projects/{$project->id}/contents")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/projects/{$target->id}/contents")->assertNotFound();
    }

    public function test_avancar_um_passo_grava_revisao_na_transacao(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertOk()
            ->assertJsonPath('data.status', 'production');

        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'from_status' => 'idea',
            'to_status' => 'production',
            'user_id' => $editor->id,
        ]);
    }

    public function test_voltar_um_passo_e_valido(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'review');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertOk()
            ->assertJsonPath('data.status', 'production');
    }

    public function test_pular_etapa_devolve_422_e_nao_muda_nada(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'published'])
            ->assertStatus(422);

        $this->assertSame('idea', $content->fresh()->status);
        $this->assertSame(0, ContentRevision::query()->count());
    }

    public function test_pular_de_idea_para_review_dentro_do_fluxo_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        // idea e review estao ambos no FLOW, distancia 2 — a regra +-1 barra.
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'review'])
            ->assertStatus(422);
    }

    public function test_avancar_alem_de_approved_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'approved');

        Sanctum::actingAs($editor);

        // Agendar e do social_media, nao do humano: exige uma data, e este PATCH so
        // carrega status. Quem clica em "Avancar" numa peca aprovada nao tem como
        // dizer *quando*.
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'scheduled'])
            ->assertStatus(422);
    }

    public function test_desagendar_volta_para_approved_e_limpa_a_data(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'scheduled');
        $content->update(['scheduled_for' => now()->addDays(3)]);

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.scheduled_for', null);

        // Sem isto a peca voltaria para Aprovado carregando uma data fantasma.
        $this->assertNull($content->fresh()->scheduled_for);
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'from_status' => 'scheduled',
            'to_status' => 'approved',
        ]);
    }

    public function test_arquivar_uma_peca_agendada_e_valido(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'scheduled');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_remarcar_muda_a_data_e_grava_a_revisao_em_changes(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'scheduled');
        $content->update(['scheduled_for' => '2026-08-03 10:00:00']);

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['scheduled_for' => '2026-08-07T18:30:00'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('2026-08-07 18:30:00', $content->fresh()->scheduled_for->toDateTimeString());

        // Remarcar nao e transicao: o status nao mudou, e quem conta o que houve e
        // `changes` — a coluna que existia desde a primeira migration e nunca fora usada.
        $revisao = ContentRevision::where('content_id', $content->id)->latest('id')->first();
        $this->assertNull($revisao->from_status);
        $this->assertNull($revisao->to_status);
        $this->assertSame('2026-08-07 18:30:00', $revisao->changes['scheduled_for']['to']);
        $this->assertStringStartsWith('2026-08-03', $revisao->changes['scheduled_for']['from']);
    }

    public function test_remarcar_peca_que_nao_esta_agendada_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['scheduled_for' => '2026-08-07T18:30:00'])
            ->assertStatus(422);

        $this->assertNull($content->fresh()->scheduled_for);
        $this->assertSame(0, ContentRevision::query()->count());
    }

    public function test_status_e_scheduled_for_juntos_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'scheduled');

        Sanctum::actingAs($editor);

        // Mover no fluxo e remarcar sao gestos diferentes; juntos, a revisao nao
        // saberia contar o que aconteceu.
        $this->patchJson("/api/v1/contents/{$content->id}", [
            'status' => 'approved',
            'scheduled_for' => '2026-08-07T18:30:00',
        ])->assertStatus(422);
    }

    public function test_patch_vazio_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'scheduled');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", [])->assertStatus(422);
    }

    public function test_de_scheduled_so_da_para_desagendar_ou_arquivar(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'scheduled');

        Sanctum::actingAs($editor);

        // `published` exige exportar/publicar, que nao existe.
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'published'])
            ->assertStatus(422);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'review'])
            ->assertStatus(422);
    }

    public function test_arquivar_de_qualquer_estado_ativo_e_valido(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'review');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_arquivar_o_que_ja_esta_arquivado_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'archived');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'archived'])
            ->assertStatus(422);
    }

    public function test_viewer_nao_pode_mover(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->memberOf($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($viewer);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertForbidden();
    }

    public function test_peca_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);
        $content = $this->content($target, 'idea');

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertNotFound();
    }

    /**
     * O BECO SEM SAIDA. `archived` era terminal, mas o rewriter conserta peca
     * arquivada — e o resultado ficava preso no arquivo, sem poder ser revisado nem
     * publicado (na sessao em que isso apareceu, a peca teve de ser movida por SQL).
     *
     * Volta de ONDE SAIU, e o historico ja sabia: toda transicao grava a revisao.
     */
    public function test_desarquivar_devolve_a_peca_ao_status_de_onde_ela_saiu(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'review');

        Sanctum::actingAs($editor);

        // Arquiva pelo caminho normal: e ele que grava de onde a peca veio.
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'archived'])->assertOk();
        $this->assertSame('archived', $content->refresh()->status);

        $this->postJson("/api/v1/contents/{$content->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.status', 'review');

        $this->assertSame('review', $content->refresh()->status);

        // O caminho de volta tambem e historia: sem esta linha, o de-para mentiria.
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'from_status' => 'archived',
            'to_status' => 'review',
        ]);
    }

    /** Peca arquivada direto no banco nao tem revisao: `idea` e o comeco do fluxo. */
    public function test_desarquivar_sem_revisao_registrada_cai_em_idea(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'archived');

        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/contents/{$content->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.status', 'idea');
    }

    /** Desarquivar o que nao esta arquivado nao e gesto nenhum. */
    public function test_desarquivar_peca_ativa_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/contents/{$content->id}/unarchive")->assertStatus(422);
    }

    public function test_viewer_nao_pode_desarquivar(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->memberOf($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'archived');

        Sanctum::actingAs($viewer);

        $this->postJson("/api/v1/contents/{$content->id}/unarchive")->assertForbidden();
    }

    public function test_desarquivar_peca_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);
        $content = $this->content($target, 'archived');

        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/contents/{$content->id}/unarchive")->assertNotFound();
    }

    public function test_desarquivar_sem_token_devolve_401(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'archived');

        $this->postJson("/api/v1/contents/{$content->id}/unarchive")->assertUnauthorized();
    }
}
