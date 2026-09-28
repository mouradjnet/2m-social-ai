<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
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

    /** @return array{Workspace, Project} */
    private function scene(WorkspaceRole $role = WorkspaceRole::Editor): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        Sanctum::actingAs($this->memberOf($workspace, $role));

        return [$workspace, $project];
    }

    private function upload(Project $project, UploadedFile $file): TestResponse
    {
        return $this->post("/api/v1/projects/{$project->id}/assets", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function content(Project $project, string $status, ?int $assetId = null): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Peca',
            'caption' => 'Legenda',
            'hashtags' => [],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'image_asset_id' => $assetId,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_sobe_um_jpeg_e_devolve_a_url_publica(): void
    {
        [, $project] = $this->scene();

        $response = $this->upload($project, UploadedFile::fake()->image('capa.jpg', 1080, 1080))
            ->assertCreated()
            ->assertJsonPath('data.width', 1080)
            ->assertJsonPath('data.height', 1080)
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.original_name', 'capa.jpg')
            ->assertJsonMissingPath('data.path');

        $asset = Asset::first();
        Storage::disk('public')->assertExists($asset->path);
        $this->assertStringEndsWith('.jpg', $asset->path);
        $this->assertStringContainsString('/storage/media/', $response->json('data.url'));
    }

    public function test_png_vira_jpeg(): void
    {
        [, $project] = $this->scene();

        $this->upload($project, UploadedFile::fake()->image('logo.png', 800, 800))->assertCreated();

        $bytes = Storage::disk('public')->get(Asset::first()->path);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($bytes)[2]);
    }

    public function test_largura_acima_do_limite_e_reduzida_mantendo_a_proporcao(): void
    {
        [, $project] = $this->scene();

        $this->upload($project, UploadedFile::fake()->image('grande.jpg', 2160, 2700))
            ->assertCreated()
            ->assertJsonPath('data.width', 1440)
            ->assertJsonPath('data.height', 1800);
    }

    public function test_imagem_estreita_demais_e_recusada(): void
    {
        [, $project] = $this->scene();

        $this->upload($project, UploadedFile::fake()->image('mini.jpg', 200, 200))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'A imagem tem 200 px de largura; o Instagram exige pelo menos 320.');

        $this->assertSame(0, Asset::count());
    }

    public function test_proporcao_fora_do_instagram_e_recusada(): void
    {
        [, $project] = $this->scene();

        $this->upload($project, UploadedFile::fake()->image('faixa.jpg', 1500, 500))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_arquivo_que_nao_e_imagem_e_recusado(): void
    {
        [, $project] = $this->scene();

        $this->upload($project, UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Envie JPEG, PNG ou WebP.');
    }

    public function test_a_mesma_imagem_duas_vezes_nao_duplica(): void
    {
        [, $project] = $this->scene();
        $file = UploadedFile::fake()->image('capa.jpg', 1080, 1080);

        $id = $this->upload($project, $file)->assertCreated()->json('data.id');
        $this->upload($project, $file)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, Asset::count());
    }

    public function test_lista_so_as_imagens_do_projeto(): void
    {
        [$workspace, $project] = $this->scene();
        $outro = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->upload($project, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertCreated();
        $this->upload($outro, UploadedFile::fake()->image('b.jpg', 1080, 1350))->assertCreated();

        $this->getJson("/api/v1/projects/{$project->id}/assets")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'a.jpg');
    }

    public function test_viewer_nao_sobe(): void
    {
        [, $project] = $this->scene(WorkspaceRole::Viewer);

        $this->upload($project, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertForbidden();
    }

    public function test_remove_a_imagem_e_o_arquivo(): void
    {
        [, $project] = $this->scene();
        $this->upload($project, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertCreated();
        $asset = Asset::first();
        $rascunho = $this->content($project, 'production', $asset->id);

        $this->deleteJson("/api/v1/assets/{$asset->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($asset->path);
        $this->assertNull($rascunho->fresh()->image_asset_id);
    }

    public function test_nao_remove_imagem_de_peca_aprovada(): void
    {
        [, $project] = $this->scene();
        $this->upload($project, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertCreated();
        $asset = Asset::first();
        $this->content($project, 'scheduled', $asset->id);

        $this->deleteJson("/api/v1/assets/{$asset->id}")->assertStatus(409);

        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_imagem_de_outro_workspace_nao_existe(): void
    {
        $theirs = Workspace::factory()->create();
        $alheio = Project::factory()->create(['workspace_id' => $theirs->id]);
        $asset = Asset::create([
            'workspace_id' => $theirs->id, 'project_id' => $alheio->id, 'type' => 'image',
            'disk' => 'public', 'path' => 'x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1,
            'checksum' => str_repeat('a', 64), 'created_by' => User::factory()->create()->id,
        ]);
        $this->scene(WorkspaceRole::Owner);

        $this->deleteJson("/api/v1/assets/{$asset->id}")->assertNotFound();
        $this->getJson("/api/v1/projects/{$alheio->id}/assets")->assertNotFound();
    }

    public function test_associa_a_imagem_a_peca_e_grava_a_revisao(): void
    {
        [, $project] = $this->scene();
        $this->upload($project, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertCreated();
        $asset = Asset::first();
        $content = $this->content($project, 'production');

        $this->putJson("/api/v1/contents/{$content->id}/image", ['asset_id' => $asset->id])
            ->assertOk()
            ->assertJsonPath('data.image.id', $asset->id)
            ->assertJsonPath('data.image.url', $asset->url);

        $revisao = ContentRevision::where('content_id', $content->id)->latest('id')->first();
        $this->assertEquals(['from' => null, 'to' => $asset->id], $revisao->changes['image_asset_id']);

        $this->putJson("/api/v1/contents/{$content->id}/image", ['asset_id' => null])->assertOk();
        $this->assertNull($content->fresh()->image_asset_id);
    }

    public function test_peca_aprovada_nao_troca_a_imagem(): void
    {
        [, $project] = $this->scene();
        $this->upload($project, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertCreated();
        $content = $this->content($project, 'approved');

        $this->putJson("/api/v1/contents/{$content->id}/image", ['asset_id' => Asset::first()->id])
            ->assertStatus(422);
    }

    public function test_imagem_de_outro_projeto_nao_entra_na_peca(): void
    {
        [$workspace, $project] = $this->scene();
        $outro = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->upload($outro, UploadedFile::fake()->image('a.jpg', 1080, 1080))->assertCreated();
        $content = $this->content($project, 'idea');

        $this->putJson("/api/v1/contents/{$content->id}/image", ['asset_id' => Asset::first()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_id');
    }

    public function test_viewer_nao_troca_a_imagem_e_outro_tenant_nao_existe(): void
    {
        [$workspace, $project] = $this->scene(WorkspaceRole::Viewer);
        $content = $this->content($project, 'idea');

        $this->putJson("/api/v1/contents/{$content->id}/image", ['asset_id' => null])->assertForbidden();

        $alheio = $this->content(Project::factory()->create(), 'idea');
        $this->putJson("/api/v1/contents/{$alheio->id}/image", ['asset_id' => null])->assertNotFound();
    }
}
