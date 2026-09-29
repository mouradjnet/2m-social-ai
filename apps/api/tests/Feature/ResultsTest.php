<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Etapa 5: os resultados reais, com as contas feitas em PHP e os posts sem numero separados. */
class ResultsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $viewer;

    private InstagramAccount $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->viewer = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $this->viewer->id, 'role' => WorkspaceRole::Viewer, 'joined_at' => now()]);
        Sanctum::actingAs($this->viewer);

        $this->conta = InstagramAccount::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'ig_user_id' => '1784', 'username' => '2msaudefeminina', 'account_type' => 'BUSINESS',
            'access_token' => 't', 'token_expires_at' => now()->addDays(50),
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights'],
            'status' => 'active', 'connected_by' => $this->viewer->id, 'connected_at' => now(),
        ]);
    }

    private function publicado(string $titulo, string $pilar, string $formato, ?array $metricas, ?string $erro = null, int $diasAtras = 3): Publication
    {
        $c = Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'title' => $titulo,
            'pillar' => $pilar, 'hashtags' => [], 'format' => $formato, 'channel' => 'instagram',
            'status' => 'published', 'created_by' => $this->viewer->id,
        ]);
        $p = Publication::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'content_id' => $c->id,
            'instagram_account_id' => $this->conta->id, 'caption' => 'x', 'scheduled_for' => now()->subDays($diasAtras),
            'status' => 'published', 'media_id' => "m{$c->id}", 'published_at' => now()->subDays($diasAtras),
        ]);

        if ($metricas !== null || $erro !== null) {
            PublicationMetric::create(['publication_id' => $p->id, 'metrics' => $metricas ?? [], 'error' => $erro, 'collected_at' => now()]);
        }

        return $p;
    }

    public function test_soma_compara_por_pilar_e_formato_e_separa_quem_nao_tem_numero(): void
    {
        $a = $this->publicado('Papanicolau', 'Prevenção', 'carousel', ['reach' => 1000, 'total_interactions' => 80, 'saved' => 30]);
        // Coleta antiga e nova do mesmo post: vale a mais recente.
        PublicationMetric::create(['publication_id' => $a->id, 'metrics' => ['reach' => 1200, 'total_interactions' => 96, 'saved' => 40], 'collected_at' => now()]);
        $this->publicado('Ciclo', 'Educação', 'post', ['reach' => 800, 'total_interactions' => 24]);
        $this->publicado('Recém-publicado', 'Educação', 'post', null);
        $this->publicado('Álbum', 'Educação', 'carousel', null, 'metric not supported');
        $this->publicado('Antigo', 'Educação', 'post', ['reach' => 99999, 'total_interactions' => 9999], diasAtras: 40);

        $r = $this->getJson("/api/v1/projects/{$this->project->id}/results")->assertOk();

        $r->assertJsonPath('data.published', 4)
            ->assertJsonPath('data.measured', 2)
            ->assertJsonPath('data.totals.reach', 2000)
            ->assertJsonPath('data.totals.total_interactions', 120)
            // 120 interacoes / 2000 de alcance.
            ->assertJsonPath('data.engagement_rate', 6)
            ->assertJsonPath('data.top.0.title', 'Papanicolau')
            ->assertJsonPath('data.by_pillar.0.name', 'Prevenção')
            ->assertJsonPath('data.by_pillar.0.avg_reach', 1200)
            ->assertJsonPath('data.by_format.0.name', 'carousel')
            ->assertJsonPath('account.insights_enabled', true);

        $estados = collect($r->json('data.posts'))->pluck('state', 'title')->all();
        $this->assertSame(['Papanicolau' => 'measured', 'Ciclo' => 'measured', 'Recém-publicado' => 'pending', 'Álbum' => 'unavailable'], $estados);

        // Periodo maior traz o post antigo.
        $this->getJson("/api/v1/projects/{$this->project->id}/results?days=90")->assertJsonPath('data.published', 5);
        $this->getJson("/api/v1/projects/{$this->project->id}/results?days=13")->assertStatus(422);
    }

    public function test_sem_nada_medido_nao_ha_taxa_e_conta_sem_escopo_e_avisada(): void
    {
        $this->conta->update(['scopes' => ['instagram_business_basic', 'instagram_business_content_publish']]);
        $this->publicado('Ciclo', 'Educação', 'post', null);

        $this->getJson("/api/v1/projects/{$this->project->id}/results")
            ->assertJsonPath('data.measured', 0)
            ->assertJsonPath('data.engagement_rate', null)
            ->assertJsonPath('account.insights_enabled', false);
    }

    public function test_outro_tenant_nao_ve_os_resultados(): void
    {
        $intruso = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => Workspace::factory()->create()->id, 'user_id' => $intruso->id, 'role' => WorkspaceRole::Owner, 'joined_at' => now()]);
        Sanctum::actingAs($intruso);

        $this->getJson("/api/v1/projects/{$this->project->id}/results")->assertNotFound();
    }
}
