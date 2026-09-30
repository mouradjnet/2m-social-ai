<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentReview;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\Aprovar;
use Tests\TestCase;

/**
 * CP-04A — a acao Aprovar: permissao `approve` no servidor, estado
 * `pending_approval`, versao esperada, transacao unica (decisao + status +
 * auditoria) e idempotencia por `request_key`. Sem rede.
 */
class ApproveActionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->reviewer = $this->membro(WorkspaceRole::Reviewer);
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
        return Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'title' => 'Sono e bem-estar', 'caption' => 'Uma rotina simples.', 'cta' => 'Salve',
            'hashtags' => ['#bemestar'], 'format' => 'post', 'channel' => 'instagram',
            'status' => $status, 'created_by' => $this->reviewer->id,
        ]);
    }

    private function aprovar(Content $peca, array $corpo)
    {
        return $this->postJson("/api/v1/contents/{$peca->id}/approve", $corpo);
    }

    // --- Aprovacao valida ---------------------------------------------------------------

    public function test_aprovacao_valida_ignora_autoridade_vinda_do_cliente(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);
        $outro = User::factory()->create();
        Sanctum::actingAs($this->reviewer);

        $this->aprovar($peca, [
            ...$pedido,
            // Tentativas de impor autoridade pelo corpo: ignoradas.
            'approved_by' => $outro->id, 'user_id' => $outro->id,
            'project_id' => 999, 'brand_id' => 999, 'role' => 'owner',
        ])
            ->assertOk()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('decision.version', $pedido['expected_version'])
            ->assertJsonPath('decision.user.id', $this->reviewer->id)
            ->assertJsonPath('data.approved_by', $this->reviewer->id)
            ->assertJsonPath('data.editorial_state', 'approved');

        $decisao = ContentDecision::sole();
        $this->assertSame($this->reviewer->id, $decisao->user_id);
        $this->assertSame($this->project->id, $decisao->project_id);
        $this->assertSame($pedido['request_key'], $decisao->request_key);
        $this->assertNotNull($decisao->snapshot_hash);
    }

    // --- Autenticacao, permissao, marca, existencia -------------------------------------

    public function test_sem_sessao_e_401(): void
    {
        $peca = $this->peca();

        $this->aprovar($peca, Aprovar::pedido($peca))->assertUnauthorized();
        $this->assertSame(0, ContentDecision::count());
    }

    public function test_sem_permissao_de_aprovar_e_403_em_portugues(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);

        foreach ([WorkspaceRole::Editor, WorkspaceRole::Viewer] as $papel) {
            Sanctum::actingAs($this->membro($papel));
            $this->aprovar($peca, $pedido)
                ->assertForbidden()
                ->assertJsonPath('message', 'Só quem revisa pode decidir sobre uma peça.');
        }

        $this->assertSame(0, ContentDecision::count());
        $this->assertSame('review', $peca->fresh()->status);
    }

    public function test_outra_marca_e_conteudo_inexistente_sao_404(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);

        Sanctum::actingAs($this->membro(WorkspaceRole::Owner, Workspace::factory()->create()));
        $this->aprovar($peca, $pedido)->assertNotFound();

        Sanctum::actingAs($this->reviewer);
        $this->postJson('/api/v1/contents/999999/approve', $pedido)->assertNotFound();

        $this->assertSame(0, ContentDecision::count());
    }

    public function test_payload_invalido_e_422(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);

        $this->aprovar($peca, ['expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->aprovar($peca, ['expected_version' => 1, 'request_key' => 'nao-e-uuid'])->assertJsonValidationErrors('request_key');
        $this->aprovar($peca, ['request_key' => (string) Str::uuid()])->assertJsonValidationErrors('expected_version');

        $this->assertSame(0, ContentDecision::count());
    }

    // --- Estado --------------------------------------------------------------------------

    public function test_so_pending_approval_pode_ser_aprovada(): void
    {
        Sanctum::actingAs($this->reviewer);
        $chave = fn () => (string) Str::uuid();

        // Rascunho.
        $rascunho = $this->peca('idea');
        $this->aprovar($rascunho, ['expected_version' => 1, 'request_key' => $chave()])->assertStatus(409);

        // Em revisao, sem veredito da IA (in_review).
        $semIa = $this->peca('review');
        $this->aprovar($semIa, ['expected_version' => 1, 'request_key' => $chave()])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, "'in_review'"));

        // Reprovada pela IA (needs_revision).
        $reprovada = $this->peca('review');
        $run = AiRun::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'agent' => 'reviewer',
            'provider' => 'mock', 'model' => 'm', 'status' => 'succeeded', 'input' => [], 'created_by' => $this->reviewer->id,
        ]);
        ContentReview::create([
            'content_id' => $reprovada->id, 'ai_run_id' => $run->id, 'verdict' => 'fail', 'summary' => 's',
            'violations' => [['rule' => 'r', 'excerpt' => 'e', 'suggestion' => 's']],
        ]);
        $this->aprovar($reprovada, ['expected_version' => 1, 'request_key' => $chave()])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, "'needs_revision'"));

        // Ja aprovada (valida) e agendada.
        $aprovada = Aprovar::peca($this->peca());
        $this->aprovar($aprovada, ['expected_version' => 1, 'request_key' => $chave()])->assertStatus(409);
        $aprovada->update(['status' => 'scheduled', 'scheduled_for' => now()->addDay()]);
        $this->aprovar($aprovada, ['expected_version' => 1, 'request_key' => $chave()])->assertStatus(409);

        // So a aprovacao feita pelo helper existe.
        $this->assertSame(1, ContentDecision::count());
    }

    public function test_versao_desatualizada_e_409_com_a_versao_atual(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);
        $peca->update(['caption' => 'Mudou antes da aprovação.']);
        Aprovar::revisadaPelaIa($peca);
        Sanctum::actingAs($this->reviewer);

        $this->aprovar($peca, $pedido)->assertStatus(409)->assertJsonPath('version', 2);
        $this->assertSame(0, ContentDecision::count());
    }

    // --- Idempotencia --------------------------------------------------------------------

    public function test_mesma_chave_e_mesmo_pedido_devolvem_o_resultado_original(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);
        Sanctum::actingAs($this->reviewer);

        $primeira = $this->aprovar($peca, $pedido)->assertOk()->assertJsonPath('replayed', false)->json('decision');
        $segunda = $this->aprovar($peca, $pedido)->assertOk()->assertJsonPath('replayed', true)->json('decision');

        $this->assertSame($primeira['id'], $segunda['id']);
        $this->assertSame(1, ContentDecision::count());
        // Nem a auditoria duplica: um movimento review -> approved.
        $this->assertSame(1, ContentRevision::where('content_id', $peca->id)->where('to_status', 'approved')->count());
    }

    public function test_mesma_chave_com_outro_pedido_e_recusada(): void
    {
        $a = $this->peca();
        $b = $this->peca();
        $pedidoA = Aprovar::pedido($a);
        $pedidoB = Aprovar::pedido($b);
        Sanctum::actingAs($this->reviewer);

        $this->aprovar($a, $pedidoA)->assertOk();

        $this->aprovar($b, [...$pedidoB, 'request_key' => $pedidoA['request_key']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_key');

        $this->assertSame(1, ContentDecision::count());
        $this->assertSame('review', $b->fresh()->status);
    }

    public function test_o_banco_nao_aceita_duas_decisoes_com_a_mesma_chave_do_mesmo_usuario(): void
    {
        $peca = $this->peca();
        $chave = (string) Str::uuid();
        $linha = fn () => [
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'content_id' => $peca->id,
            'version' => 1, 'decision' => 'changes_requested', 'reason' => 'x', 'from_status' => 'review',
            'to_status' => 'production', 'user_id' => $this->reviewer->id, 'request_key' => $chave,
        ];

        ContentDecision::create($linha());

        $this->expectException(UniqueConstraintViolationException::class);
        ContentDecision::create($linha());
    }

    /**
     * Dois revisores aprovando a mesma peca, cada um com a sua chave: a linha travada
     * deixa uma so passar; a outra ve a peca ja aprovada (409). E aprovar nao agenda,
     * nao enfileira nada e nao fala com a rede.
     */
    public function test_aprovacao_simultanea_passa_uma_e_aprovar_nao_agenda_nem_publica(): void
    {
        Queue::fake();
        Http::fake();
        $peca = $this->peca();
        $pedidoA = Aprovar::pedido($peca);
        $pedidoB = [...$pedidoA, 'request_key' => (string) Str::uuid()];

        Sanctum::actingAs($this->reviewer);
        $this->aprovar($peca, $pedidoA)->assertOk();

        Sanctum::actingAs($this->membro(WorkspaceRole::Reviewer));
        $this->aprovar($peca, $pedidoB)->assertStatus(409);

        $this->assertSame(1, ContentDecision::count());
        $peca->refresh();
        $this->assertSame('approved', $peca->status);
        $this->assertNull($peca->scheduled_for);
        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // --- Transacao ----------------------------------------------------------------------

    public function test_falha_na_auditoria_desfaz_tudo(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);
        Sanctum::actingAs($this->reviewer);

        Event::listen('eloquent.creating: '.ContentRevision::class, function () {
            throw new RuntimeException('disco cheio na auditoria');
        });

        $this->aprovar($peca, $pedido)->assertStatus(500);

        $this->assertSame(0, ContentDecision::count());
        $peca->refresh();
        $this->assertSame('review', $peca->status);
        $this->assertNull($peca->approved_by);
    }

    public function test_falha_depois_de_gravar_a_decisao_desfaz_tudo(): void
    {
        $peca = $this->peca();
        $pedido = Aprovar::pedido($peca);
        Sanctum::actingAs($this->reviewer);

        Event::listen('eloquent.created: '.ContentDecision::class, function () {
            throw new RuntimeException('queda no meio da transacao');
        });

        $this->aprovar($peca, $pedido)->assertStatus(500);

        $this->assertSame(0, ContentDecision::count());
        $this->assertSame('review', $peca->fresh()->status);
        $this->assertSame(0, ContentRevision::where('content_id', $peca->id)->where('to_status', 'approved')->count());
    }
}
