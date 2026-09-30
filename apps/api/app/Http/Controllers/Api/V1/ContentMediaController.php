<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * As midias do carrossel e do Reel (Etapa 3). Como a imagem (ContentImageController):
 * so antes da aprovacao, e toda troca grava a revisao com o de-para — e e essa
 * revisao que faz o PublishGate pedir nova aprovacao se algo mudar depois.
 */
class ContentMediaController extends Controller
{
    private const EDITAVEIS = ['idea', 'production', 'review'];

    public function slides(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $max = (int) config('media.carousel.max_items');
        $data = $request->validate([
            'asset_ids' => ['present', 'array', "max:{$max}"],
            'asset_ids.*' => ['integer', 'distinct'],
        ], [
            'asset_ids.max' => "O Instagram aceita até {$max} imagens por carrossel.",
            'asset_ids.*.distinct' => 'A mesma imagem entrou duas vezes.',
        ]);

        if ($resposta = $this->congelada($content)) {
            return $resposta;
        }

        $ids = array_map('intval', $data['asset_ids']);
        $validas = Asset::where('project_id', $content->project_id)->where('type', 'image')->whereIn('id', $ids)->count();

        if ($validas !== count($ids)) {
            return $this->recusa('asset_ids', 'Só imagens deste projeto entram no carrossel.');
        }

        $de = $content->slides()->pluck('assets.id')->all();

        if ($de !== $ids) {
            DB::transaction(function () use ($content, $ids, $de, $request) {
                DB::table('content_slides')->where('content_id', $content->id)->delete();
                DB::table('content_slides')->insert(array_map(
                    fn (int $id, int $i) => ['content_id' => $content->id, 'asset_id' => $id, 'position' => $i],
                    $ids, array_keys($ids),
                ));
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $request->user()->id,
                    'changes' => ['slides' => ['from' => $de, 'to' => $ids]],
                ]);
                // CP-04: os slides vao ao ar; trocar e mudar de versao.
                $content->markContentChanged();
            });
        }

        return response()->json(['data' => $content->refresh()->load('slides')]);
    }

    public function video(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate(['asset_id' => ['present', 'nullable', 'integer']]);

        if ($resposta = $this->congelada($content)) {
            return $resposta;
        }

        if ($data['asset_id'] !== null
            && ! Asset::where('project_id', $content->project_id)->where('type', 'video')->whereKey($data['asset_id'])->exists()) {
            return $this->recusa('asset_id', 'Vídeo não encontrado neste projeto.');
        }

        $de = $content->video_asset_id;

        if ($de !== $data['asset_id']) {
            DB::transaction(function () use ($content, $de, $data, $request) {
                $content->update(['video_asset_id' => $data['asset_id']]);
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $request->user()->id,
                    'changes' => ['video_asset_id' => ['from' => $de, 'to' => $data['asset_id']]],
                ]);
            });
        }

        return response()->json(['data' => $content->refresh()->load('video')]);
    }

    private function congelada(Content $content): ?JsonResponse
    {
        return in_array($content->status, self::EDITAVEIS, true) ? null : response()->json([
            'message' => 'Peça aprovada não troca a mídia. Devolva para revisão antes.',
        ], 422);
    }

    private function recusa(string $campo, string $mensagem): JsonResponse
    {
        return response()->json(['message' => $mensagem, 'errors' => [$campo => [$mensagem]]], 422);
    }
}
