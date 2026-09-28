<?php

namespace Tests\Feature;

use App\Domain\Publishing\Dispatcher;
use App\Domain\Publishing\Publisher;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A publicacao automatica de ponta a ponta, com a Meta simulada por Http::fake. A
 * fila e `sync` nos testes: o PublishJob roda dentro do `publications:dispatch`.
 */
class PublishingTest extends TestCase
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

        Http::preventStrayRequests();

        $this->workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->reviewer = $this->memberOf(WorkspaceRole::Reviewer);
    }

    private function memberOf(WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function conta(array $attrs = []): InstagramAccount
    {
        return InstagramAccount::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'ig_user_id' => '1784',
            'username' => '2msaudefeminina',
            'account_type' => 'BUSINESS',
            'access_token' => 'token-da-conta',
            'token_expires_at' => now()->addDays(50),
            'token_refreshed_at' => now(),
            'status' => 'active',
            'connected_by' => $this->reviewer->id,
            'connected_at' => now(),
            ...$attrs,
        ]);
    }

    /**
     * Uma peca que um reviewer aprovou pela rota, com imagem, agendada para `$quando`
     * (padrao: um minuto atras — a hora ja chegou).
     */
    private function pecaAprovada(?string $quando = null, bool $comImagem = true): Content
    {
        $asset = $comImagem ? Asset::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'type' => 'image',
            'disk' => 'public', 'path' => 'media/capa.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10,
            'width' => 1080, 'height' => 1080, 'checksum' => str_repeat('b', 64), 'created_by' => $this->reviewer->id,
        ]) : null;

        $content = Content::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Ciclo menstrual',
            'caption' => 'Seu ciclo diz muito sobre sua saúde.',
            'cta' => 'Agende sua consulta.',
            'hashtags' => ['saudefeminina', '#ginecologia'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => 'review',
            'image_asset_id' => $asset?->id,
            'created_by' => $this->reviewer->id,
        ]);

        Sanctum::actingAs($this->reviewer);
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'approved'])->assertOk();

        $content->refresh()->update([
            'status' => 'scheduled',
            'scheduled_for' => $quando ?? now()->subMinute(),
        ]);

        return $content->refresh();
    }

    /**
     * A Meta de mentira. `$trocas` substitui respostas por endpoint; o que nao for
     * trocado responde o caminho feliz.
     */
    private function meta(array $trocas = []): void
    {
        $padrao = [
            'quota' => Http::response(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100]]]]),
            'container' => Http::response(['id' => 'container-1']),
            'status' => Http::response(['status_code' => 'FINISHED', 'id' => 'container-1']),
            'publish' => Http::response(['id' => 'midia-1']),
            'midia' => Http::response(['id' => 'midia-1', 'permalink' => 'https://www.instagram.com/p/ABC/', 'timestamp' => '2026-09-28T12:00:00+0000']),
            'recentes' => Http::response(['data' => []]),
        ];

        $r = [...$padrao, ...$trocas];

        // A ordem importa: `1784/media_publish` antes de `1784/media`.
        Http::fake([
            self::G.'1784/content_publishing_limit*' => $r['quota'],
            self::G.'1784/media_publish' => $r['publish'],
            self::G.'1784/media' => $r['container'],
            self::G.'1784/media?*' => $r['recentes'],
            self::G.'container-1?*' => $r['status'],
            self::G.'midia-1?*' => $r['midia'],
        ]);
    }

    private function dispatch(): void
    {
        $this->artisan('publications:dispatch')->assertSuccessful();
    }

    private function publicacoes(): int
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::G.'1784/media_publish')->count();
    }

    // ------------------------------------------------------------ caminho feliz

    public function test_publica_na_hora_e_registra_tudo(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta();

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('published', $p->status);
        $this->assertSame('midia-1', $p->media_id);
        $this->assertSame('https://www.instagram.com/p/ABC/', $p->permalink);
        $this->assertSame('container-1', $p->container_id);

        // O snapshot do que o humano aprovou.
        $this->assertSame($this->reviewer->id, $p->approved_by);
        $this->assertNotNull($p->approved_at);
        $this->assertSame('2msaudefeminina', $p->account_username);
        $this->assertTrue($p->scheduled_for->equalTo($content->scheduled_for));
        $this->assertSame(
            "Seu ciclo diz muito sobre sua saúde.\n\nAgende sua consulta.\n\n#saudefeminina #ginecologia",
            $p->caption,
        );
        $this->assertSame('https://social.exemplo.test/storage/media/capa.jpg', $p->image_url);

        // A Meta recebeu a URL publica e a legenda aprovada.
        Http::assertSent(fn (Request $r) => $r->url() === self::G.'1784/media'
            && $r['image_url'] === 'https://social.exemplo.test/storage/media/capa.jpg'
            && $r['caption'] === $p->caption);

        // A peca andou, em nome de quem aprovou.
        $content->refresh();
        $this->assertSame('published', $content->status);
        $this->assertNotNull($content->published_at);
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id, 'from_status' => 'scheduled', 'to_status' => 'published',
            'user_id' => $this->reviewer->id,
        ]);

        $this->assertSame(['container', 'publish'], $p->attemptsLog()->pluck('step')->all());
    }

    public function test_nao_publica_antes_da_hora(): void
    {
        $this->conta();
        $this->pecaAprovada(now()->addHour()->toDateTimeString());
        $this->meta();

        $this->dispatch();

        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_ignora_pecas_de_outros_canais(): void
    {
        $this->conta();
        $this->pecaAprovada()->update(['channel' => 'linkedin']);
        $this->meta();

        $this->dispatch();

        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------ portas fechadas

    public function test_peca_sem_aprovacao_nao_publica_e_o_historico_diz_por_que(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $content->update(['approved_by' => null, 'approved_at' => null]);
        ContentRevision::where('content_id', $content->id)->delete();
        $this->meta();

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $p->status);
        $this->assertSame('refused', $p->error_kind);
        $this->assertSame('A peça não tem aprovação humana registrada.', $p->last_error);
        Http::assertNothingSent();
    }

    public function test_texto_mudado_depois_da_aprovacao_nao_publica(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        ContentRevision::create([
            'content_id' => $content->id, 'user_id' => $this->reviewer->id,
            'changes' => ['caption' => ['from' => 'a', 'to' => 'b']],
        ]);
        $this->meta();

        $this->dispatch();

        $this->assertSame('O texto mudou depois da aprovação. Aprove de novo.', Publication::withoutGlobalScopes()->sole()->last_error);
        Http::assertNothingSent();
    }

    public function test_sem_imagem_sem_conta_ou_com_conta_vencida_nao_publica(): void
    {
        $this->pecaAprovada(comImagem: false);
        $this->dispatch();
        $this->assertStringContainsString('não tem imagem', Publication::withoutGlobalScopes()->sole()->last_error);

        Publication::withoutGlobalScopes()->delete();
        Content::withoutGlobalScopes()->delete();
        $this->pecaAprovada();
        $this->dispatch();
        $this->assertSame('O projeto não tem conta do Instagram conectada.', Publication::withoutGlobalScopes()->sole()->last_error);

        Publication::withoutGlobalScopes()->delete();
        $this->conta(['status' => 'expired']);
        $this->dispatch();
        $this->assertSame('A conexão com @2msaudefeminina venceu. Reconecte a conta.', Publication::withoutGlobalScopes()->sole()->last_error);

        Http::assertNothingSent();
    }

    public function test_horario_perdido_ha_muito_tempo_nao_publica_atrasado(): void
    {
        $this->conta();
        $this->pecaAprovada(now()->subHours(13)->toDateTimeString());
        $this->meta();

        $this->dispatch();

        $this->assertStringContainsString('Remarque a peça', Publication::withoutGlobalScopes()->sole()->last_error);
        Http::assertNothingSent();
    }

    public function test_legenda_acima_do_limite_do_instagram_nao_publica(): void
    {
        $this->conta();
        $this->pecaAprovada()->update(['caption' => str_repeat('a', 2300)]);

        $this->dispatch();

        $this->assertStringContainsString('o Instagram aceita até 2200', Publication::withoutGlobalScopes()->sole()->last_error);
    }

    // ------------------------------------------------------------ idempotencia

    public function test_rodar_o_agendador_varias_vezes_publica_uma_vez(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta();

        $this->dispatch();
        $this->dispatch();
        $this->travel(5)->minutes();
        $this->dispatch();

        $this->assertSame(1, Publication::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->publicacoes());
    }

    public function test_o_banco_recusa_duas_publicacoes_vivas_da_mesma_peca(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta(['publish' => Http::response(['error' => ['code' => 2]], 502)]);
        $this->dispatch();

        // Mesmo que alguem tente criar outra publicacao para outro horario, a primeira
        // ainda esta viva (`unknown`): o indice parcial barra.
        $this->expectException(UniqueConstraintViolationException::class);
        Publication::create([
            ...Publication::withoutGlobalScopes()->sole()->only(['workspace_id', 'project_id', 'content_id', 'caption']),
            'scheduled_for' => now()->addDay(), 'status' => 'pending',
        ]);
    }

    public function test_job_duplicado_nao_publica_duas_vezes(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta();
        $this->dispatch();

        // Um segundo worker pega o mesmo id depois: a reivindicacao atomica recusa.
        app(Publisher::class)->run(Publication::withoutGlobalScopes()->sole()->id);

        $this->assertSame(1, $this->publicacoes());
    }

    // ------------------------------------------------------------ falhas

    public function test_falha_transitoria_espera_e_tenta_de_novo(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta(['container' => Http::sequence()
            ->push(['error' => ['code' => 1, 'message' => 'Erro temporário']], 500)
            ->push(['id' => 'container-1'])]);

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $p->status);
        $this->assertSame('transient', $p->error_kind);
        $this->assertTrue($p->next_attempt_at->isFuture());

        // Antes da hora, nada.
        $this->dispatch();
        $this->assertSame('pending', $p->fresh()->status);

        $this->travel(2)->minutes();
        $this->dispatch();

        $this->assertSame('published', $p->fresh()->status);
        $this->assertSame('published', $content->fresh()->status);
        $this->assertSame(1, $this->publicacoes());
    }

    public function test_falhas_transitorias_esgotam_e_a_publicacao_falha(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta(['container' => Http::response(['error' => ['code' => 4, 'message' => 'Limite de requisições']], 400)]);

        $this->dispatch();
        foreach (config('publishing.backoff_minutes') as $minutos) {
            $this->travel($minutos + 1)->minutes();
            $this->dispatch();
        }

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $p->status);
        $this->assertSame(6, $p->attemptsLog()->where('outcome', 'transient')->count());
        $this->assertSame(0, $this->publicacoes());
    }

    public function test_imagem_recusada_falha_sem_insistir(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta(['container' => Http::response(['error' => [
            'code' => 36003, 'error_subcode' => 2207009, 'message' => 'Aspect ratio', 'error_user_msg' => 'Proporção não suportada.',
        ]], 400)]);

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $p->status);
        $this->assertSame('permanent', $p->error_kind);
        $this->assertSame('Proporção não suportada.', $p->last_error);
        $this->assertSame(400, $p->attemptsLog()->first()->http_status);
        $this->assertSame(2207009, $p->attemptsLog()->first()->meta_subcode);
    }

    public function test_token_recusado_falha_e_marca_a_conta_como_vencida(): void
    {
        $conta = $this->conta();
        $this->pecaAprovada();
        $this->meta(['quota' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid token']], 400)]);

        $this->dispatch();

        $this->assertSame('failed', Publication::withoutGlobalScopes()->sole()->status);
        $this->assertSame('expired', $conta->fresh()->status);
    }

    public function test_cota_de_24h_cheia_espera(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta(['quota' => Http::response(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]])]);

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $p->status);
        $this->assertStringContainsString('Limite de 100 publicações', $p->last_error);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::G.'1784/media');
    }

    public function test_container_em_processamento_espera_e_depois_publica(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta(['status' => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS'])
            ->push(['status_code' => 'FINISHED'])]);

        $this->dispatch();
        $this->assertSame('pending', Publication::withoutGlobalScopes()->sole()->status);

        $this->travel(2)->minutes();
        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('published', $p->status);
        // Reaproveitou o container: nao criou um segundo.
        $this->assertSame(1, Http::recorded(fn (Request $r) => $r->url() === self::G.'1784/media')->count());
    }

    // ------------------------------------------------------------ resultado desconhecido

    /**
     * O coracao da regra: o media_publish deu timeout. A Meta pode ter publicado. A
     * rodada seguinte PERGUNTA — o container esta PUBLISHED — e conclui sem publicar
     * de novo.
     */
    public function test_resultado_desconhecido_e_conferido_e_nao_repetido(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta([
            'publish' => Http::response(['error' => ['code' => 2, 'message' => 'Service unavailable']], 502),
            'status' => Http::sequence()->push(['status_code' => 'FINISHED'])->push(['status_code' => 'PUBLISHED']),
            'recentes' => Http::response(['data' => [
                ['id' => 'outra', 'caption' => 'Post antigo', 'timestamp' => '2026-09-27T12:00:00+0000'],
                ['id' => 'midia-1', 'caption' => "Seu ciclo diz muito sobre sua saúde.\n\nAgende sua consulta.\n\n#saudefeminina #ginecologia", 'timestamp' => '2026-09-28T12:00:00+0000'],
            ]]),
        ]);

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('unknown', $p->status);
        $this->assertSame('scheduled', $content->fresh()->status);

        // Enquanto desconhecida, a peca nao se mexe.
        Sanctum::actingAs($this->reviewer);
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'approved'])->assertStatus(409);

        $this->travel(3)->minutes();
        $this->dispatch();

        $p->refresh();
        $this->assertSame('published', $p->status);
        $this->assertSame('midia-1', $p->media_id);
        $this->assertSame('published', $content->fresh()->status);
        $this->assertSame(1, $this->publicacoes());
    }

    /** Container ainda FINISHED depois do timeout: a Meta NAO publicou. Publicar e seguro. */
    public function test_resultado_desconhecido_com_container_nao_publicado_publica_com_seguranca(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta([
            'publish' => Http::sequence()
                ->push(['error' => ['code' => 2]], 504)
                ->push(['id' => 'midia-1']),
        ]);

        $this->dispatch();
        $this->travel(3)->minutes();
        $this->dispatch();

        $this->assertSame('published', Publication::withoutGlobalScopes()->sole()->status);
        $this->assertSame(2, $this->publicacoes());
        // A segunda chamada so saiu depois de perguntar o estado do container.
        $this->assertSame(
            ['container', 'publish', 'reconcile', 'publish'],
            Publication::withoutGlobalScopes()->sole()->attemptsLog()->pluck('step')->all(),
        );
    }

    /**
     * A conferencia tambem falha, varias vezes. A publicacao NAO vira `failed` (que
     * ofereceria "tentar de novo" sobre um post que pode estar no ar): fica `unknown`
     * sem nova tentativa, e um humano decide.
     */
    public function test_conferencia_que_nao_conclui_espera_um_humano_e_nunca_republica(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta([
            'publish' => Http::response(['error' => ['code' => 2]], 502),
            'status' => Http::sequence()
                ->push(['status_code' => 'FINISHED'])
                ->whenEmpty(Http::response(['error' => ['code' => 1, 'message' => 'Indisponível']], 500)),
        ]);

        $this->dispatch();
        for ($i = 0; $i < 8; $i++) {
            $this->travel(61)->minutes();
            $this->dispatch();
        }

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('unknown', $p->status);
        $this->assertNull($p->next_attempt_at);
        $this->assertStringContainsString('Confira o perfil e decida', $p->last_error);
        $this->assertSame(1, $this->publicacoes());

        // E "tentar de novo" nao se aplica: so a decisao humana.
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/publications/{$p->id}/retry")->assertStatus(422);
    }

    public function test_worker_que_morreu_no_meio_vira_desconhecido(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $p = app(Dispatcher::class)->prepare($content->load('image'));
        // Simula o worker morto: reivindicou, criou o container e nunca devolveu.
        Publication::withoutGlobalScopes()->whereKey($p->id)->update([
            'status' => 'publishing', 'container_id' => 'container-1', 'updated_at' => now()->subMinutes(11),
        ]);

        app(Dispatcher::class)->recoverStuck();

        $this->assertSame('unknown', $p->fresh()->status);
    }

    // ------------------------------------------------------------ gestos humanos

    public function test_tentar_de_novo_abre_uma_publicacao_nova_e_guarda_a_que_falhou(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta(['container' => Http::sequence()
            ->push(['error' => ['code' => 36003, 'message' => 'Imagem ruim']], 400)
            ->push(['id' => 'container-1'])]);
        $this->dispatch();
        $falhou = Publication::withoutGlobalScopes()->sole();

        // Desagendar e permitido depois da falha (nada em andamento).
        Sanctum::actingAs($this->memberOf(WorkspaceRole::Editor));
        $this->postJson("/api/v1/publications/{$falhou->id}/retry")
            ->assertCreated()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame('failed', $falhou->fresh()->status);
        $this->assertSame(2, Publication::withoutGlobalScopes()->count());
        $this->assertSame('published', $content->fresh()->status);
    }

    public function test_viewer_nao_tenta_de_novo_e_publicacao_viva_nao_e_repetida(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta(['publish' => Http::response(['error' => ['code' => 2]], 502)]);
        $this->dispatch();
        $p = Publication::withoutGlobalScopes()->sole();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Viewer));
        $this->postJson("/api/v1/publications/{$p->id}/retry")->assertForbidden();

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/publications/{$p->id}/retry")->assertStatus(422);
    }

    public function test_resultado_desconhecido_e_decidido_por_quem_revisa(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta(['publish' => Http::response(['error' => ['code' => 2]], 502)]);
        $this->dispatch();
        $p = Publication::withoutGlobalScopes()->sole();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Editor));
        $this->postJson("/api/v1/publications/{$p->id}/resolve", ['outcome' => 'published'])->assertForbidden();

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/publications/{$p->id}/resolve", [
            'outcome' => 'published', 'permalink' => 'https://www.instagram.com/p/XYZ/',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertSame('published', $content->fresh()->status);
        $this->assertSame('resolve', $p->attemptsLog()->get()->last()->step);
    }

    public function test_historico_lista_as_publicacoes_do_projeto_e_esconde_as_alheias(): void
    {
        $this->conta();
        $this->pecaAprovada();
        $this->meta();
        $this->dispatch();
        $p = Publication::withoutGlobalScopes()->sole();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Viewer));
        $this->getJson("/api/v1/projects/{$this->project->id}/publications")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.content.title', 'Ciclo menstrual')
            ->assertJsonPath('data.0.approver.id', $this->reviewer->id);
        $this->getJson("/api/v1/publications/{$p->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.attempts_log');

        $intruso = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => Workspace::factory()->create()->id, 'user_id' => $intruso->id, 'role' => WorkspaceRole::Owner, 'joined_at' => now()]);
        Sanctum::actingAs($intruso);
        $this->getJson("/api/v1/publications/{$p->id}")->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/publications")->assertNotFound();
        $this->postJson("/api/v1/publications/{$p->id}/retry")->assertNotFound();
        $this->postJson("/api/v1/publications/{$p->id}/resolve", ['outcome' => 'failed'])->assertNotFound();
    }

    public function test_imagem_que_foi_ao_ar_nao_pode_ser_removida(): void
    {
        $this->conta();
        $content = $this->pecaAprovada();
        $this->meta();
        $this->dispatch();

        Sanctum::actingAs($this->reviewer);
        $this->deleteJson("/api/v1/assets/{$content->image_asset_id}")->assertStatus(409);
    }

    // ------------------------------------------------------------ agendar a mao e fuso

    public function test_agendar_a_mao_le_a_hora_no_fuso_do_projeto(): void
    {
        $content = $this->pecaAprovada();
        $content->update(['status' => 'approved', 'scheduled_for' => null]);
        $this->project->update(['timezone' => 'America/Sao_Paulo']);
        Sanctum::actingAs($this->memberOf(WorkspaceRole::Editor));

        $amanha = now('America/Sao_Paulo')->addDay()->format('Y-m-d');

        $this->postJson("/api/v1/contents/{$content->id}/schedule", ['scheduled_for' => "{$amanha}T08:30"])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        // 08:30 em Sao Paulo (UTC-3) = 11:30 UTC.
        $this->assertSame("{$amanha} 11:30:00", $content->fresh()->scheduled_for->toDateTimeString());
    }

    public function test_agendar_no_passado_ou_peca_nao_aprovada_e_recusado(): void
    {
        $content = $this->pecaAprovada();
        Sanctum::actingAs($this->memberOf(WorkspaceRole::Editor));

        // Ja esta agendada, nao aprovada.
        $this->postJson("/api/v1/contents/{$content->id}/schedule", ['scheduled_for' => now()->addDay()->toIso8601String()])
            ->assertStatus(422);

        $content->update(['status' => 'approved', 'scheduled_for' => null]);
        $this->postJson("/api/v1/contents/{$content->id}/schedule", ['scheduled_for' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_for');
    }

    public function test_viewer_nao_agenda_e_peca_alheia_nao_existe(): void
    {
        $content = $this->pecaAprovada();
        $content->update(['status' => 'approved', 'scheduled_for' => null]);
        $quando = now()->addDay()->toIso8601String();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Viewer));
        $this->postJson("/api/v1/contents/{$content->id}/schedule", ['scheduled_for' => $quando])->assertForbidden();

        $intruso = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => Workspace::factory()->create()->id, 'user_id' => $intruso->id, 'role' => WorkspaceRole::Owner, 'joined_at' => now()]);
        Sanctum::actingAs($intruso);
        $this->postJson("/api/v1/contents/{$content->id}/schedule", ['scheduled_for' => $quando])->assertNotFound();

        $this->assertSame('approved', $content->fresh()->status);
    }

    public function test_driver_fake_publica_sem_sair_da_maquina(): void
    {
        config(['instagram.driver' => 'fake']);
        $this->conta();
        $content = $this->pecaAprovada();

        $this->dispatch();

        $p = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('published', $p->status);
        $this->assertStringStartsWith('fake-media-', $p->media_id);
        $this->assertSame('published', $content->fresh()->status);
        Http::assertNothingSent();
    }
}
