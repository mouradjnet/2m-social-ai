<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Etapa 5: a coleta das metricas reais (IG Media Insights, 29/09/2026), com a Meta
 * simulada. So entra numero que a Meta devolveu; recusa vira erro registrado, nunca zero.
 */
class InsightsCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://graph.instagram.com/v23.0/';

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'instagram.driver' => 'graph',
            'instagram.app_id' => 'app',
            'instagram.app_secret' => 'segredo',
            'instagram.graph_version' => 'v23.0',
        ]);

        $workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->user = User::factory()->create();
    }

    private function conta(bool $insights = true, array $attrs = []): InstagramAccount
    {
        return InstagramAccount::create([
            'workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id,
            'ig_user_id' => '1784', 'username' => '2msaudefeminina', 'account_type' => 'BUSINESS',
            'access_token' => 'token-da-conta', 'token_expires_at' => now()->addDays(50),
            'scopes' => array_merge(
                ['instagram_business_basic', 'instagram_business_content_publish'],
                $insights ? ['instagram_business_manage_insights'] : [],
            ),
            'status' => 'active', 'connected_by' => $this->user->id, 'connected_at' => now(), ...$attrs,
        ]);
    }

    private function publicada(InstagramAccount $conta, string $mediaId, string $tipo = 'IMAGE', array $attrs = []): Publication
    {
        $content = Content::create([
            'workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id,
            'title' => "Peça {$mediaId}", 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram',
            'status' => 'published', 'created_by' => $this->user->id,
        ]);

        return Publication::create([
            'workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id,
            'content_id' => $content->id, 'instagram_account_id' => $conta->id, 'media_type' => $tipo,
            'caption' => 'x', 'scheduled_for' => now()->subDays(2), 'status' => 'published',
            'media_id' => $mediaId, 'published_at' => now()->subDays(2), ...$attrs,
        ]);
    }

    private function insights(string $mediaId, array $valores): array
    {
        return [self::G."{$mediaId}/insights*" => Http::response(['data' => collect($valores)->map(
            fn ($v, $nome) => ['name' => $nome, 'period' => 'lifetime', 'values' => [['value' => $v]], 'id' => "{$mediaId}/insights/{$nome}/lifetime"],
        )->values()->all()])];
    }

    public function test_le_as_metricas_do_post_e_do_reel_com_o_conjunto_de_cada_tipo(): void
    {
        $conta = $this->conta();
        $post = $this->publicada($conta, 'midia-1');
        $reel = $this->publicada($conta, 'midia-2', 'REELS');
        Http::fake([
            ...$this->insights('midia-1', ['reach' => 812, 'likes' => 40, 'saved' => 9]),
            ...$this->insights('midia-2', ['reach' => 3100, 'views' => 5400, 'ig_reels_avg_watch_time' => 7300]),
        ]);

        $this->artisan('instagram:collect-insights')->expectsOutputToContain('medidas: 2')->assertSuccessful();

        $this->assertEquals(['reach' => 812, 'likes' => 40, 'saved' => 9], PublicationMetric::where('publication_id', $post->id)->sole()->metrics);
        $this->assertSame(7300, PublicationMetric::where('publication_id', $reel->id)->sole()->metrics['ig_reels_avg_watch_time']);

        $metricas = fn (string $id) => Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::G."{$id}/insights"))
            ->first()[0]->data()['metric'];
        $this->assertSame('reach,views,likes,comments,saved,shares,total_interactions', $metricas('midia-1'));
        $this->assertStringEndsWith(',ig_reels_avg_watch_time', $metricas('midia-2'));
        // Descontinuada para midia criada depois de 02/07/2024.
        $this->assertStringNotContainsString('impressions', $metricas('midia-1'));
    }

    public function test_sem_o_escopo_de_metricas_nao_pergunta_a_meta(): void
    {
        $this->publicada($this->conta(insights: false), 'midia-1');
        Http::fake();

        $this->artisan('instagram:collect-insights')->expectsOutputToContain('sem permissão de insights: 1')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PublicationMetric::count());
    }

    public function test_recusa_da_meta_fica_registrada_e_nao_vira_zero(): void
    {
        $pub = $this->publicada($this->conta(), 'midia-1', 'CAROUSEL');
        Http::fake([self::G.'midia-1/insights*' => Http::response(['error' => ['message' => 'metric not supported', 'code' => 100]], 400)]);

        $this->artisan('instagram:collect-insights')->expectsOutputToContain('recusadas pela Meta: 1')->assertSuccessful();

        $linha = PublicationMetric::where('publication_id', $pub->id)->sole();
        $this->assertSame([], $linha->metrics);
        $this->assertNotNull($linha->error);
        $this->assertStringNotContainsString('token-da-conta', $linha->error);
    }

    public function test_so_mede_post_publicado_nos_ultimos_30_dias_com_conta_valida(): void
    {
        $conta = $this->conta();
        $this->publicada($conta, 'velha', attrs: ['published_at' => now()->subDays(31)]);
        $this->publicada($conta, 'falhou', attrs: ['status' => 'failed']);
        $this->publicada($this->conta(attrs: ['status' => 'disconnected', 'access_token' => null, 'project_id' => Project::factory()->create()->id]), 'orfa');
        Http::fake();

        $this->artisan('instagram:collect-insights')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_driver_fake_mede_sem_sair_da_maquina(): void
    {
        config(['instagram.driver' => 'fake']);
        $this->publicada($this->conta(), 'fake-media-1');
        Http::fake();

        $this->artisan('instagram:collect-insights')->expectsOutputToContain('medidas: 1')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertArrayHasKey('reach', PublicationMetric::sole()->metrics);
    }
}
