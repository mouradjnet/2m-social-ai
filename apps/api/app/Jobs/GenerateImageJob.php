<?php

namespace App\Jobs;

use App\Ai\Images\ImageGenerationException;
use App\Ai\Images\ImageProvider;
use App\Domain\Media\ImageProcessor;
use App\Domain\Media\InvalidImageException;
use App\Models\AiRun;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentRevision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Desenha a imagem de UMA peca a partir do `image_prompt` e a poe na peca (ADR-15).
 *
 * A imagem entra na biblioteca pelo mesmo ImageProcessor do upload: sai JPEG, na
 * proporcao do Instagram, sem metadado. E so e anexada se a peca ainda nao foi
 * aprovada — a IA propoe, o humano aprova (ADR-13); uma imagem trocada depois da
 * aprovacao seria publicar o que ninguem viu.
 */
class GenerateImageJob implements ShouldQueue
{
    use Queueable;

    private const EDITAVEIS = ['idea', 'production', 'review'];

    public function __construct(private readonly int $aiRunId) {}

    public function handle(ImageProvider $provider): void
    {
        $run = AiRun::withoutGlobalScopes()->findOrFail($this->aiRunId);
        $content = Content::withoutGlobalScopes()->findOrFail($run->input['content_id']);

        $run->update(['status' => 'running']);
        $inicio = microtime(true);
        $custo = 0;

        try {
            $imagem = $provider->generate((string) $content->image_prompt);
            $custo = $imagem->costCents;
            $asset = $this->guardar($content, $run, $imagem->bytes);
        } catch (Throwable $e) {
            Log::error('Falha na geracao de imagem.', [
                'ai_run_id' => $run->id,
                'provider' => $run->provider,
                'exception' => $e,
            ]);

            $run->update([
                'status' => 'failed',
                'error' => $this->mensagem($e),
                'error_code' => match (true) {
                    $e instanceof ImageGenerationException && $e->kind === 'refused' => 'refused',
                    $e instanceof InvalidImageException => 'rejected_output',
                    default => 'provider_failed',
                },
                // Uma imagem recusada pela biblioteca ja foi paga ao provedor.
                'cost_cents' => $custo,
                'latency_ms' => $this->decorrido($inicio),
            ]);

            return;
        }

        DB::transaction(function () use ($content, $run, $asset, $custo, $inicio) {
            // Rele a peca: durante a geracao alguem pode ter aprovado.
            $content->refresh();
            $anexada = in_array($content->status, self::EDITAVEIS, true);

            if ($anexada && $content->image_asset_id !== $asset->id) {
                $de = $content->image_asset_id;
                $content->update(['image_asset_id' => $asset->id]);
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $run->created_by,
                    'changes' => ['image_asset_id' => ['from' => $de, 'to' => $asset->id], 'source' => 'ai'],
                ]);
            }

            $run->update([
                'status' => 'succeeded',
                'output' => ['asset_id' => $asset->id, 'content_id' => $content->id, 'attached' => $anexada],
                'cost_cents' => $custo,
                'latency_ms' => $this->decorrido($inicio),
            ]);
        });
    }

    /** Mesmo caminho do upload (AssetController::store): processar, deduplicar, guardar. */
    private function guardar(Content $content, AiRun $run, string $bytes): Asset
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');

        try {
            file_put_contents($tmp, $bytes);
            $imagem = ImageProcessor::toInstagramJpeg($tmp);
        } finally {
            @unlink($tmp);
        }

        $checksum = hash('sha256', $imagem['bytes']);
        $existente = Asset::withoutGlobalScopes()
            ->where('project_id', $content->project_id)
            ->where('checksum', $checksum)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $disk = config('media.disk');
        $path = "media/{$content->workspace_id}/{$content->project_id}/".Str::uuid().'.jpg';
        Storage::disk($disk)->put($path, $imagem['bytes'], 'public');

        return Asset::create([
            'workspace_id' => $content->workspace_id,
            'project_id' => $content->project_id,
            'type' => 'image',
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit("ia-{$content->id}-".Str::slug($content->title), 240, '').'.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => strlen($imagem['bytes']),
            'width' => $imagem['width'],
            'height' => $imagem['height'],
            'checksum' => $checksum,
            'created_by' => $run->created_by,
        ]);
    }

    /** O job morreu fora do handle(): a execucao nao pode ficar `running` (409 eterno). */
    public function failed(?Throwable $e): void
    {
        AiRun::withoutGlobalScopes()
            ->whereKey($this->aiRunId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => 'A geração foi interrompida. Tente novamente.',
                'error_code' => 'provider_failed',
            ]);
    }

    private function mensagem(Throwable $e): string
    {
        return match (true) {
            $e instanceof ImageGenerationException => $e->getMessage(),
            $e instanceof InvalidImageException => 'A imagem gerada não serve para o Instagram: '.$e->getMessage(),
            default => 'Falha ao gerar a imagem. Tente novamente em alguns instantes.',
        };
    }

    private function decorrido(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }
}
