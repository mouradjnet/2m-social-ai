<?php

namespace Tests\Feature;

use App\Domain\Publishing\PublishGate;
use App\Enums\WorkspaceRole;
use App\Models\Content;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Aprovar;
use Tests\TestCase;

/**
 * ADR-13: a IA propoe, o humano aprova e o sistema publica. Estes testes seguram a
 * parte do humano — quem aprova, que a aprovacao tem nome e hora, e que ela cobre o
 * texto que vai ao ar e nenhum outro.
 */
class ApprovalTest extends TestCase
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

    private function content(Project $project, string $status): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Peca',
            'caption' => 'Legenda',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** @return array{Workspace, Project} */
    private function scene(): array
    {
        $workspace = Workspace::factory()->create();

        return [$workspace, Project::factory()->create(['workspace_id' => $workspace->id])];
    }

    /** Aprova pela rota, como um reviewer, e agenda direto no banco. */
    private function aprovadaEAgendada(Workspace $workspace, Project $project): Content
    {
        $content = $this->content($project, 'review');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Reviewer));

        $this->postJson("/api/v1/contents/{$content->id}/approve", Aprovar::pedido($content))->assertOk();

        $content->refresh()->update(['status' => 'scheduled', 'scheduled_for' => now()->addDay()]);

        return $content->refresh();
    }

    public function test_editor_nao_aprova(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'review');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        $this->postJson("/api/v1/contents/{$content->id}/approve", Aprovar::pedido($content))
            ->assertForbidden()
            ->assertJsonPath('message', 'Só quem revisa pode decidir sobre uma peça.');

        $this->assertSame('review', $content->fresh()->status);
        $this->assertNull($content->fresh()->approved_by);
    }

    public function test_editor_ainda_devolve_para_revisao_e_move_o_resto_do_fluxo(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'production');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Editor));

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'review'])->assertOk();
    }

    public function test_reviewer_aprova_e_a_aprovacao_tem_nome_e_hora(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'review');
        $reviewer = $this->memberOf($workspace, WorkspaceRole::Reviewer);
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/v1/contents/{$content->id}/approve", Aprovar::pedido($content))
            ->assertOk()
            ->assertJsonPath('data.approved_by', $reviewer->id)
            ->assertJsonPath('data.approver.name', $reviewer->name);

        $content->refresh();
        $this->assertSame($reviewer->id, $content->approved_by);
        $this->assertNotNull($content->approved_at);
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'user_id' => $reviewer->id,
            'from_status' => 'review',
            'to_status' => 'approved',
        ]);
    }

    public function test_owner_tambem_aprova(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'review');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Owner));

        $this->postJson("/api/v1/contents/{$content->id}/approve", Aprovar::pedido($content))->assertOk();
    }

    public function test_devolver_para_revisao_desfaz_a_aprovacao(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->content($project, 'review');
        Sanctum::actingAs($this->memberOf($workspace, WorkspaceRole::Reviewer));

        $this->postJson("/api/v1/contents/{$content->id}/approve", Aprovar::pedido($content))->assertOk();
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'review'])->assertOk();

        $this->assertNull($content->fresh()->approved_by);
        $this->assertNull($content->fresh()->approved_at);
    }

    public function test_desagendar_mantem_a_aprovacao(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->aprovadaEAgendada($workspace, $project);
        $aprovador = $content->approved_by;

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'approved'])->assertOk();

        $this->assertSame($aprovador, $content->fresh()->approved_by);
    }

    public function test_seo_nao_muda_peca_aprovada(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->aprovadaEAgendada($workspace, $project);

        // A recusa vem antes de procurar a sugestao: nem chega a importar se ha uma.
        $this->postJson("/api/v1/contents/{$content->id}/seo:apply", ['expected_version' => $content->fresh()->version])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Peça aprovada não muda o texto. Devolva para revisão antes de aplicar o SEO.');

        $this->assertSame('Peca', $content->fresh()->title);
    }

    public function test_porta_deixa_passar_a_peca_aprovada_e_agendada(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->aprovadaEAgendada($workspace, $project);

        $this->assertNull(PublishGate::refusal($content));
    }

    public function test_porta_recusa_peca_sem_aprovacao(): void
    {
        [, $project] = $this->scene();
        $content = $this->content($project, 'scheduled');
        $content->update(['scheduled_for' => now()->addDay()]);

        $this->assertSame('A peça não tem aprovação humana registrada.', PublishGate::refusal($content));
    }

    public function test_porta_recusa_o_que_nao_esta_agendado(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->aprovadaEAgendada($workspace, $project);
        $content->update(['status' => 'approved']);

        $this->assertStringContainsString('não agendada', PublishGate::refusal($content));
    }

    /**
     * O status continua `scheduled`, o aprovador continua gravado — e mesmo assim nao
     * publica: o texto que vai ao ar nao e o que o humano aprovou. CP-04: a mudanca e
     * feita por SQL cru, contornando o model (o pior caso); o hash do snapshot pega.
     */
    public function test_porta_recusa_se_o_texto_mudou_depois_da_aprovacao(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->aprovadaEAgendada($workspace, $project);

        DB::table('contents')->where('id', $content->id)->update(['caption' => 'Outra']);

        $this->assertSame('scheduled', $content->fresh()->status);
        $this->assertSame('A aprovação não vale para esta versão da peça (o conteúdo mudou, ou foi aprovada antes do controle de versões). Aprove de novo.', PublishGate::refusal($content->fresh()));
    }

    public function test_remarcar_nao_pede_nova_aprovacao(): void
    {
        [$workspace, $project] = $this->scene();
        $content = $this->aprovadaEAgendada($workspace, $project);

        $this->patchJson("/api/v1/contents/{$content->id}", ['scheduled_for' => now()->addDays(2)->toIso8601String()])
            ->assertOk();

        $this->assertNull(PublishGate::refusal($content->fresh()));
    }
}
