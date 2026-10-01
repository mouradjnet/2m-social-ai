<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Aprovar;
use Tests\TestCase;
use ZipArchive;

/**
 * A ENTREGA. Sem API das redes sociais (decisao de produto), o zip E como o conteudo
 * sai daqui — ate agora o cliente via as pecas na tela e copiava uma a uma.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function membro(Workspace $workspace, WorkspaceRole $role): User
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

    /**
     * `approved`/`scheduled` passam pela aprovacao humana de verdade (CP-05A P0-01):
     * o zip so leva peca com aprovacao valida da versao atual. Para simular uma peca
     * aprovada SEM decisao valida (antes do CP-04), use legada().
     */
    private function peca(Project $project, string $status, array $extra = []): Content
    {
        if (! in_array($status, ['approved', 'scheduled'], true)) {
            return $this->cru($project, $status, $extra);
        }

        $quando = $extra['scheduled_for'] ?? null;
        unset($extra['scheduled_for']);

        $peca = Aprovar::peca($this->cru($project, 'review', $extra));

        if ($status === 'scheduled') {
            $peca->update(['status' => 'scheduled', 'scheduled_for' => $quando ?? now()->addDay()]);
        }

        return $peca->fresh();
    }

    /** Status `approved` gravado direto, sem decisao humana: o caso das pecas pre-CP-04. */
    private function legada(Project $project, array $extra = []): Content
    {
        return $this->cru($project, 'approved', $extra);
    }

    private function cru(Project $project, string $status, array $extra = []): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Como ler um laudo cautelar',
            'caption' => "Primeira linha da legenda.\n\nSegunda linha, com acento: transparência.",
            'cta' => 'Chame no WhatsApp',
            'hashtags' => ['#seminovos', '#laudo'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => 'Transparencia',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
            ...$extra,
        ]);
    }

    /** Abre o zip baixado e devolve [nome do arquivo => conteudo]. */
    private function abrir(string $bytes): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'test-zip-');
        file_put_contents($caminho, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true, 'O download nao e um zip valido.');

        $arquivos = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            $arquivos[$nome] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($caminho);

        return $arquivos;
    }

    public function test_o_zip_traz_um_markdown_por_peca_e_o_calendario(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'name' => '2F AutoShop']);

        $this->peca($project, 'scheduled', [
            'scheduled_for' => '2026-08-03T13:00:00Z',
            'image_prompt' => 'A wide, softly lit workshop scene.',
        ]);
        $this->peca($project, 'approved', ['title' => 'Preco na vitrine']);

        Sanctum::actingAs($editor);

        $response = $this->get("/api/v1/projects/{$project->id}/export")->assertOk();

        $this->assertStringContainsString('2f-autoshop-conteudo-', $response->headers->get('content-disposition'));

        $arquivos = $this->abrir($response->streamedContent());

        $this->assertCount(3, $arquivos, 'Esperado: calendario.csv + 2 pecas.');
        $this->assertArrayHasKey('calendario.csv', $arquivos);

        $md = collect($arquivos)->filter(fn ($_, $nome) => str_starts_with($nome, 'pecas/'));
        $this->assertCount(2, $md);

        $agendada = $md->first();

        // O texto sai INTEIRO, com quebras de linha e acento — e o motivo de nao ser um
        // CSV so: ninguem publica a partir de uma celula de planilha.
        $this->assertStringContainsString('# Como ler um laudo cautelar', $agendada);
        $this->assertStringContainsString("Primeira linha da legenda.\n\nSegunda linha, com acento: transparência.", $agendada);
        $this->assertStringContainsString('#seminovos #laudo', $agendada);
        $this->assertStringContainsString('Chame no WhatsApp', $agendada);
        $this->assertStringContainsString('A wide, softly lit workshop scene.', $agendada);
    }

    /**
     * O zip nao pode vazar RASCUNHO. `review` inclui peca que o revisor REPROVOU:
     * entregar isso ao cliente e pior que nao entregar nada.
     */
    public function test_rascunho_e_arquivada_ficam_de_fora(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->peca($project, 'approved', ['title' => 'Vai no zip']);

        foreach (['idea', 'production', 'review', 'archived'] as $status) {
            $this->peca($project, $status, ['title' => "Nao vai: {$status}"]);
        }

        Sanctum::actingAs($editor);

        $arquivos = $this->abrir(
            $this->get("/api/v1/projects/{$project->id}/export")->assertOk()->streamedContent()
        );

        $this->assertCount(2, $arquivos, 'Esperado: calendario.csv + 1 peca aprovada.');

        $tudo = implode("\n", $arquivos);

        $this->assertStringContainsString('Vai no zip', $tudo);
        $this->assertStringNotContainsString('Nao vai', $tudo);
    }

    public function test_o_calendario_traz_data_hora_e_canal_de_cada_peca(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->peca($project, 'scheduled', ['scheduled_for' => '2026-08-03T13:00:00Z']);

        Sanctum::actingAs($editor);

        $arquivos = $this->abrir(
            $this->get("/api/v1/projects/{$project->id}/export")->assertOk()->streamedContent()
        );

        $csv = $arquivos['calendario.csv'];

        $this->assertStringContainsString('Data,Hora,Canal,Formato,Pilar,Titulo,Status,"Versao aprovada","Hash aprovado"', $csv);
        $this->assertStringContainsString('03/08/2026', $csv);
        $this->assertStringContainsString('instagram', $csv);
        $this->assertStringContainsString('Agendado', $csv);

        // Sem BOM o Excel abre "transparência" como "transparÃªncia".
        $this->assertStringStartsWith("\u{FEFF}", $csv);
    }

    /**
     * O CSV e o calendario que o CLIENTE abre: a hora dele manda publicar. O banco
     * guarda UTC (config/database.php fixa a conexao pgsql em UTC), entao formatar
     * sem converter entrega o horario errado — e o `.md` da MESMA peca, que converte,
     * discordaria do CSV dentro do mesmo zip.
     *
     * O cenario cruza a meia-noite de proposito: 00:30Z e 21:30 do DIA ANTERIOR em
     * Sao Paulo. Assim a hora e a data divergem, e o nome do arquivo (que comeca pela
     * data justamente para a ordem alfabetica virar a ordem do calendario) tambem
     * precisa estar no fuso do projeto.
     */
    public function test_o_calendario_usa_o_fuso_do_projeto_e_nao_utc(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'timezone' => 'America/Sao_Paulo',
        ]);

        $this->peca($project, 'scheduled', ['scheduled_for' => '2026-08-04T00:30:00Z']);

        Sanctum::actingAs($editor);

        $arquivos = $this->abrir(
            $this->get("/api/v1/projects/{$project->id}/export")->assertOk()->streamedContent()
        );

        $csv = $arquivos['calendario.csv'];

        $this->assertStringContainsString('03/08/2026,21:30', $csv, 'O CSV entregou o horario em UTC.');
        $this->assertStringNotContainsString('04/08/2026', $csv);
        $this->assertStringNotContainsString('00:30', $csv);

        // O `.md` ja convertia. O zip nao pode dizer duas coisas sobre a mesma peca.
        $md = collect($arquivos)->first(fn ($_, $nome) => str_starts_with($nome, 'pecas/'));
        $this->assertStringContainsString('03/08/2026 às 21:30', $md);

        $nome = collect($arquivos)->keys()->first(fn ($n) => str_starts_with($n, 'pecas/'));
        $this->assertStringStartsWith('pecas/01-2026-08-03-', $nome, 'O nome do arquivo saiu na data UTC.');
    }

    // ---------------------------------------------- CP-05A P0-01: aprovacao valida

    private function exportar(Project $project, WorkspaceRole $papel = WorkspaceRole::Editor): array
    {
        Sanctum::actingAs($this->membro($project->workspace, $papel));

        return $this->abrir($this->get("/api/v1/projects/{$project->id}/export")->assertOk()->streamedContent());
    }

    private function projeto(): Project
    {
        return Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    }

    /** O CSV leva a versao e o hash da decisao vigente — os mesmos do banco. */
    public function test_peca_com_aprovacao_valida_sai_com_versao_e_hash(): void
    {
        $project = $this->projeto();
        $peca = $this->peca($project, 'approved', ['title' => 'Aprovada de verdade']);
        $decisao = ContentDecision::where('content_id', $peca->id)->sole();

        $arquivos = $this->exportar($project);

        $this->assertCount(2, $arquivos);
        $this->assertStringContainsString('Aprovada de verdade', $arquivos['calendario.csv']);
        $this->assertStringContainsString(",{$decisao->version},{$decisao->snapshot_hash}", $arquivos['calendario.csv']);
        $this->assertSame((int) $peca->version, (int) $decisao->version);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $decisao->snapshot_hash);
    }

    /** O achado: `approved` no banco, sem decisao humana (peca pre-CP-04). */
    public function test_status_approved_sem_decisao_valida_fica_de_fora(): void
    {
        $project = $this->projeto();
        $this->peca($project, 'approved', ['title' => 'Vai no zip']);
        $this->legada($project, ['title' => 'Legada sem decisao']);

        $tudo = implode("\n", $this->exportar($project));

        $this->assertStringContainsString('Vai no zip', $tudo);
        $this->assertStringNotContainsString('Legada sem decisao', $tudo);
    }

    /** O conteudo mudou por fora do fluxo (sem subir a versao): o hash nao bate mais. */
    public function test_hash_divergente_fica_de_fora(): void
    {
        $project = $this->projeto();
        $this->peca($project, 'approved', ['title' => 'Intacta']);
        $mexida = $this->peca($project, 'approved', ['title' => 'Mexida por fora']);

        DB::table('contents')->where('id', $mexida->id)->update(['caption' => 'Legenda trocada sem passar pelo fluxo.']);

        $tudo = implode("\n", $this->exportar($project));

        $this->assertStringContainsString('Intacta', $tudo);
        $this->assertStringNotContainsString('Mexida por fora', $tudo);
        $this->assertStringNotContainsString('Legenda trocada', $tudo);
    }

    /** A decisao e de uma versao que ja nao e a atual. */
    public function test_aprovacao_de_versao_antiga_fica_de_fora(): void
    {
        $project = $this->projeto();
        $this->peca($project, 'approved', ['title' => 'Versao atual aprovada']);
        $antiga = $this->peca($project, 'approved', ['title' => 'Aprovada na v anterior']);

        DB::table('contents')->where('id', $antiga->id)->update(['version' => $antiga->version + 1]);

        $tudo = implode("\n", $this->exportar($project));

        $this->assertStringContainsString('Versao atual aprovada', $tudo);
        $this->assertStringNotContainsString('Aprovada na v anterior', $tudo);
    }

    /** Pelo caminho normal: editar depois de aprovar derruba a aprovacao e o zip. */
    public function test_conteudo_alterado_apos_aprovacao_fica_de_fora(): void
    {
        $project = $this->projeto();
        $this->peca($project, 'approved', ['title' => 'Continua aprovada']);
        $editada = $this->peca($project, 'approved', ['title' => 'Editada depois']);

        $editada->update(['caption' => 'Mudei depois da aprovação.']);
        $this->assertSame('review', $editada->fresh()->status);

        $tudo = implode("\n", $this->exportar($project));

        $this->assertStringContainsString('Continua aprovada', $tudo);
        $this->assertStringNotContainsString('Editada depois', $tudo);
    }

    /** Agendada continua `scheduled`, mas com a aprovacao vencida nao sai. */
    public function test_agendada_com_aprovacao_vencida_fica_de_fora(): void
    {
        $project = $this->projeto();
        $this->peca($project, 'scheduled', ['title' => 'Agendada valida', 'scheduled_for' => '2026-08-03T13:00:00Z']);
        $vencida = $this->peca($project, 'scheduled', ['title' => 'Agendada vencida', 'scheduled_for' => '2026-08-04T13:00:00Z']);

        DB::table('contents')->where('id', $vencida->id)->update(['hashtags' => json_encode(['#trocada'])]);
        $this->assertSame('scheduled', $vencida->fresh()->status);

        $tudo = implode("\n", $this->exportar($project));

        $this->assertStringContainsString('Agendada valida', $tudo);
        $this->assertStringNotContainsString('Agendada vencida', $tudo);
        $this->assertStringNotContainsString('#trocada', $tudo);
    }

    /** So pecas aprovadas de forma invalida: nada a exportar (422), nao um zip vazio. */
    public function test_so_aprovacoes_invalidas_e_exportacao_vazia(): void
    {
        $project = $this->projeto();
        $this->legada($project);
        $agendada = $this->peca($project, 'scheduled');
        DB::table('contents')->where('id', $agendada->id)->update(['title' => 'Trocado por fora']);

        Sanctum::actingAs($this->membro($project->workspace, WorkspaceRole::Editor));

        $this->get("/api/v1/projects/{$project->id}/export")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não há peça aprovada ou agendada para exportar.');
    }

    public function test_sem_peca_pronta_nao_ha_o_que_exportar(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->peca($project, 'idea');

        Sanctum::actingAs($editor);

        $this->get("/api/v1/projects/{$project->id}/export")->assertStatus(422);
    }

    /** Exportar e LER: o cliente da agencia, que so acompanha, leva o conteudo dele. */
    public function test_viewer_pode_exportar(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->membro($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->peca($project, 'approved');

        Sanctum::actingAs($viewer);

        $this->get("/api/v1/projects/{$project->id}/export")->assertOk();
    }

    public function test_projeto_de_outro_workspace_nao_existe(): void
    {
        $meu = Workspace::factory()->create();
        $alheio = Workspace::factory()->create();
        $intruso = $this->membro($meu, WorkspaceRole::Owner);
        $alvo = Project::factory()->create(['workspace_id' => $alheio->id]);

        $this->peca($alvo, 'approved');

        Sanctum::actingAs($intruso);

        $this->get("/api/v1/projects/{$alvo->id}/export")->assertNotFound();
    }

    public function test_sem_autenticacao_nao_passa(): void
    {
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/export")->assertUnauthorized();
    }
}
