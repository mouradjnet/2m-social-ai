<?php

namespace Tests\Feature;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\RepurposerAgent;
use App\Ai\Exceptions\OutputRejectedException;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Roteiro;
use Tests\TestCase;

/** Etapa 2d: uma peca vira outra, em outro formato ou canal. A original nao muda. */
class RepurposeTest extends TestCase
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
        $this->editor = $this->memberOf(WorkspaceRole::Editor);
        Sanctum::actingAs($this->editor);
    }

    private function memberOf(WorkspaceRole $role, ?Workspace $workspace = null): User
    {
        $user = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function peca(array $attrs = []): Content
    {
        return Content::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Papanicolau: quando fazer',
            'caption' => 'O exame preventivo e indicado a partir dos 25 anos.',
            'cta' => 'Converse com sua ginecologista.',
            'hashtags' => ['#saude'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => 'Prevenção e exames',
            'status' => 'published',
            'created_by' => $this->editor->id,
            ...$attrs,
        ]);
    }

    private function reaproveitar(Content $content, array $body = ['format' => 'carousel', 'channel' => 'instagram']): TestResponse
    {
        return $this->postJson("/api/v1/contents/{$content->id}/repurpose:generate", $body);
    }

    public function test_cria_uma_peca_nova_ligada_a_original_e_a_original_nao_muda(): void
    {
        $original = $this->peca();
        $antes = $original->only(['title', 'caption', 'format', 'status']);

        $id = $this->reaproveitar($original)->assertStatus(202)->json('ai_run_id');

        $this->assertSame('succeeded', AiRun::find($id)->status);
        $nova = Content::where('repurposed_from_id', $original->id)->sole();
        $this->assertSame('carousel', $nova->format);
        $this->assertSame('instagram', $nova->channel);
        $this->assertSame('Prevenção e exames', $nova->pillar);
        // Nasce ideia: passa por revisao e aprovacao humana antes de qualquer agenda.
        $this->assertSame('idea', $nova->status);
        $this->assertSame('ai', $nova->source);
        $this->assertNotNull($nova->summary);
        $this->assertSame($antes, $original->fresh()->only(['title', 'caption', 'format', 'status']));
    }

    public function test_mesmo_formato_e_canal_ou_peca_sem_texto_e_recusado(): void
    {
        $this->reaproveitar($this->peca(), ['format' => 'post', 'channel' => 'instagram'])->assertStatus(422);
        $this->reaproveitar($this->peca(['caption' => null]))->assertStatus(422);
        $this->reaproveitar($this->peca(), ['format' => 'podcast', 'channel' => 'instagram'])->assertStatus(422);
        $this->assertSame(0, AiRun::count());
    }

    public function test_reaproveitamento_em_andamento_devolve_409(): void
    {
        AiRun::create([
            'workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'agent' => 'repurposer', 'provider' => 'mock', 'model' => 'x', 'status' => 'queued',
            'input' => [], 'created_by' => $this->editor->id,
        ]);

        $this->reaproveitar($this->peca())->assertStatus(409);
    }

    public function test_viewer_nao_reaproveita_outro_tenant_nao_existe_e_sem_token_401(): void
    {
        $content = $this->peca();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Viewer));
        $this->reaproveitar($content)->assertForbidden();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Owner, Workspace::factory()->create()));
        $this->reaproveitar($content)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/contents/{$content->id}/repurpose:generate", ['format' => 'reel', 'channel' => 'instagram'])->assertUnauthorized();

        $this->assertSame(0, AiRun::count());
    }

    public function test_validate_recusa_copia_da_original_e_titulo_repetido(): void
    {
        $ctx = new AgentContext(
            projectId: 1,
            projectName: 'x',
            segment: null,
            brandProfile: [],
            existingContents: [['title' => 'Já existe', 'pillar' => 'A']],
            repurpose: [
                'source' => ['title' => 'Original', 'caption' => 'Texto original.'],
                'target' => ['format' => 'carousel', 'channel' => 'instagram'],
            ],
        );
        $saida = fn (array $extra) => ['title' => 'Nova', 'caption' => 'Slide 1: outro texto.', 'cta' => 'x', 'hashtags' => [], 'adaptation_notes' => 'n', 'structure' => Roteiro::valido(), ...$extra];
        $agent = new RepurposerAgent;

        $agent->validate($saida([]), $ctx);

        foreach ([['caption' => 'Texto original.'], ['title' => 'original'], ['title' => 'Já existe']] as $ruim) {
            try {
                $agent->validate($saida($ruim), $ctx);
                $this->fail('Devia recusar: '.json_encode($ruim));
            } catch (OutputRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
