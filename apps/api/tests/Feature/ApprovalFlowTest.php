<?php

namespace Tests\Feature;

use App\Domain\Editorial\Approval;
use App\Domain\Publishing\Dispatcher;
use App\Domain\Publishing\Publisher;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentReview;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Support\Aprovar;
use Tests\TestCase;

/**
 * CP-04 — a regra inegociavel: nenhuma peca e agendada nem publicada sem aprovacao
 * humana explicita, valida e presa a versao exata do conteudo. A Meta e simulada
 * (Http::fake); nenhuma publicacao real acontece.
 */
class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://graph.instagram.com/v23.0/';

    private Workspace $workspace;

    private Project $project;

    private User $reviewer;

    private User $editor;

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
        $this->reviewer = $this->membro(WorkspaceRole::Reviewer);
        $this->editor = $this->membro(WorkspaceRole::Editor);
    }

    private function membro(WorkspaceRole $papel, ?Workspace $workspace = null): User
    {
        $user = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => ($workspace ?? $this->workspace)->id, 'user_id' => $user->id,
            'role' => $papel, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function peca(string $status = 'review'): Content
    {
        $imagem = Asset::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'type' => 'image',
            'disk' => 'public', 'path' => 'media/capa.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10,
            'width' => 1080, 'height' => 1080, 'checksum' => str_repeat('c', 64), 'created_by' => $this->editor->id,
        ]);

        return Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'title' => 'Autocuidado em 5 minutos', 'caption' => 'Pequenos hábitos que cabem na rotina.',
            'cta' => 'Salve para depois', 'hashtags' => ['#autocuidado'], 'format' => 'post',
            'channel' => 'instagram', 'status' => $status, 'image_asset_id' => $imagem->id,
            'created_by' => $this->editor->id,
        ]);
    }

    private function aprovar(Content $peca, ?int $versao = null)
    {
        // CP-04A: em pending_approval (revisada pela IA nesta versao), com request_key.
        $payload = Aprovar::pedido($peca);

        return $this->postJson("/api/v1/contents/{$peca->id}/approve", [
            ...$payload,
            'expected_version' => $versao ?? $payload['expected_version'],
        ]);
    }

    private function conta(): void
    {
        InstagramAccount::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'ig_user_id' => '1784', 'username' => '2msaudefeminina', 'account_type' => 'BUSINESS',
            'access_token' => 'token-da-conta', 'token_expires_at' => now()->addDays(50), 'token_refreshed_at' => now(),
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            'status' => 'active', 'connected_by' => $this->reviewer->id, 'connected_at' => now(),
        ]);
    }

    private function meta(): void
    {
        Http::fake([
            self::G.'1784/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 100]]]]),
            self::G.'1784/media_publish' => Http::response(['id' => 'midia-1']),
            self::G.'1784/media' => Http::response(['id' => 'container-1']),
            self::G.'1784/media?*' => Http::response(['data' => []]),
            self::G.'container-1?*' => Http::response(['status_code' => 'FINISHED', 'id' => 'container-1']),
            self::G.'midia-1?*' => Http::response(['id' => 'midia-1', 'permalink' => 'https://www.instagram.com/p/X/', 'timestamp' => '2026-09-30T12:00:00+0000']),
        ]);
    }

    private function publicouNaMeta(): int
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::G.'1784/media_publish')->count();
    }

    /** Aprovada pela rota e agendada para ja (vencida para o Dispatcher). */
    private function aprovadaEAgendada(): Content
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca)->assertOk();
        $peca->refresh()->update(['status' => 'scheduled', 'scheduled_for' => now()->subMinute()]);

        return $peca->refresh();
    }

    // 1 -------------------------------------------------------------------------------

    public function test_aprovacao_valida_grava_versao_snapshot_e_hash(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);

        $this->aprovar($peca)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.editorial_state', 'approved')
            ->assertJsonPath('decision.decision', 'approved')
            ->assertJsonPath('decision.version', 1);

        $decisao = ContentDecision::sole();
        $this->assertSame($this->reviewer->id, $decisao->user_id);
        $this->assertSame('Pequenos hábitos que cabem na rotina.', $decisao->snapshot['caption']);
        $this->assertSame(Approval::hash($decisao->snapshot), $decisao->snapshot_hash);
        $this->assertSame($decisao->id, Approval::validApproval($peca->fresh())?->id);
    }

    // 2 / 14 ----------------------------------------------------------------------------

    public function test_so_quem_revisa_decide_e_outra_marca_nem_ve_a_peca(): void
    {
        $peca = $this->peca();

        Sanctum::actingAs($this->editor);
        $this->aprovar($peca)->assertForbidden();
        $this->postJson("/api/v1/contents/{$peca->id}/reject", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'não'])->assertForbidden();
        $this->postJson("/api/v1/contents/{$peca->id}/request-changes", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'ajuste'])->assertForbidden();

        Sanctum::actingAs($this->membro(WorkspaceRole::Viewer));
        $this->aprovar($peca)->assertForbidden();

        // Revisor de OUTRO workspace: a peca nao existe para ele (404), nem o historico.
        $alheio = $this->membro(WorkspaceRole::Owner, Workspace::factory()->create());
        Sanctum::actingAs($alheio);
        $this->aprovar($peca)->assertNotFound();
        $this->postJson("/api/v1/contents/{$peca->id}/reject", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'não'])->assertNotFound();
        $this->postJson("/api/v1/contents/{$peca->id}/request-changes", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'x x'])->assertNotFound();
        $this->getJson("/api/v1/contents/{$peca->id}/history")->assertNotFound();

        $this->assertSame(0, ContentDecision::count());
        $this->assertSame('review', $peca->fresh()->status);
    }

    public function test_sem_token_nao_decide(): void
    {
        $this->aprovar($this->peca())->assertUnauthorized();
    }

    // 3 --------------------------------------------------------------------------------

    public function test_rejeitar_exige_motivo_arquiva_e_fica_rejeitada(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);

        $this->postJson("/api/v1/contents/{$peca->id}/reject", ['version' => 1, 'request_key' => (string) Str::uuid()])->assertUnprocessable();

        $this->postJson("/api/v1/contents/{$peca->id}/reject", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'Promete resultado sem base.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.editorial_state', 'rejected');

        $this->assertSame('Promete resultado sem base.', ContentDecision::sole()->reason);
        $this->assertNull(Approval::validApproval($peca->fresh()));
    }

    // 4 --------------------------------------------------------------------------------

    public function test_pedir_ajustes_devolve_para_producao_com_o_motivo(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);

        $this->postJson("/api/v1/contents/{$peca->id}/request-changes", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'Troque o CTA.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'production')
            ->assertJsonPath('data.editorial_state', 'needs_revision');

        $this->assertSame('changes_requested', ContentDecision::sole()->decision);
    }

    // 5 --------------------------------------------------------------------------------

    public function test_mudanca_depois_da_aprovacao_derruba_a_aprovacao_por_qualquer_caminho(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca)->assertOk();

        // Pelo editor: recusado (peca aprovada nao muda o texto).
        $this->patchJson("/api/v1/contents/{$peca->id}/draft", ['expected_version' => $peca->fresh()->version, 'caption' => 'Outra'])->assertUnprocessable();

        // Por qualquer outro caminho de codigo (agente, job): o model derruba a aprovacao.
        $peca->refresh()->update(['hashtags' => ['#outra']]);

        $peca->refresh();
        $this->assertSame(2, $peca->version);
        $this->assertSame('review', $peca->status);
        $this->assertNull($peca->approved_by);
        $this->assertNull(Approval::validApproval($peca));
    }

    // 6 --------------------------------------------------------------------------------

    public function test_regeneracao_de_peca_aprovada_volta_para_revisao_e_nao_sobrescreve_calada(): void
    {
        $peca = $this->peca();

        // A IA aprovou, a pessoa aprovou...
        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca)->assertOk();

        // ...e uma revisao tardia da IA reprova (o reescritor so age sobre reprovada).
        $run = AiRun::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'agent' => 'reviewer',
            'provider' => 'mock', 'model' => 'm', 'status' => 'succeeded', 'input' => [], 'created_by' => $this->reviewer->id,
        ]);
        $tardia = ContentReview::create([
            'content_id' => $peca->id, 'ai_run_id' => $run->id, 'verdict' => 'fail', 'summary' => 's',
            'violations' => [['rule' => 'tom', 'excerpt' => 'e', 'suggestion' => 's']],
        ]);
        $tardia->forceFill(['created_at' => now()->addSeconds(5)])->save();

        // Depois, alguem manda a IA reescrever.
        $this->postJson("/api/v1/contents/{$peca->id}/rewrite:generate", ['expected_version' => $peca->fresh()->version])->assertStatus(202);

        $peca->refresh();
        $this->assertNotSame('Pequenos hábitos que cabem na rotina.', $peca->caption);
        $this->assertSame('review', $peca->status);
        $this->assertSame(2, $peca->version);
        $this->assertNull(Approval::validApproval($peca));
    }

    // 7 / 8 ------------------------------------------------------------------------------

    public function test_aprovar_versao_velha_e_409_e_a_edicao_concorrente_vence(): void
    {
        $peca = $this->peca();
        $versaoVista = $peca->fresh()->version;

        // O editor muda o texto enquanto o revisor esta com a tela aberta.
        Sanctum::actingAs($this->editor);
        $this->patchJson("/api/v1/contents/{$peca->id}/draft", ['expected_version' => $peca->fresh()->version, 'caption' => 'Texto novo do editor.'])->assertOk();

        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca, $versaoVista)
            ->assertStatus(409)
            ->assertJsonPath('version', 2);
        $this->assertSame(0, ContentDecision::count());

        // Conferida a versao atual, a aprovacao cobre o texto NOVO.
        $this->aprovar($peca, 2)->assertOk();
        $this->assertSame('Texto novo do editor.', ContentDecision::sole()->snapshot['caption']);
    }

    // 9 / 11 -----------------------------------------------------------------------------

    public function test_nao_ha_atalho_para_aprovar_nem_para_agendar_sem_aprovacao(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);

        // Mudar o status a seco nao aprova.
        $this->patchJson("/api/v1/contents/{$peca->id}", ['status' => 'approved'])->assertUnprocessable();
        // O cliente nao escolhe a versao: o campo e ignorado no editor.
        $this->patchJson("/api/v1/contents/{$peca->id}/draft", ['expected_version' => 1, 'version' => 99, 'title' => 'Outro título'])->assertOk();
        $this->assertSame(2, $peca->fresh()->version);

        // "Aprovada" no banco, sem decisao humana: nao agenda.
        DB::table('contents')->where('id', $peca->id)->update(['status' => 'approved', 'approved_by' => $this->reviewer->id, 'approved_at' => now()]);
        $this->postJson("/api/v1/contents/{$peca->id}/schedule", ['scheduled_for' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'não vale para esta versão'));
        $this->assertSame('approved', $peca->fresh()->status);
    }

    // 10 --------------------------------------------------------------------------------

    public function test_publicacao_sem_aprovacao_valida_nao_chega_na_meta(): void
    {
        $this->conta();
        $this->meta();
        $peca = $this->peca('review');
        // Agendada "a forca" no banco, sem nenhuma decisao humana.
        DB::table('contents')->where('id', $peca->id)->update([
            'status' => 'scheduled', 'scheduled_for' => now()->subMinute(),
            'approved_by' => $this->reviewer->id, 'approved_at' => now(),
        ]);

        $this->artisan('publications:dispatch')->assertSuccessful();

        $publicacao = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $publicacao->status);
        $this->assertSame(0, $this->publicouNaMeta());
    }

    // 12 --------------------------------------------------------------------------------

    public function test_o_worker_confere_de_novo_e_nao_publica_o_que_mudou_depois_de_preparado(): void
    {
        $this->conta();
        $this->meta();
        $peca = $this->aprovadaEAgendada();

        // Prepara sem rodar o job (como na fila de verdade, que roda depois).
        Queue::fake();
        $publicacao = app(Dispatcher::class)->prepare($peca->fresh());
        $this->assertSame('pending', $publicacao->status);
        $this->assertSame(1, $publicacao->content_version);
        $this->assertNotNull($publicacao->decision_id);

        // A legenda muda por fora do model (o pior caso) antes do worker rodar.
        DB::table('contents')->where('id', $peca->id)->update(['caption' => 'Trocada depois de preparar.']);

        app(Publisher::class)->run($publicacao->id);

        $this->assertSame('cancelled', $publicacao->fresh()->status);
        $this->assertStringContainsString('não vale para esta versão', $publicacao->fresh()->last_error);
        $this->assertSame(0, $this->publicouNaMeta());
    }

    public function test_publicacao_sem_vinculo_com_a_aprovacao_nao_sai(): void
    {
        $this->conta();
        $this->meta();
        $peca = $this->aprovadaEAgendada();
        Queue::fake();
        $publicacao = app(Dispatcher::class)->prepare($peca->fresh());
        // Publicacao antiga, de antes do CP-04: sem decision_id.
        DB::table('publications')->where('id', $publicacao->id)->update(['decision_id' => null]);

        app(Publisher::class)->run($publicacao->id);

        $this->assertSame('cancelled', $publicacao->fresh()->status);
        $this->assertSame(0, $this->publicouNaMeta());
    }

    // 13 --------------------------------------------------------------------------------

    public function test_rodar_de_novo_nao_publica_duas_vezes(): void
    {
        $this->conta();
        $this->meta();
        $this->aprovadaEAgendada();

        $this->artisan('publications:dispatch')->assertSuccessful();
        $this->artisan('publications:dispatch')->assertSuccessful();

        $this->assertSame(1, Publication::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->publicouNaMeta());
        $this->assertSame('published', Publication::withoutGlobalScopes()->sole()->status);
    }

    // 15 --------------------------------------------------------------------------------

    public function test_historico_registra_quem_o_que_versao_e_motivo_e_decisao_e_imutavel(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/contents/{$peca->id}/request-changes", ['version' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'CTA fraco.'])->assertOk();

        $historico = $this->getJson("/api/v1/contents/{$peca->id}/history")->assertOk()->json('data');

        $this->assertSame($peca->project_id, $historico['project_id']);
        $this->assertCount(1, $historico['decisions']);
        $decisao = $historico['decisions'][0];
        $this->assertSame('changes_requested', $decisao['decision']);
        $this->assertSame(1, $decisao['version']);
        $this->assertSame('CTA fraco.', $decisao['reason']);
        $this->assertSame('review', $decisao['from_status']);
        $this->assertSame('production', $decisao['to_status']);
        $this->assertSame($this->reviewer->id, $decisao['user']['id']);
        $this->assertNotEmpty($decisao['at']);
        // Nada sensivel no historico.
        $this->assertStringNotContainsString('token', json_encode($historico));

        $this->expectException(LogicException::class);
        ContentDecision::sole()->update(['reason' => 'reescrita da historia']);
    }

    // 16 --------------------------------------------------------------------------------

    public function test_depois_de_derrubada_a_aprovacao_reaprovar_a_nova_versao_publica(): void
    {
        $this->conta();
        $this->meta();
        $peca = $this->aprovadaEAgendada();

        // Mudou: aprovacao cai, publicacao nao sai.
        $peca->refresh()->update(['caption' => 'Versão corrigida.']);
        $this->assertSame('review', $peca->fresh()->status);

        // Recuperacao: aprova a versao nova e agenda de novo.
        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca)->assertOk();
        $this->postJson("/api/v1/contents/{$peca->id}/schedule", ['scheduled_for' => now()->addMinutes(2)->toIso8601String()])->assertOk();
        $this->travel(3)->minutes();

        $this->artisan('publications:dispatch')->assertSuccessful();

        $publicacao = Publication::withoutGlobalScopes()->sole();
        $this->assertSame('published', $publicacao->status);
        $this->assertSame(2, $publicacao->content_version);
        $this->assertSame('Versão corrigida.', explode("\n\n", $publicacao->caption)[0]);
        $this->assertSame(1, $this->publicouNaMeta());
    }

    // 17 --------------------------------------------------------------------------------

    public function test_cancelar_o_agendamento_cancela_a_publicacao_pendente(): void
    {
        $this->conta();
        $this->meta();
        $peca = $this->aprovadaEAgendada();
        Queue::fake();
        $publicacao = app(Dispatcher::class)->prepare($peca->fresh());

        Sanctum::actingAs($this->reviewer);
        $this->patchJson("/api/v1/contents/{$peca->id}", ['status' => 'approved'])->assertOk();

        app(Publisher::class)->run($publicacao->id);

        $this->assertSame('cancelled', $publicacao->fresh()->status);
        $this->assertSame(0, $this->publicouNaMeta());
        // Desagendar nao muda o conteudo: a aprovacao continua valendo.
        $this->assertSame('approved', $peca->fresh()->editorial_state);
    }

    // 18 --------------------------------------------------------------------------------

    public function test_decisao_persiste_e_volta_na_listagem_ao_recarregar(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca)->assertOk();

        $daLista = collect($this->getJson("/api/v1/projects/{$this->project->id}/contents")->assertOk()->json('data'))
            ->firstWhere('id', $peca->id);

        $this->assertSame('approved', $daLista['status']);
        $this->assertSame('approved', $daLista['editorial_state']);
        $this->assertSame(1, $daLista['version']);
        $this->assertSame('approved', $daLista['latest_decision']['decision']);
    }
}
