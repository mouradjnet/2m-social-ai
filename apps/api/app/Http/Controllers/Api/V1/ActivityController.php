<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * O registro de atividade do projeto (ver ActivityLog). Leitura para qualquer membro:
 * saber quem desconectou a conta ou decidiu uma publicacao nao e segredo de admin.
 */
class ActivityController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => ActivityLog::where('project_id', $project->id)
                ->with('user')
                ->latest('id')
                ->limit(50)
                ->get(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'meta', 'created_at']),
        ]);
    }
}
