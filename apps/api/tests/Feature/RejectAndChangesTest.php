<?php

namespace Tests\Feature;

use App\Domain\Editorial\Approval;
use App\Domain\Publishing\PublishGate;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\Support\Aprovar;
use Tests\TestCase;

/**
 * CP-04B — Rejeitar e Solicitar ajustes: mesma permissao (`approve`), versao esperada,
 * justificativa, idempotencia por `request_key`, auditoria na transacao. Rejeitada
 * nunca volta direto para aprovada; ajuste pendente bloqueia agendar e publicar.
 */
class RejectAndChangesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $reviewer;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

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
        return Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'title' => 'Mitos da pele', 'caption' => 'O que é verdade sobre hidratação.', 'cta' => 'Salve',
            'hashtags' => ['#pele'], 'format' => 'post', 'channel' => 'instagram',
            'status' => $status, 'created_by' => $this->editor->id,
        ]);
    }

    private function corpo(Content $peca, string $motivo = 'Troque o CTA por um convite a comentar.', ?int $versao = null): array
    {
        return ['expected_version' => $versao ?? (int) $peca->fresh()->version, 'reason' => $motivo, 'request_key' => (string) Str::uuid()];
    }

    private function pedirAjustes(Content $peca, array $corpo)
    {
        return $this->postJson("/api/v1/contents/{$peca->id}/request-changes", $corpo);
    }

    private function rejeitar(Content $peca, array $corpo)
    {
        return $this->postJson("/api/v1/contents/{$peca->id}/reject", $corpo);
    }

    // 1 ----------------------------------------------------------------------------------

    public function test_pedir_ajustes_valido_registra_tudo_e_preserva_o_conteudo(): void
    {
        $peca = $this->peca();
        $corpo = $this->corpo($peca);
        Sanctum::actingAs($this->reviewer);

        $this->pedirAjustes($peca, $corpo)
            ->assertOk()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('data.status', 'production')
            ->assertJsonPath('data.editorial_state', 'needs_revision')
            ->assertJsonPath('decision.decision', 'changes_requested')
            ->assertJsonPath('decision.user.id', $this->reviewer->id);

        $d = ContentDecision::sole();
        $this->assertSame([$this->project->id, 1, 'review', 'production', $corpo['reason'], $corpo['request_key']],
            [$d->project_id, $d->version, $d->from_status, $d->to_status, $d->reason, $d->request_key]);
        $this->assertNotNull($d->created_at);
        // O conteudo nao muda: pedir ajuste nao reescreve nada.
        $this->assertSame('O que é verdade sobre hidratação.', $peca->fresh()->caption);
        $this->assertSame(1, $peca->fresh()->version);
    }

    public function test_pedir_ajustes_em_peca_aprovada_derruba_a_aprovacao(): void
    {
        $peca = Aprovar::peca($this->peca(), $this->reviewer);
        $this->assertNotNull(Approval::validApproval($peca));
        Sanctum::actingAs($this->reviewer);

        $this->pedirAjustes($peca, $this->corpo($peca))->assertOk()->assertJsonPath('data.status', 'production');

        $peca->refresh();
        $this->assertNull(Approval::validApproval($peca));
        $this->assertNull($peca->approved_by);
    }

    // 2 ----------------------------------------------------------------------------------

    public function test_rejeitar_valido_arquiva_e_desarquivar_nao_volta_para_aprovada(): void
    {
        $peca = Aprovar::peca($this->peca(), $this->reviewer);
        Sanctum::actingAs($this->reviewer);

        $this->rejeitar($peca, $this->corpo($peca, 'Fala de tratamento; a marca não indica tratamento.'))
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.editorial_state', 'rejected');

        // Reaproveitar e um novo ciclo: volta para producao, nunca para aprovada.
        $this->postJson("/api/v1/contents/{$peca->id}/unarchive")->assertOk()->assertJsonPath('data.status', 'production');
        $this->assertNull(Approval::validApproval($peca->fresh()));
    }

    public function test_peca_agendada_se_desagenda_antes_de_decidir(): void
    {
        $peca = Aprovar::peca($this->peca(), $this->reviewer);
        $peca->update(['status' => 'scheduled', 'scheduled_for' => now()->addDay()]);
        Sanctum::actingAs($this->reviewer);

        $this->rejeitar($peca, $this->corpo($peca))
            ->assertStatus(409)
            ->assertJsonPath('message', 'A peça está agendada: desagende antes de decidir sobre ela.');
    }

    // 3 ----------------------------------------------------------------------------------

    public function test_justificativa_ausente_ou_em_branco_e_recusada(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);

        foreach ([null, '', '   ', 'ok'] as $motivo) {
            $corpo = $this->corpo($peca, 'x');
            $corpo['reason'] = $motivo;
            $this->rejeitar($peca, $corpo)->assertUnprocessable()->assertJsonValidationErrors('reason');
            $this->pedirAjustes($peca, $corpo)->assertUnprocessable()->assertJsonValidationErrors('reason');
        }

        $this->assertSame(0, ContentDecision::count());
    }

    // 4 / 5 ------------------------------------------------------------------------------

    public function test_nao_autorizado_e_outra_marca(): void
    {
        $peca = $this->peca();
        $corpo = $this->corpo($peca);

        $this->rejeitar($peca, $corpo)->assertUnauthorized();

        foreach ([$this->editor, $this->membro(WorkspaceRole::Viewer)] as $quem) {
            Sanctum::actingAs($quem);
            $this->rejeitar($peca, $corpo)->assertForbidden();
            $this->pedirAjustes($peca, $corpo)->assertForbidden();
        }

        Sanctum::actingAs($this->membro(WorkspaceRole::Owner, Workspace::factory()->create()));
        $this->rejeitar($peca, $corpo)->assertNotFound();
        $this->pedirAjustes($peca, $corpo)->assertNotFound();

        $this->assertSame(0, ContentDecision::count());
        $this->assertSame('review', $peca->fresh()->status);
    }

    // 6 / 8 ------------------------------------------------------------------------------

    public function test_versao_desatualizada_e_edicao_concorrente(): void
    {
        $peca = $this->peca();
        $vistaPeloRevisor = $this->corpo($peca);

        // O editor muda o texto enquanto o revisor escreve o pedido de ajuste.
        Sanctum::actingAs($this->editor);
        $this->patchJson("/api/v1/contents/{$peca->id}/draft", ['caption' => 'Texto novo.'])->assertOk();

        Sanctum::actingAs($this->reviewer);
        $this->pedirAjustes($peca, $vistaPeloRevisor)->assertStatus(409)->assertJsonPath('version', 2);
        $this->rejeitar($peca, [...$vistaPeloRevisor, 'request_key' => (string) Str::uuid()])->assertStatus(409);

        $this->assertSame(0, ContentDecision::count());
        $this->assertSame('review', $peca->fresh()->status);
    }

    // 7 ----------------------------------------------------------------------------------

    public function test_requisicao_duplicada_devolve_o_original_e_chave_com_outro_pedido_e_recusada(): void
    {
        $peca = $this->peca();
        $corpo = $this->corpo($peca);
        Sanctum::actingAs($this->reviewer);

        $id = $this->pedirAjustes($peca, $corpo)->assertOk()->json('decision.id');
        $this->pedirAjustes($peca, $corpo)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('decision.id', $id);

        // Mesma chave, outro motivo: recusado, nada gravado.
        $this->pedirAjustes($peca, [...$corpo, 'reason' => 'Outro motivo qualquer.'])
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');
        // Mesma chave, outra acao: recusado.
        $this->rejeitar($peca, $corpo)->assertUnprocessable()->assertJsonValidationErrors('request_key');

        $this->assertSame(1, ContentDecision::count());
        $this->assertSame(1, ContentRevision::where('content_id', $peca->id)->where('to_status', 'production')->count());
    }

    // 9 ----------------------------------------------------------------------------------

    public function test_falha_na_auditoria_desfaz_a_decisao(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);
        Event::listen('eloquent.creating: '.ContentRevision::class, function () {
            throw new RuntimeException('auditoria indisponível');
        });

        $this->rejeitar($peca, $this->corpo($peca))->assertStatus(500);

        $this->assertSame(0, ContentDecision::count());
        $this->assertSame('review', $peca->fresh()->status);
    }

    // 10 ---------------------------------------------------------------------------------

    public function test_nova_versao_apos_ajustes_por_edicao_volta_ao_fluxo_sem_herdar_aprovacao(): void
    {
        $peca = Aprovar::peca($this->peca(), $this->reviewer);
        // O pedido de ajuste vem DEPOIS da revisao da IA (o helper a data 1 s a frente).
        $this->travel(10)->seconds();
        Sanctum::actingAs($this->reviewer);
        $this->pedirAjustes($peca, $this->corpo($peca))->assertOk();

        // Nova versao: editada a mao.
        Sanctum::actingAs($this->editor);
        $this->patchJson("/api/v1/contents/{$peca->id}/draft", ['cta' => 'Comente sua dúvida.'])->assertOk();
        $peca->refresh();
        $this->assertSame(2, $peca->version);
        $this->assertSame('needs_revision', $peca->editorial_state);
        $this->assertNull(Approval::validApproval($peca));

        // De volta a revisao: o "pass" antigo da IA nao vale depois da decisao humana.
        $this->patchJson("/api/v1/contents/{$peca->id}", ['status' => 'review'])->assertOk();
        $this->assertSame('in_review', $peca->fresh()->editorial_state);

        // Nova revisao da IA -> aguarda aprovacao humana -> aprovada na versao 2.
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/contents/{$peca->id}/approve", Aprovar::pedido($peca))->assertOk();
        $this->assertSame(2, Approval::validApproval($peca->fresh())?->version);

        // O historico guarda as tres decisoes, em ordem.
        $this->assertSame(['approved', 'changes_requested', 'approved'], ContentDecision::orderBy('id')->pluck('decision')->all());
        $this->assertSame([1, 1, 2], ContentDecision::orderBy('id')->pluck('version')->all());
    }

    /** Sem nenhuma edicao: voltar a revisao nao reaproveita o "pass" antigo da IA. */
    public function test_decisao_humana_depois_do_veredito_exige_nova_revisao_da_ia(): void
    {
        $peca = $this->peca();
        Aprovar::revisadaPelaIa($peca);
        $this->assertSame('pending_approval', $peca->fresh()->editorial_state);
        $this->travel(10)->seconds();

        Sanctum::actingAs($this->reviewer);
        $this->pedirAjustes($peca, $this->corpo($peca))->assertOk();
        $this->patchJson("/api/v1/contents/{$peca->id}", ['status' => 'review'])->assertOk();

        $this->assertSame(1, $peca->fresh()->version);
        $this->assertSame('in_review', $peca->fresh()->editorial_state);
        $this->postJson("/api/v1/contents/{$peca->id}/approve", [
            'expected_version' => 1, 'request_key' => (string) Str::uuid(),
        ])->assertStatus(409);
    }

    public function test_nova_versao_apos_ajustes_por_regeneracao_da_ia_leva_o_pedido_humano(): void
    {
        $peca = $this->peca();
        Aprovar::revisadaPelaIa($peca);
        Sanctum::actingAs($this->reviewer);
        $this->pedirAjustes($peca, $this->corpo($peca, 'Tire a promessa de resultado.'))->assertOk();

        // A IA aprovou antes; foi a PESSOA que pediu ajuste. O reescritor aceita.
        $this->postJson("/api/v1/contents/{$peca->id}/rewrite:generate")->assertStatus(202);

        $run = AiRun::where('agent', 'rewriter')->sole();
        $this->assertSame('succeeded', $run->status, (string) $run->error);
        $peca->refresh();
        $this->assertSame(2, $peca->version);
        $this->assertNotSame('O que é verdade sobre hidratação.', $peca->caption);
        $this->assertNull(Approval::validApproval($peca));
        // O conteudo anterior fica no historico (de/para), nao some.
        $this->assertSame('O que é verdade sobre hidratação.',
            ContentRevision::where('content_id', $peca->id)->whereNotNull('changes')->latest('id')->first()->changes['de']['caption']);
    }

    // 11 / 12 / 14 -----------------------------------------------------------------------

    public function test_rejeitada_e_com_ajustes_pendentes_nao_agendam_nem_publicam(): void
    {
        Queue::fake();
        Http::fake();
        $rejeitada = Aprovar::peca($this->peca(), $this->reviewer);
        $comAjustes = Aprovar::peca($this->peca(), $this->reviewer);
        Sanctum::actingAs($this->reviewer);
        $this->rejeitar($rejeitada, $this->corpo($rejeitada))->assertOk();
        $this->pedirAjustes($comAjustes, $this->corpo($comAjustes))->assertOk();

        foreach ([$rejeitada, $comAjustes] as $peca) {
            $this->postJson("/api/v1/contents/{$peca->id}/schedule", ['scheduled_for' => now()->addDay()->toIso8601String()])
                ->assertUnprocessable();

            // Mesmo "agendada a forca" no banco, a porta de publicacao recusa.
            DB::table('contents')->where('id', $peca->id)->update([
                'status' => 'scheduled', 'scheduled_for' => now()->subMinute(),
                'approved_by' => $this->reviewer->id, 'approved_at' => now(),
            ]);
            $this->assertStringContainsString('não vale para esta versão', PublishGate::refusal($peca->fresh()));
        }

        // As decisoes nao enfileiram nada nem falam com a rede.
        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // 13 ---------------------------------------------------------------------------------

    public function test_historico_preserva_decisoes_motivos_e_chaves_e_e_imutavel(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->reviewer);
        $pedido = $this->corpo($peca, 'Primeiro ajuste.');
        $this->pedirAjustes($peca, $pedido)->assertOk();

        $historico = $this->getJson("/api/v1/contents/{$peca->id}/history")->assertOk()->json('data');

        $this->assertSame('Primeiro ajuste.', $historico['decisions'][0]['reason']);
        $this->assertSame($pedido['request_key'], $historico['decisions'][0]['request_key']);
        $this->assertSame('production', $historico['decisions'][0]['to_status']);
        $this->assertStringNotContainsString('token', json_encode($historico));

        $this->expectException(LogicException::class);
        ContentDecision::sole()->delete();
    }
}
