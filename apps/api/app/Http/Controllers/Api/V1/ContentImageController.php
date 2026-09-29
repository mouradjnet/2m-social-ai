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
 * A imagem que vai ao ar com a peca. Trocar a imagem e mudar o que foi aprovado, entao
 * so vale antes da aprovacao — e grava a revisao com o de-para, como o texto.
 */
class ContentImageController extends Controller
{
    private const EDITAVEIS = ['idea', 'production', 'review'];

    public function update(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate([
            'asset_id' => ['present', 'nullable', 'integer'],
        ]);

        if (! in_array($content->status, self::EDITAVEIS, true)) {
            return response()->json([
                'message' => 'Peça aprovada não troca a imagem. Devolva para revisão antes.',
            ], 422);
        }

        // So imagem do MESMO projeto. O escopo de tenant ja esconde as de outro
        // workspace; esta checagem esconde as de outro projeto do mesmo workspace.
        if ($data['asset_id'] !== null
            && ! Asset::where('project_id', $content->project_id)->where('type', 'image')->whereKey($data['asset_id'])->exists()) {
            return response()->json([
                'message' => 'Imagem não encontrada neste projeto.',
                'errors' => ['asset_id' => ['Imagem não encontrada neste projeto.']],
            ], 422);
        }

        $de = $content->image_asset_id;

        if ($de !== $data['asset_id']) {
            DB::transaction(function () use ($content, $de, $data, $request) {
                $content->update(['image_asset_id' => $data['asset_id']]);
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $request->user()->id,
                    'changes' => ['image_asset_id' => ['from' => $de, 'to' => $data['asset_id']]],
                ]);
            });
        }

        return response()->json(['data' => $content->refresh()->load('image')]);
    }
}
