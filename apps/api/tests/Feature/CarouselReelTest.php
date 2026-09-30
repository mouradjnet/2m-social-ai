<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Aprovar;
use Tests\TestCase;

/**
 * Etapa 3: carrossel (2 a 10 imagens) e Reel (video + capa), da montagem na peca
 * ate o media_publish, com a Meta simulada. Limites consultados na documentacao da
 * Meta em 29/09/2026.
 */
class CarouselReelTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://graph.instagram.com/v23.0/';

    private Workspace $workspace;

    private Project $project;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'instagram.driver' => 'graph',
            'instagram.app_id' => 'app',
            'instagram.app_secret' => 'segredo',
            'instagram.graph_version' => 'v23.0',
            'filesystems.disks.public.url' => 'https://social.exemplo.test/storage',
        ]);

        $this->workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->reviewer = $this->memberOf(WorkspaceRole::Reviewer);
        Sanctum::actingAs($this->reviewer);

        InstagramAccount::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'ig_user_id' => '1784', 'username' => '2msaudefeminina', 'account_type' => 'BUSINESS',
            'access_token' => 'token-da-conta', 'token_expires_at' => now()->addDays(50),
            'token_refreshed_at' => now(), 'status' => 'active',
            'connected_by' => $this->reviewer->id, 'connected_at' => now(),
        ]);
    }

    private function memberOf(WorkspaceRole $role, ?Workspace $workspace = null): User
    {
        $user = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'user_id' => $user->id, 'role' => $role, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function asset(string $nome, string $tipo = 'image', ?Project $project = null): Asset
    {
        return Asset::create([
            'workspace_id' => $this->workspace->id, 'project_id' => ($project ?? $this->project)->id, 'type' => $tipo,
            'disk' => 'public', 'path' => "media/{$nome}", 'mime' => $tipo === 'video' ? 'video/mp4' : 'image/jpeg',
            'size_bytes' => 10, 'width' => 1080, 'height' => $tipo === 'video' ? 1920 : 1080,
            'duration_ms' => $tipo === 'video' ? 20_000 : null,
            'checksum' => hash('sha256', $nome), 'created_by' => $this->reviewer->id,
        ]);
    }

    private function peca(string $format, array $attrs = []): Content
    {
        return Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'title' => 'Mitos do ciclo', 'caption' => 'Três mitos sobre o ciclo.', 'cta' => 'Salve este post.',
            'hashtags' => ['saudefeminina'], 'format' => $format, 'channel' => 'instagram',
            'status' => 'review', 'created_by' => $this->reviewer->id, ...$attrs,
        ]);
    }

    /** Aprova pela rota (reviewer) e agenda para um minuto atras. */
    private function aprovarEAgendar(Content $content): void
    {
        $this->postJson("/api/v1/contents/{$content->id}/approve", Aprovar::pedido($content))->assertOk();
        $content->refresh()->update(['status' => 'scheduled', 'scheduled_for' => now()->subMinute()]);
    }

    private function dispatch(): void
    {
        $this->artisan('publications:dispatch')->assertSuccessful();
    }

    private function meta(array $containers, array $status = ['FINISHED']): void
    {
        $seq = Http::sequence();
        foreach ($containers as $id) {
            $seq->push(['id' => $id]);
        }

        $estados = Http::sequence();
        foreach ($status as $s) {
            $estados->push(['status_code' => $s]);
        }

        Http::fake([
            self::G.'1784/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 100]]]]),
            self::G.'1784/media_publish' => Http::response(['id' => 'midia-1']),
            self::G.'1784/media' => $seq,
            self::G.'container-1?*' => $estados,
            self::G.'midia-1?*' => Http::response(['id' => 'midia-1', 'permalink' => 'https://www.instagram.com/p/XYZ/']),
        ]);
    }

    private function criacoes(): array
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::G.'1784/media' && $r->method() === 'POST')
            ->map(fn ($par) => $par[0]->data())
            ->values()
            ->all();
    }

    public function test_carrossel_cria_um_item_por_imagem_na_ordem_e_publica(): void
    {
        [$a, $b, $c] = [$this->asset('a.jpg'), $this->asset('b.jpg'), $this->asset('c.jpg')];
        $peca = $this->peca('carousel');

        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.slides.0.id', $c->id);

        $this->aprovarEAgendar($peca);
        $this->meta(['item-1', 'item-2', 'item-3', 'container-1']);
        $this->dispatch();

        $pub = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('published', $pub->status, (string) $pub->last_error);
        $this->assertSame('CAROUSEL', $pub->media_type);
        $this->assertSame([$c->url, $a->url, $b->url], $pub->media['images']);
        // O primeiro slide e o que abre o post.
        $this->assertSame($c->url, $pub->image_url);

        $criacoes = $this->criacoes();
        $this->assertCount(4, $criacoes);
        foreach ([$c, $a, $b] as $i => $slide) {
            $this->assertSame($slide->url, $criacoes[$i]['image_url']);
            $this->assertSame('true', $criacoes[$i]['is_carousel_item']);
            $this->assertArrayNotHasKey('caption', $criacoes[$i]);
        }
        $this->assertSame('CAROUSEL', $criacoes[3]['media_type']);
        $this->assertSame('item-1,item-2,item-3', $criacoes[3]['children']);
        $this->assertStringContainsString('Três mitos', $criacoes[3]['caption']);
        Http::assertSent(fn (Request $r) => $r->url() === self::G.'1784/media_publish' && $r['creation_id'] === 'container-1');
    }

    public function test_reel_manda_video_capa_e_espera_o_processamento(): void
    {
        $video = $this->asset('reel.mp4', 'video');
        $capa = $this->asset('capa.jpg');
        $peca = $this->peca('reel', ['image_asset_id' => $capa->id]);

        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => $video->id])->assertOk();
        $this->aprovarEAgendar($peca);

        // A Meta ainda processa o video na primeira consulta.
        $this->meta(['container-1'], ['IN_PROGRESS', 'FINISHED']);
        $this->dispatch();

        $pub = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $pub->status);
        $this->assertSame('REELS', $pub->media_type);
        $this->assertEquals(['video_url' => $video->url, 'cover_url' => $capa->url], $pub->media);
        $this->assertSame($video->id, $pub->asset_id);

        $criacao = $this->criacoes()[0];
        $this->assertSame('REELS', $criacao['media_type']);
        $this->assertSame($video->url, $criacao['video_url']);
        $this->assertSame($capa->url, $criacao['cover_url']);
        $this->assertSame('true', $criacao['share_to_feed']);

        $this->travel(2)->minutes();
        $this->dispatch();

        $this->assertSame('published', $pub->refresh()->status);
        // Um container so: a espera nao recria nada.
        $this->assertCount(1, $this->criacoes());
    }

    public function test_cada_formato_sem_a_midia_certa_nao_publica_e_diz_por_que(): void
    {
        $this->meta(['container-1']);

        $um = $this->peca('carousel');
        $this->putJson("/api/v1/contents/{$um->id}/slides", ['asset_ids' => [$this->asset('so.jpg')->id]])->assertOk();
        $this->aprovarEAgendar($um);

        $semVideo = $this->peca('reel', ['title' => 'Reel sem vídeo']);
        $this->aprovarEAgendar($semVideo);

        $story = $this->peca('story', ['title' => 'Story', 'image_asset_id' => $this->asset('s.jpg')->id]);
        $this->aprovarEAgendar($story);

        $this->dispatch();

        $motivo = fn (Content $c) => Publication::withoutGlobalScopes()->where('content_id', $c->id)->sole();
        $this->assertStringContainsString('de 2 a 10', $motivo($um)->last_error);
        $this->assertSame('O Reel não tem vídeo.', $motivo($semVideo)->last_error);
        // Antes, um story saia como post de imagem, em silencio.
        $this->assertStringContainsString("'story' não é publicado", $motivo($story)->last_error);
        $this->assertSame(0, Publication::withoutGlobalScopes()->where('status', '<>', 'failed')->count());
        $this->assertSame([], $this->criacoes());
    }

    public function test_slides_so_imagens_deste_projeto_sem_repetir_e_no_maximo_10(): void
    {
        $peca = $this->peca('carousel');
        $url = "/api/v1/contents/{$peca->id}/slides";
        $img = $this->asset('i.jpg');

        $this->putJson($url, ['asset_ids' => array_map(fn ($i) => $this->asset("x{$i}.jpg")->id, range(1, 11))])->assertStatus(422);
        $this->putJson($url, ['asset_ids' => [$img->id, $img->id]])->assertStatus(422);
        $this->putJson($url, ['asset_ids' => [$img->id, $this->asset('v.mp4', 'video')->id]])->assertStatus(422);
        $outro = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->putJson($url, ['asset_ids' => [$img->id, $this->asset('o.jpg', 'image', $outro)->id]])->assertStatus(422);
        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => $img->id])->assertStatus(422);

        $this->assertSame(0, $peca->slides()->count());
    }

    public function test_trocar_slides_grava_revisao_e_peca_aprovada_nao_troca(): void
    {
        [$a, $b] = [$this->asset('a.jpg'), $this->asset('b.jpg')];
        $peca = $this->peca('carousel');

        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$a->id, $b->id]])->assertOk();

        $rev = ContentRevision::where('content_id', $peca->id)->sole();
        $this->assertEquals(['from' => [], 'to' => [$a->id, $b->id]], $rev->changes['slides']);

        $this->postJson("/api/v1/contents/{$peca->id}/approve", Aprovar::pedido($peca))->assertOk();
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$b->id, $a->id]])->assertStatus(422);
        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => null])->assertStatus(422);
    }

    public function test_imagem_que_e_slide_de_peca_aprovada_nao_sai_da_biblioteca(): void
    {
        [$a, $b, $solta] = [$this->asset('a.jpg'), $this->asset('b.jpg'), $this->asset('c.jpg')];
        $aprovada = $this->peca('carousel');
        $this->putJson("/api/v1/contents/{$aprovada->id}/slides", ['asset_ids' => [$a->id, $b->id]])->assertOk();
        $this->postJson("/api/v1/contents/{$aprovada->id}/approve", Aprovar::pedido($aprovada))->assertOk();

        $rascunho = $this->peca('carousel', ['title' => 'Rascunho']);
        $this->putJson("/api/v1/contents/{$rascunho->id}/slides", ['asset_ids' => [$solta->id, $b->id]])->assertOk();

        $this->deleteJson("/api/v1/assets/{$a->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/assets/{$solta->id}")->assertNoContent();
        // O rascunho perde o slide apagado e fica com o resto, na ordem.
        $this->assertSame([$b->id], $rascunho->slides()->pluck('assets.id')->all());
    }

    public function test_viewer_nao_mexe_e_outro_tenant_nao_existe(): void
    {
        $peca = $this->peca('carousel');
        $img = $this->asset('a.jpg');

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Viewer));
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$img->id]])->assertForbidden();
        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => null])->assertForbidden();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Owner, Workspace::factory()->create()));
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$img->id]])->assertNotFound();
        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => null])->assertNotFound();
    }
}
