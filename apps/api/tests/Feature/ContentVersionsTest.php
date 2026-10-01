<?php

namespace Tests\Feature;

use App\Domain\Editorial\Approval;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\Support\Aprovar;
use Tests\TestCase;

/**
 * CP-04C — Historico editorial e versoes: toda versao gravada (imutavel), comparacao
 * campo a campo, restauracao como versao NOVA (sem herdar aprovacao), concorrencia
 * otimista nas edicoes (expected_version) e acesso so dentro da marca.
 */
class ContentVersionsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $reviewer;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
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

    private function peca(string $status = 'production', array $attrs = []): Content
    {
        return Content::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'title' => 'Mitos da pele', 'caption' => 'O que é verdade sobre hidratação.', 'cta' => 'Salve',
            'hashtags' => ['#pele'], 'format' => 'post', 'channel' => 'instagram',
            'status' => $status, 'created_by' => $this->editor->id, ...$attrs,
        ]);
    }

    private function asset(string $nome, ?string $checksum = null): Asset
    {
        return Asset::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'type' => 'image',
            'disk' => 'public', 'path' => "media/{$nome}", 'original_name' => 'foto.jpg', 'mime' => 'image/jpeg',
            'size_bytes' => 10, 'width' => 1080, 'height' => 1080,
            'checksum' => $checksum ?? hash('sha256', $nome), 'created_by' => $this->editor->id,
        ]);
    }

    private function editar(Content $peca, array $campos, ?int $versao = null)
    {
        return $this->patchJson("/api/v1/contents/{$peca->id}/draft", [
            'expected_version' => $versao ?? (int) $peca->fresh()->version, ...$campos,
        ]);
    }

    private function restaurar(Content $peca, int $versao, ?int $esperada = null)
    {
        return $this->postJson("/api/v1/contents/{$peca->id}/versions/{$versao}/restore", [
            'expected_version' => $esperada ?? (int) $peca->fresh()->version,
        ]);
    }

    // 1 Criacao de versao -----------------------------------------------------------------

    public function test_criar_a_peca_grava_a_versao_1_com_snapshot_e_hash_da_aprovacao(): void
    {
        Sanctum::actingAs($this->editor);
        $peca = $this->peca();

        $v = ContentVersion::sole();
        $this->assertSame([1, 'created', $this->editor->id, $this->project->id], [$v->version, $v->origin, $v->user_id, $v->project_id]);
        $this->assertSame('O que é verdade sobre hidratação.', $v->snapshot['caption']);
        // O mesmo hash que a aprovacao calcula: aprovacao e versao se reconhecem.
        $this->assertSame(Approval::hash(Approval::snapshot($peca)), $v->snapshot_hash);
    }

    // 2 Edicao de conteudo ----------------------------------------------------------------

    public function test_edicao_manual_cria_versao_nova_e_a_anterior_fica_igual(): void
    {
        $peca = $this->peca();
        $v1 = ContentVersion::sole();
        Sanctum::actingAs($this->editor);

        $this->editar($peca, ['caption' => 'Hidratar não é só beber água.'])->assertOk()->assertJsonPath('data.version', 2);

        $v2 = ContentVersion::where('version', 2)->sole();
        $this->assertSame(['manual_edit', $this->editor->id, 'Hidratar não é só beber água.'], [$v2->origin, $v2->user_id, $v2->snapshot['caption']]);
        $this->assertSame($v1->snapshot_hash, $v1->fresh()->snapshot_hash);
        $this->assertSame('O que é verdade sobre hidratação.', $v1->fresh()->snapshot['caption']);
    }

    // 3 Regeneracao pela IA ---------------------------------------------------------------

    public function test_regeneracao_pela_ia_grava_a_versao_com_origem_e_execucao(): void
    {
        $peca = $this->peca('review');
        Aprovar::revisadaPelaIa($peca);
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/contents/{$peca->id}/request-changes", [
            'expected_version' => 1, 'reason' => 'Tire a promessa de resultado.', 'request_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->postJson("/api/v1/contents/{$peca->id}/rewrite:generate", ['expected_version' => $peca->fresh()->version])->assertStatus(202);

        $run = AiRun::where('agent', 'rewriter')->sole();
        $v2 = ContentVersion::where('version', 2)->sole();
        $this->assertSame(['ai_rewrite', $run->id, $this->reviewer->id], [$v2->origin, $v2->ai_run_id, $v2->user_id]);
        $this->assertSame($peca->fresh()->caption, $v2->snapshot['caption']);
    }

    // 4 Consulta de historico -------------------------------------------------------------

    public function test_historico_traz_os_eventos_com_responsavel_versao_e_transicao(): void
    {
        // A linha do tempo ordena pela hora (precisao de segundo nas decisoes e
        // movimentos): os passos acontecem em segundos diferentes, como com pessoas.
        Sanctum::actingAs($this->editor);
        $peca = $this->peca();
        $this->travel(1)->minute();
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();
        $this->travel(1)->minute();
        $this->patchJson("/api/v1/contents/{$peca->id}", ['status' => 'review'])->assertOk();
        $this->travel(1)->minute();
        Aprovar::peca($peca, $this->reviewer);
        $this->travel(1)->minute();
        Sanctum::actingAs($this->editor);
        $this->restaurar($peca->fresh(), 1)->assertOk();

        $eventos = collect($this->getJson("/api/v1/contents/{$peca->id}/history")->assertOk()->json('data.events'));

        $this->assertSame(
            ['created', 'manual_edit', 'sent_to_review', 'approved', 'restored', 'approval_invalidated'],
            $eventos->pluck('type')->all(),
        );
        $aprovado = $eventos->firstWhere('type', 'approved');
        $this->assertSame([2, $this->reviewer->id, 'review', 'approved'],
            [$aprovado['version'], $aprovado['user']['id'], $aprovado['from_status'], $aprovado['to_status']]);
        $this->assertSame([3, 1], [$eventos->firstWhere('type', 'restored')['version'], $eventos->firstWhere('type', 'restored')['restored_from_version']]);
        // Nada sensivel: so id e nome de quem fez.
        $this->assertSame(['id', 'name'], array_keys($aprovado['user']));
    }

    // 5 Comparacao ------------------------------------------------------------------------

    public function test_comparacao_mostra_campos_mudados_e_nao_iguala_midia_pelo_nome(): void
    {
        $a = $this->asset('a.jpg');
        $b = $this->asset('b.jpg'); // mesmo original_name 'foto.jpg', outro arquivo
        $peca = $this->peca('production', ['image_asset_id' => $a->id]);
        Sanctum::actingAs($this->editor);

        $this->editar($peca, ['caption' => 'Nova legenda.', 'hashtags' => ['#pele', '#verao']])->assertOk();
        $this->putJson("/api/v1/contents/{$peca->id}/image", ['asset_id' => $b->id, 'expected_version' => 2])->assertOk();

        $campos = collect($this->getJson("/api/v1/contents/{$peca->id}/versions/compare?from=1&to=3")->assertOk()->json('data.fields'))->keyBy('field');

        $this->assertTrue($campos['caption']['changed']);
        $this->assertSame(['#pele', '#verao'], $campos['hashtags']['to']);
        $this->assertFalse($campos['title']['changed']);
        $this->assertFalse($campos['cta']['changed']);
        $this->assertTrue($campos['image']['changed']);
        $this->assertSame('foto.jpg', $campos['image']['from']['original_name']);
        $this->assertSame('foto.jpg', $campos['image']['to']['original_name']);
        $this->assertSame('Arquivos diferentes (checksums diferentes).', $campos['image']['note']);
        $this->assertEqualsCanonicalizing(
            ['title', 'caption', 'cta', 'hashtags', 'structure', 'format', 'channel', 'published_caption', 'image', 'video', 'slides'],
            $campos->keys()->all(),
        );
    }

    public function test_comparacao_de_slides_reordenados_e_de_arquivo_identico(): void
    {
        [$s1, $s2] = [$this->asset('s1.jpg'), $this->asset('s2.jpg')];
        $peca = $this->peca('production', ['format' => 'carousel']);
        Sanctum::actingAs($this->editor);
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$s1->id, $s2->id], 'expected_version' => 1])->assertOk();
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [$s2->id, $s1->id], 'expected_version' => 2])->assertOk();

        $slides = collect($this->getJson("/api/v1/contents/{$peca->id}/versions/compare?from=2&to=3")->json('data.fields'))->firstWhere('field', 'slides');
        $this->assertTrue($slides['changed']);
        $this->assertSame('Mesmas imagens, em outra ordem.', $slides['note']);
    }

    // 6 Aprovacao vinculada a versao ------------------------------------------------------

    public function test_aprovacao_fica_presa_a_versao_exata(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();
        Aprovar::peca($peca, $this->reviewer);

        $lista = $this->getJson("/api/v1/contents/{$peca->id}/versions")->assertOk()
            ->assertJsonPath('data.current_version', 2)
            ->assertJsonPath('data.approved_version', 2)
            ->json('data.versions');

        $this->assertSame([false, true], array_column($lista, 'is_approved'));
        $this->assertSame(ContentDecision::sole()->snapshot_hash, ContentVersion::where('version', 2)->sole()->snapshot_hash);
    }

    // 7 Invalidacao apos alteracao --------------------------------------------------------

    public function test_alteracao_depois_da_aprovacao_invalida_e_fica_registrada(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();
        $peca = Aprovar::peca($peca, $this->reviewer);
        $this->assertSame('approved', $peca->status);

        // Qualquer caminho de codigo (aqui, o model direto, como um job faria).
        $peca->update(['cta' => 'Comente']);

        $v3 = ContentVersion::where('version', 3)->sole();
        $this->assertTrue($v3->invalidated_approval);
        $this->assertSame('review', $peca->fresh()->status);
        $this->assertNull(Approval::validApproval($peca->fresh()));
        $this->getJson("/api/v1/contents/{$peca->id}/versions")->assertJsonPath('data.approved_version', null);
    }

    // 8 Restauracao como nova versao / 9 preservacao do historico ------------------------

    public function test_restaurar_cria_versao_nova_sem_herdar_aprovacao_e_preserva_as_antigas(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();
        $peca = Aprovar::peca($peca, $this->reviewer); // aprovada a v2
        $antes = ContentVersion::orderBy('version')->get()->map->only(['version', 'snapshot_hash', 'origin'])->all();

        Sanctum::actingAs($this->editor);
        $this->restaurar($peca, 1)->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.caption', 'O que é verdade sobre hidratação.')
            ->assertJsonPath('data.status', 'review')
            ->assertJsonPath('version.origin', 'restore')
            ->assertJsonPath('version.restored_from_version', 1)
            ->assertJsonPath('version.is_approved', false);

        $peca->refresh();
        $this->assertNull(Approval::validApproval($peca));
        $this->assertNotSame('approved', $peca->editorial_state);
        // A aprovacao da v2 continua no historico, sem valer para a v3.
        $this->assertSame(2, ContentDecision::sole()->version);
        // Versoes antigas intactas.
        $this->assertSame($antes, ContentVersion::where('version', '<', 3)->orderBy('version')->get()->map->only(['version', 'snapshot_hash', 'origin'])->all());
        // O movimento de restauracao tambem fica em content_revisions.
        $this->assertEquals(['from' => 2, 'to' => 3, 'restored_version' => 1],
            ContentRevision::whereNotNull('changes')->latest('id')->first()->changes['restore']);
    }

    public function test_versao_historica_nao_se_edita_nem_se_apaga(): void
    {
        $v = ContentVersion::where('content_id', $this->peca()->id)->sole();

        try {
            $v->update(['origin' => 'manual_edit']);
            $this->fail('Versão foi alterada pelo model.');
        } catch (LogicException) {
        }

        try {
            $v->delete();
            $this->fail('Versão foi apagada pelo model.');
        } catch (LogicException) {
        }

        // Mesmo por SQL direto, o banco recusa (trigger).
        $this->expectException(QueryException::class);
        DB::table('content_versions')->where('id', $v->id)->update(['origin' => 'manual_edit']);
    }

    public function test_restauracao_recusa_versao_igual_inexistente_e_midia_removida(): void
    {
        $a = $this->asset('a.jpg');
        $peca = $this->peca('production', ['image_asset_id' => $a->id]);
        Sanctum::actingAs($this->editor);
        $this->putJson("/api/v1/contents/{$peca->id}/image", ['asset_id' => null, 'expected_version' => 1])->assertOk();
        $this->editar($peca, ['caption' => 'Outra.'])->assertOk();
        $this->editar($peca, ['caption' => 'O que é verdade sobre hidratação.'])->assertOk(); // v4 = texto da v2

        $this->restaurar($peca, 2)->assertUnprocessable()->assertJsonPath('message', 'A versão 2 já é o conteúdo atual.');
        $this->restaurar($peca, 99)->assertUnprocessable();

        $this->deleteJson("/api/v1/assets/{$a->id}")->assertNoContent();
        $this->restaurar($peca, 1)->assertUnprocessable();
        $this->assertSame(4, $peca->fresh()->version);
    }

    public function test_peca_agendada_nao_restaura(): void
    {
        Queue::fake();
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();
        $peca = Aprovar::peca($peca, $this->reviewer);
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/contents/{$peca->id}/schedule", ['scheduled_for' => now()->addDay()->toIso8601String()])->assertOk();

        $this->restaurar($peca, 1)->assertStatus(409);
        $this->assertSame(2, $peca->fresh()->version);
    }

    // 10 Edicao concorrente / 11 versao desatualizada ------------------------------------

    public function test_edicao_concorrente_a_segunda_recebe_409_e_nao_sobrescreve(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);

        $this->editar($peca, ['caption' => 'Da Ana.'], 1)->assertOk();
        $this->editar($peca, ['caption' => 'Do Beto.'], 1)
            ->assertStatus(409)
            ->assertJsonPath('current_version', 2);

        $this->assertSame('Da Ana.', $peca->fresh()->caption);
        $this->assertSame(2, ContentVersion::count());
    }

    public function test_versao_desatualizada_e_409_em_toda_edicao_restauracao_e_aprovacao(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();

        $this->putJson("/api/v1/contents/{$peca->id}/image", ['asset_id' => null, 'expected_version' => 1])->assertStatus(409);
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => [], 'expected_version' => 1])->assertStatus(409);
        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => null, 'expected_version' => 1])->assertStatus(409);
        $this->restaurar($peca, 1, 1)->assertStatus(409)->assertJsonPath('current_version', 2);

        $pedido = Aprovar::pedido($peca);
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/contents/{$peca->id}/approve", [...$pedido, 'expected_version' => 1])->assertStatus(409);

        $this->assertSame(2, $peca->fresh()->version);
    }

    public function test_edicao_sem_expected_version_e_422(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);

        $this->patchJson("/api/v1/contents/{$peca->id}/draft", ['caption' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("/api/v1/contents/{$peca->id}/image", ['asset_id' => null])->assertJsonValidationErrors('expected_version');
        $this->putJson("/api/v1/contents/{$peca->id}/slides", ['asset_ids' => []])->assertJsonValidationErrors('expected_version');
        $this->putJson("/api/v1/contents/{$peca->id}/video", ['asset_id' => null])->assertJsonValidationErrors('expected_version');
        $this->postJson("/api/v1/contents/{$peca->id}/versions/1/restore")->assertJsonValidationErrors('expected_version');
    }

    // 12 Numeracao duplicada --------------------------------------------------------------

    public function test_numero_de_versao_nao_se_repete(): void
    {
        $peca = $this->peca();
        $velha = Content::find($peca->id); // outra copia em memoria, ainda na v1

        $peca->update(['caption' => 'A.']); // v2

        // A copia velha calcularia v2 de novo: o unico (content_id, version) recusa, e a
        // transacao desfaz a mudanca da peca junto.
        try {
            DB::transaction(fn () => $velha->update(['caption' => 'B.']));
            $this->fail('Gravou a versão 2 duas vezes.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame(['A.', 2], [$peca->fresh()->caption, $peca->fresh()->version]);
        $this->assertSame([1, 2], ContentVersion::orderBy('version')->pluck('version')->all());
    }

    // 13 Outra marca / 14 sem autorizacao -------------------------------------------------

    public function test_outra_marca_nao_ve_nem_compara_nem_restaura(): void
    {
        $peca = $this->peca();
        $this->peca()->update(['caption' => 'x']);
        $estranho = $this->membro(WorkspaceRole::Owner, Workspace::factory()->create());
        Sanctum::actingAs($estranho);

        $this->getJson("/api/v1/contents/{$peca->id}/versions")->assertNotFound();
        $this->getJson("/api/v1/contents/{$peca->id}/versions/1")->assertNotFound();
        $this->getJson("/api/v1/contents/{$peca->id}/versions/compare?from=1&to=1")->assertNotFound();
        $this->getJson("/api/v1/contents/{$peca->id}/history")->assertNotFound();
        $this->restaurar($peca, 1, 1)->assertNotFound();
    }

    public function test_sem_sessao_401_e_leitor_nao_restaura(): void
    {
        $peca = $this->peca();
        $peca->update(['caption' => 'x']);

        $this->getJson("/api/v1/contents/{$peca->id}/versions")->assertUnauthorized();
        $this->restaurar($peca, 1)->assertUnauthorized();

        Sanctum::actingAs($this->membro(WorkspaceRole::Viewer));
        // Ler pode; restaurar e editar, nao.
        $this->getJson("/api/v1/contents/{$peca->id}/versions")->assertOk();
        $this->restaurar($peca, 1)->assertForbidden();
        $this->assertSame(2, $peca->fresh()->version);
    }

    public function test_autoria_vem_da_sessao_e_nao_do_corpo(): void
    {
        $peca = $this->peca();
        $peca->update(['caption' => 'x']);
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/v1/contents/{$peca->id}/versions/1/restore", [
            'expected_version' => 2, 'user_id' => $this->reviewer->id, 'project_id' => 999, 'origin' => 'backfill',
        ])->assertOk();

        $v = ContentVersion::where('version', 3)->sole();
        $this->assertSame([$this->editor->id, 'restore', $this->project->id], [$v->user_id, $v->origin, $v->project_id]);
    }

    // 15 Falha transacional ---------------------------------------------------------------

    public function test_falha_ao_gravar_a_versao_desfaz_a_edicao(): void
    {
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        ContentVersion::creating(fn () => throw new RuntimeException('disco cheio'));
        $this->withoutExceptionHandling();

        try {
            $this->editar($peca, ['caption' => 'Não pode ficar.']);
            $this->fail('A edição passou sem gravar a versão.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['O que é verdade sobre hidratação.', 1], [$peca->fresh()->caption, $peca->fresh()->version]);
        $this->assertSame(0, ContentRevision::whereNotNull('changes')->count());
    }

    public function test_falha_no_meio_da_restauracao_desfaz_tudo(): void
    {
        $peca = $this->peca();
        $peca->update(['caption' => 'Versão dois.']);
        Sanctum::actingAs($this->editor);
        ContentRevision::creating(fn () => throw new RuntimeException('falhou a auditoria'));
        $this->withoutExceptionHandling();

        try {
            $this->restaurar($peca, 1);
            $this->fail('Restaurou sem auditoria.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['Versão dois.', 2], [$peca->fresh()->caption, $peca->fresh()->version]);
        $this->assertSame(2, ContentVersion::count());
    }

    // 16 Sem publicacao externa -----------------------------------------------------------

    public function test_editar_comparar_e_restaurar_nao_publicam_nada(): void
    {
        Queue::fake();
        $peca = $this->peca();
        Sanctum::actingAs($this->editor);
        $this->editar($peca, ['caption' => 'Versão dois.'])->assertOk();
        $this->getJson("/api/v1/contents/{$peca->id}/versions/compare?from=1&to=2")->assertOk();
        $this->restaurar($peca, 1)->assertOk();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, Publication::count());
    }
}
