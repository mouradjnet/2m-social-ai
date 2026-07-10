<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $projects = $workspace->projects()
            ->when($request->string('status')->isNotEmpty(),
                fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request, Workspace $workspace): ProjectResource
    {
        $project = $workspace->projects()->create($request->validated());

        return new ProjectResource($project);
    }

    /**
     * Sem checagem de workspace aqui de proposito: o WorkspaceMemberScope no
     * modelo faz o binding falhar com 404 quando o projeto e de outro tenant.
     */
    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project);
    }
}
