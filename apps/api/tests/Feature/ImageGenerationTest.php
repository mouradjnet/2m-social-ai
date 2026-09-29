<?php

namespace Tests\Feature;

use App\Ai\Budget;
use App\Ai\Images\GeneratedImage;
use App\Ai\Images\ImageProvider;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-15: a imagem da peca desenhada por IA, pelo provedor configurado. O `fake` e
 * o padrao; o `openai` e exercitado com Http::fake — nenhum teste chama a API.
 */
class ImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

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

    private function peca(string $status = 'production', ?string $prompt = 'A calm flat illustration of a calendar, soft green palette, no text'): Content
    {
        return Content::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Ciclo menstrual sem mitos',
            'caption' => 'Legenda',
            'hashtags' => [],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'image_prompt' => $prompt,
            'created_by' => $this->editor->id,
        ]);
    }

    private function gerar(Content $content): TestResponse
    {
        return $this->postJson("/api/v1/contents/{$content->id}/image:generate");
    }

    private function png(int $w = 1024, int $h = 1024): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function openai(array $resposta, int $status = 200): void
    {
        config([
            'images.provider' => 'openai',
            'images.openai.api_key' => 'sk-teste-secreta',
            'images.cost_cents_per_image' => 5,
        ]);
        Http::fake(['api.openai.com/*' => Http::response($resposta, $status)]);
    }

    public function test_fake_desenha_guarda_na_biblioteca_e_poe_na_peca(): void
    {
        $content = $this->peca();

        $id = $this->gerar($content)->assertStatus(202)->json('ai_run_id');

        $run = AiRun::find($id);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('image', $run->agent);
        $this->assertSame('fake', $run->provider);
        $this->assertSame(0, $run->cost_cents);
        $this->assertTrue($run->output['attached']);

        $asset = Asset::sole();
        $this->assertSame('image/jpeg', $asset->mime);
        $this->assertSame(1080, $asset->width);
        $this->assertSame($asset->id, $content->fresh()->image_asset_id);
        Storage::disk('public')->assertExists($asset->path);

        // A troca de imagem fica na historia da peca, com a origem.
        $rev = ContentRevision::where('content_id', $content->id)->sole();
        $this->assertEquals(['from' => null, 'to' => $asset->id], $rev->changes['image_asset_id']);
        $this->assertSame('ai', $rev->changes['source']);
        $this->assertSame($this->editor->id, $rev->user_id);
    }

    public function test_openai_manda_o_prompt_e_cobra_a_imagem_no_orcamento(): void
    {
        $this->openai(['data' => [['b64_json' => base64_encode($this->png())]]]);
        $content = $this->peca();

        $id = $this->gerar($content)->assertStatus(202)->json('ai_run_id');

        $run = AiRun::find($id);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('openai', $run->provider);
        $this->assertSame('gpt-image-1', $run->model);
        $this->assertSame(5, $run->cost_cents);
        $this->assertSame(5, Budget::spentCentsThisMonth($this->workspace));
        $this->assertNotNull($content->fresh()->image_asset_id);

        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/images/generations'
            && $r->hasHeader('Authorization', 'Bearer sk-teste-secreta')
            && $r['prompt'] === $content->image_prompt
            && $r['size'] === '1024x1024'
            && $r['n'] === 1);
    }

    public function test_prompt_recusado_pelo_filtro_do_provedor_falha_como_recusa(): void
    {
        $this->openai(['error' => ['code' => 'moderation_blocked', 'message' => 'blocked']], 400);
        $content = $this->peca();

        $run = AiRun::find($this->gerar($content)->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('refused', $run->error_code);
        $this->assertStringContainsString('Ajuste o prompt', $run->error);
        $this->assertSame(0, $run->cost_cents);
        $this->assertNull($content->fresh()->image_asset_id);
        $this->assertSame(0, Asset::count());
    }

    public function test_falha_do_provedor_nao_vaza_a_chave(): void
    {
        $this->openai(['error' => ['message' => 'server error']], 500);

        $run = AiRun::find($this->gerar($this->peca())->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_failed', $run->error_code);
        $this->assertStringNotContainsString('sk-teste-secreta', (string) $run->error);
    }

    public function test_imagem_fora_da_proporcao_do_instagram_e_recusada_mas_cobrada(): void
    {
        // 2:3 retrato: mais estreito que 4:5. A biblioteca recusa; o provedor ja cobrou.
        $this->openai(['data' => [['b64_json' => base64_encode($this->png(1024, 1536))]]]);

        $run = AiRun::find($this->gerar($this->peca())->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('rejected_output', $run->error_code);
        $this->assertSame(5, $run->cost_cents);
        $this->assertSame(0, Asset::count());
    }

    public function test_peca_aprovada_durante_a_geracao_nao_troca_de_imagem(): void
    {
        $content = $this->peca('review');

        // O provedor "demora" e, nesse meio tempo, um revisor aprova a peca.
        $this->app->instance(ImageProvider::class, new class($content) implements ImageProvider
        {
            public function __construct(private readonly Content $content) {}

            public function generate(string $prompt): GeneratedImage
            {
                $this->content->update(['status' => 'approved']);
                $img = imagecreatetruecolor(1080, 1080);
                ob_start();
                imagepng($img);

                return new GeneratedImage((string) ob_get_clean(), 0);
            }

            public function name(): string
            {
                return 'fake';
            }

            public function model(): string
            {
                return 'fake';
            }
        });

        $run = AiRun::find($this->gerar($content)->json('ai_run_id'));

        $this->assertSame('succeeded', $run->status);
        $this->assertFalse($run->output['attached']);
        $this->assertNull($content->fresh()->image_asset_id);
        // A imagem nao se perde: fica na biblioteca para quem quiser usar.
        $this->assertSame(1, Asset::count());
    }

    public function test_sem_prompt_ou_peca_aprovada_e_recusado(): void
    {
        $this->gerar($this->peca('production', null))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'prompt'));
        $this->gerar($this->peca('approved'))->assertStatus(422);
        $this->assertSame(0, AiRun::count());
    }

    public function test_geracao_em_andamento_devolve_409(): void
    {
        AiRun::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'agent' => 'image',
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'running',
            'input' => [],
            'created_by' => $this->editor->id,
        ]);

        $this->gerar($this->peca())->assertStatus(409);
    }

    public function test_orcamento_esgotado_devolve_402(): void
    {
        AiRun::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'agent' => 'copywriter',
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'cost_cents' => Budget::limitCents($this->workspace),
            'created_by' => $this->editor->id,
        ]);

        $this->gerar($this->peca())->assertStatus(402);
    }

    public function test_viewer_nao_gera_outro_tenant_nao_existe_e_sem_token_401(): void
    {
        $content = $this->peca();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Viewer));
        $this->gerar($content)->assertForbidden();

        Sanctum::actingAs($this->memberOf(WorkspaceRole::Owner, Workspace::factory()->create()));
        $this->gerar($content)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/contents/{$content->id}/image:generate")->assertUnauthorized();

        $this->assertSame(0, AiRun::count());
    }
}
