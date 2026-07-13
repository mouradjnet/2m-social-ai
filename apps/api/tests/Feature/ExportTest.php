<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Content;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    private function peca(Project $project, string $status, array $extra = []): Content
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

        $this->assertStringContainsString('Data,Hora,Canal,Formato,Pilar,Titulo,Status', $csv);
        $this->assertStringContainsString('03/08/2026', $csv);
        $this->assertStringContainsString('instagram', $csv);
        $this->assertStringContainsString('Agendado', $csv);

        // Sem BOM o Excel abre "transparência" como "transparÃªncia".
        $this->assertStringStartsWith("\u{FEFF}", $csv);
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
