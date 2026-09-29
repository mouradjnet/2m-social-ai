<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CP-03 de ponta a ponta, no mock: um plano com os quatro formatos editoriais vira
 * quatro pecas, cada uma com o ROTEIRO do seu formato, gravadas e devolvidas pela
 * listagem (o que a tela le ao recarregar). Nada e publicado.
 */
class EditorialFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->editor = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $this->editor->id,
            'role' => WorkspaceRole::Editor, 'joined_at' => now(),
        ]);
        Sanctum::actingAs($this->editor);
    }

    private function planoComOsQuatroFormatos(Project $project): ContentPlan
    {
        $estrategia = Strategy::create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'title' => 'E',
            'pillars' => [['name' => 'Autocuidado', 'weight' => 100, 'description' => 'd']],
            'status' => 'active',
        ]);
        $dia = CarbonImmutable::now('America/Sao_Paulo')->addDay()->toDateString();
        $slot = fn (string $formato, string $hora) => [
            'date' => $dia, 'time' => $hora, 'pillar' => 'Autocuidado', 'format' => $formato,
            'channel' => 'instagram', 'theme' => "Autocuidado em {$formato}", 'objective' => 'Educar',
            'cta' => 'Salve para consultar depois', 'rationale' => 'r',
        ];

        return ContentPlan::create([
            'strategy_id' => $estrategia->id, 'period_start' => $dia, 'period_end' => $dia, 'posts_count' => 4,
            'distribution' => ['summary' => 's', 'slots' => [
                $slot('post', '09:00'), $slot('story', '12:00'), $slot('reel', '18:00'), $slot('carousel', '20:00'),
            ]],
        ]);
    }

    public function test_os_quatro_formatos_saem_com_o_roteiro_certo_e_sobrevivem_a_recarga(): void
    {
        $plano = $this->planoComOsQuatroFormatos($this->project);

        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(202);
        $this->assertSame('succeeded', AiRun::where('agent', 'copywriter')->sole()->status);

        // "Recarregar a pagina": o que a tela recebe da listagem.
        $pecas = collect($this->getJson("/api/v1/projects/{$this->project->id}/contents")->assertOk()->json('data'))
            ->keyBy('format');

        $this->assertEqualsCanonicalizing(['post', 'story', 'reel', 'carousel'], $pecas->keys()->all());

        $this->assertNotEmpty($pecas['post']['structure']['visual']);
        $this->assertGreaterThanOrEqual(2, count($pecas['story']['structure']['screens']));
        $this->assertNotEmpty($pecas['reel']['structure']['hook']);
        $this->assertGreaterThanOrEqual(2, count($pecas['reel']['structure']['scenes']));
        $this->assertGreaterThanOrEqual(3, count($pecas['carousel']['structure']['slides']));

        foreach ($pecas as $peca) {
            // Roteiro, nao midia: nenhuma peca nasce com imagem ou video.
            $this->assertNull($peca['image_asset_id']);
            $this->assertNull($peca['video_asset_id']);
            $this->assertSame('idea', $peca['status']);
            $this->assertSame('draft', $peca['editorial_state']);
            $this->assertSame('Salve para consultar depois', $peca['cta']);
        }

        // Gerar conteudo nunca publica nem agenda.
        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
        $this->assertSame(0, Content::whereNotNull('scheduled_for')->count());
    }

    /** Isolamento: as pecas de uma marca nao aparecem na listagem da outra. */
    public function test_pecas_de_uma_marca_nao_aparecem_na_outra(): void
    {
        $outra = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $plano = $this->planoComOsQuatroFormatos($this->project);

        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(202);

        $this->assertCount(4, $this->getJson("/api/v1/projects/{$this->project->id}/contents")->json('data'));
        $this->assertCount(0, $this->getJson("/api/v1/projects/{$outra->id}/contents")->json('data'));
    }

    /** Falha da IA: nada pela metade fica gravado, e a pessoa ve a mensagem. */
    public function test_falha_da_ia_nao_grava_peca_pela_metade(): void
    {
        config(['ai.max_input_tokens' => 10]);
        $plano = $this->planoComOsQuatroFormatos($this->project);

        $this->postJson("/api/v1/projects/{$this->project->id}/copy:generate", ['content_plan_id' => $plano->id])
            ->assertStatus(202);

        $run = AiRun::where('agent', 'copywriter')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($run->error);
        $this->assertSame(0, Content::count());
    }
}
