<?php

namespace Tests\Feature;

use App\Domain\Media\Mp4Inspector;
use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\Content;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeMp4;
use Tests\TestCase;

/**
 * Etapa 3: o video do Reel entra na biblioteca conferido contra as regras da Meta
 * (IG User Media, consultada em 29/09/2026) — no upload, nao na hora de publicar.
 */
class VideoAssetTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $user = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Editor, 'joined_at' => now()]);
        Sanctum::actingAs($user);
    }

    private function subir(string $bytes, string $nome = 'reel.mp4'): TestResponse
    {
        return $this->post(
            "/api/v1/projects/{$this->project->id}/assets",
            ['file' => UploadedFile::fake()->createWithContent($nome, $bytes)],
            ['Accept' => 'application/json'],
        );
    }

    public function test_inspector_le_duracao_dimensoes_e_codecs(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mp4');
        file_put_contents($tmp, FakeMp4::bytes(durationMs: 42_500, width: 1080, height: 1920));

        $info = Mp4Inspector::inspect($tmp);
        unlink($tmp);

        $this->assertSame(42_500, $info['duration_ms']);
        $this->assertSame([1080, 1920], [$info['width'], $info['height']]);
        $this->assertSame('avc1', $info['video_codec']);
        $this->assertSame('mp4a', $info['audio_codec']);
        $this->assertTrue($info['moov_before_mdat']);
        $this->assertFalse($info['edit_list']);
    }

    public function test_reel_valido_entra_na_biblioteca_como_video_sem_reencodar(): void
    {
        $bytes = FakeMp4::bytes();

        $this->subir($bytes)
            ->assertCreated()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.duration_ms', 15_000)
            ->assertJsonPath('data.width', 1080);

        $asset = Asset::sole();
        $this->assertStringEndsWith('.mp4', $asset->path);
        // Guardado como veio: nao ha ffmpeg para reencodar.
        $this->assertSame($bytes, Storage::disk('public')->get($asset->path));
    }

    public function test_cada_regra_da_meta_recusa_com_o_motivo(): void
    {
        $casos = [
            'curto demais' => [FakeMp4::bytes(durationMs: 2_000), '3 segundos'],
            'longo demais' => [FakeMp4::bytes(durationMs: 16 * 60 * 1000), '15 minutos'],
            'largo demais' => [FakeMp4::bytes(width: 3840, height: 2160), '1920'],
            'codec de video' => [FakeMp4::bytes(videoCodec: 'vp09'), 'H.264'],
            'codec de audio' => [FakeMp4::bytes(audioCodec: 'Opus'), 'AAC'],
            'moov no fim' => [FakeMp4::bytes(fastStart: false), 'fast start'],
            'edit list' => [FakeMp4::bytes(editList: true), 'edit list'],
        ];

        foreach ($casos as $caso => [$bytes, $motivo]) {
            $this->subir($bytes)
                ->assertStatus(422)
                ->assertJsonPath('message', fn ($m) => str_contains($m, $motivo) ?: $this->fail("{$caso}: {$m}"));
        }

        $this->assertSame(0, Asset::count());
    }

    public function test_video_sem_audio_e_aceito(): void
    {
        $this->subir(FakeMp4::bytes(audioCodec: null))->assertCreated();
    }

    public function test_video_nao_vira_imagem_de_post(): void
    {
        $this->subir(FakeMp4::bytes())->assertCreated();
        $video = Asset::sole();
        $peca = Content::create([
            'workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id,
            'title' => 'x', 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram',
            'status' => 'production', 'created_by' => User::factory()->create()->id,
        ]);

        $this->putJson("/api/v1/contents/{$peca->id}/image", ['expected_version' => $peca->fresh()->version, 'asset_id' => $video->id])->assertStatus(422);
    }

    public function test_imagem_acima_de_8_mb_continua_recusada(): void
    {
        $this->post(
            "/api/v1/projects/{$this->project->id}/assets",
            ['file' => UploadedFile::fake()->image('grande.jpg', 1080, 1080)->size(9 * 1024)],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '8 MB'));
    }

    /** Sem isto, o PHP da imagem Docker recusa todo upload acima de 2 MB (padrao). */
    public function test_limites_do_php_na_imagem_cobrem_os_da_biblioteca(): void
    {
        $ini = parse_ini_file(base_path('../../docker/php-uploads.ini'));
        $bytes = fn (string $v) => (int) $v * match (strtoupper(substr($v, -1))) {
            'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1
        };

        $this->assertGreaterThan(config('media.video.max_bytes'), $bytes($ini['upload_max_filesize']));
        $this->assertGreaterThan($bytes($ini['upload_max_filesize']), $bytes($ini['post_max_size']));
        $this->assertStringContainsString('php-uploads.ini', (string) file_get_contents(base_path('../../Dockerfile')));
    }
}
