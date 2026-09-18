<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Export\ContentZip;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /**
     * Baixa o conteudo pronto do projeto em zip.
     *
     * `view`, nao `update`: exportar e LER. Um `viewer` — o cliente da agencia, que so
     * acompanha — precisa poder levar o proprio conteudo embora.
     */
    public function download(Project $project): BinaryFileResponse|JsonResponse
    {
        Gate::authorize('view', $project);

        if (ContentZip::pecas($project)->isEmpty()) {
            return response()->json([
                'message' => 'Não há peça aprovada ou agendada para exportar.',
            ], 422);
        }

        // Arquivo temporario, apagado depois de enviado: o zip e derivado do banco, nao
        // um artefato para guardar — guarda-lo seria manter uma copia do conteudo do
        // cliente envelhecendo em disco.
        $caminho = tempnam(sys_get_temp_dir(), 'export-');

        $nome = ContentZip::escrever($project, $caminho);

        return response()
            ->download($caminho, $nome, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
