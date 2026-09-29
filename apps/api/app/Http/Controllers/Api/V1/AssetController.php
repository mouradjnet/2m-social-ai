<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\ImageProcessor;
use App\Domain\Media\InvalidImageException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A biblioteca de imagens do projeto. Cada imagem ja sai daqui pronta para o
 * Instagram: JPEG, largura e proporcao que a Meta aceita, sem EXIF.
 */
class AssetController extends Controller
{
    /** Uma imagem presa a uma peca nestes estados e o que o humano aprovou. */
    private const CONGELADOS = ['approved', 'scheduled', 'published'];

    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => Asset::where('project_id', $project->id)
                ->withCount('contents')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate([
            'file' => [
                'required', 'file',
                'max:'.intdiv(config('media.max_bytes'), 1024),
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ], [
            'file.max' => 'A imagem passa de 8 MB, o limite do Instagram.',
            'file.mimetypes' => 'Envie JPEG, PNG ou WebP.',
        ]);

        $arquivo = $request->file('file');

        try {
            $imagem = ImageProcessor::toInstagramJpeg($arquivo->getRealPath());
        } catch (InvalidImageException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $checksum = hash('sha256', $imagem['bytes']);

        // A mesma foto subida duas vezes nao vira duas linhas: devolve a que ja existe.
        $existente = Asset::where('project_id', $project->id)->where('checksum', $checksum)->first();

        if ($existente !== null) {
            return response()->json(['data' => $existente->loadCount('contents')]);
        }

        $disk = config('media.disk');
        $path = "media/{$project->workspace_id}/{$project->id}/".Str::uuid().'.jpg';

        Storage::disk($disk)->put($path, $imagem['bytes'], 'public');

        $asset = Asset::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'type' => 'image',
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($arquivo->getClientOriginalName(), 250, ''),
            'mime' => 'image/jpeg',
            'size_bytes' => strlen($imagem['bytes']),
            'width' => $imagem['width'],
            'height' => $imagem['height'],
            'checksum' => $checksum,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $asset->loadCount('contents')], 201);
    }

    /**
     * Remover com seguranca: a imagem de uma peca aprovada (ou ja publicada) nao
     * some — seria trocar, por baixo, o que o humano aprovou. Presa so a rascunhos,
     * ela sai e os rascunhos ficam sem imagem (a FK anula).
     */
    public function destroy(Request $request, Asset $asset): Response|JsonResponse
    {
        Gate::authorize('update', $asset->project);

        if ($asset->contents()->whereIn('status', self::CONGELADOS)->exists()) {
            return response()->json([
                'message' => 'Esta imagem está numa peça aprovada. Troque a imagem da peça antes de remover.',
            ], 409);
        }

        // O historico diz qual imagem foi ao ar; apagar o arquivo apagaria a prova.
        if (Publication::where('asset_id', $asset->id)->exists()) {
            return response()->json([
                'message' => 'Esta imagem já foi usada numa publicação e fica no histórico.',
            ], 409);
        }

        [$disk, $path] = [$asset->disk, $asset->path];

        DB::transaction(function () use ($request, $asset) {
            $asset->delete();
            ActivityLog::record($request->user(), $asset->project, 'asset.deleted', $asset, [
                'original_name' => $asset->original_name,
            ]);
        });

        // Depois do delete: se apagar o arquivo falhar, sobra um arquivo orfao, nunca
        // uma linha apontando para o nada.
        Storage::disk($disk)->delete($path);

        return response()->noContent();
    }
}
