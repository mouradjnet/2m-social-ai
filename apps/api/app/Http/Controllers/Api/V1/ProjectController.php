<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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

    /**
     * Editar o projeto — na pratica, corrigir o FUSO. Ele so podia ser dito na
     * criacao, entao todo projeto existente ficou preso no default (o do 2F AutoShop,
     * em producao, inclusive) e nao havia tela para mudar. O social_media agenda no
     * fuso do projeto: errar significa publicar na madrugada do publico.
     *
     * O `store` roda sob o middleware `workspace:editor` (a rota e aninhada no
     * workspace). Esta nao: recebe so o projeto, entao quem autoriza e a policy —
     * mesmo desenho do resto de /projects/{project}.
     */
    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($project);
    }
}
