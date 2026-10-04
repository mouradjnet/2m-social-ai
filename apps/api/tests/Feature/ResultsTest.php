<?php

namespace Tests\Feature;

use App\Ai\Agents\ResultsAgent;
use App\Ai\Exceptions\OutputRejectedException;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\AnalyticsReport;
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

        // Os posts de um teste nascem no mesmo instante: sem isto, a virada do
        // segundo entre dois `now()` muda a ordem por published_at.
        $this->freezeTime();

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

    private function editor(): void
    {
        $u = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $u->id, 'role' => WorkspaceRole::Editor, 'joined_at' => now()]);
        Sanctum::actingAs($u);
    }

    public function test_ia_le_os_resultados_e_o_relatorio_nao_se_mistura_com_o_editorial(): void
    {
        $this->publicado('Papanicolau', 'Prevenção', 'carousel', ['reach' => 1200, 'total_interactions' => 96]);
        $this->publicado('Ciclo', 'Educação', 'post', ['reach' => 800, 'total_interactions' => 24]);
        $this->publicado('Mitos', 'Educação', 'reel', ['reach' => 3000, 'total_interactions' => 150]);
        $this->editor();

        $id = $this->postJson("/api/v1/projects/{$this->project->id}/results:generate")->assertStatus(202)->json('ai_run_id');

        $this->assertSame('succeeded', AiRun::find($id)->status, (string) AiRun::find($id)->error);
        $relatorio = AnalyticsReport::sole();
        $this->assertSame('results', $relatorio->kind);
        $this->assertNull($relatorio->score);
        // O snapshot: os numeros em que a leitura se baseou.
        $this->assertSame(3, $relatorio->metrics['measured']);

        $this->getJson("/api/v1/projects/{$this->project->id}/results")
            ->assertJsonPath('report.id', $relatorio->id)
            ->assertJsonCount(2, 'report.insights');
        // A tela Insights (editorial) nao mostra o relatorio de resultados.
        $this->getJson("/api/v1/projects/{$this->project->id}/analytics")->assertJsonPath('data', null);
    }

    public function test_com_menos_de_3_posts_medidos_a_leitura_seria_palpite(): void
    {
        $this->publicado('Ciclo', 'Educação', 'post', ['reach' => 800, 'total_interactions' => 24]);
        $this->publicado('Recém', 'Educação', 'post', null);
        $this->editor();

        $this->postJson("/api/v1/projects/{$this->project->id}/results:generate")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'palpite'));
        $this->assertSame(0, AiRun::count());
    }

    public function test_sugestao_sem_numero_ou_sem_acao_e_recusada(): void
    {
        $agent = new ResultsAgent;
        $ok = ['title' => 't', 'detail' => 'Carrossel teve 96 interações', 'action' => 'Planejar mais carrosséis'];

        $agent->validate(['summary' => 's', 'insights' => [$ok, $ok]]);

        foreach ([
            [$ok],
            [$ok, [...$ok, 'detail' => 'Carrossel vai melhor']],
            [$ok, [...$ok, 'action' => ' ']],
        ] as $insights) {
            try {
                $agent->validate(['summary' => 's', 'insights' => $insights]);
                $this->fail('Devia recusar: '.json_encode($insights));
            } catch (OutputRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
