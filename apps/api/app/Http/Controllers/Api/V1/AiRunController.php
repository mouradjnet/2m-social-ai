<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use Illuminate\Http\JsonResponse;

class AiRunController extends Controller
{
    /**
     * Alvo do polling. O WorkspaceMemberScope no AiRun faz o binding falhar com
     * 404 quando a execucao e de outro tenant.
     */
    public function show(AiRun $aiRun): JsonResponse
    {
        return response()->json([
            'id' => $aiRun->id,
            'agent' => $aiRun->agent,
            'status' => $aiRun->status,
            'output' => $aiRun->output,
            // Mensagem legivel, nunca stack trace.
            'error' => $aiRun->error,
            // Classificacao da falha: a UI decide por ela, nunca pela prosa.
            'error_code' => $aiRun->error_code,
            'cost_cents' => $aiRun->cost_cents,
            'latency_ms' => $aiRun->latency_ms,
            'created_at' => $aiRun->created_at,
        ]);
    }
}
