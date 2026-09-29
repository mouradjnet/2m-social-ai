<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Budget;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * O consumo de IA do mes: quanto foi gasto, de quanto, e onde. So leitura — o teto e
 * do operador (ver Budget). A rota exige `workspace:admin`.
 */
class UsageController extends Controller
{
    public function show(Workspace $workspace): JsonResponse
    {
        return response()->json(['data' => Budget::usage($workspace)]);
    }
}
