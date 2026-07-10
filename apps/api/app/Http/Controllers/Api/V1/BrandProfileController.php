<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBrandProfileRequest;
use App\Http\Resources\BrandProfileResource;
use App\Models\BrandProfile;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class BrandProfileController extends Controller
{
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->ok($this->profileFor($project));
    }

    /**
     * PATCH com merge parcial: o wizard envia so os campos do passo atual.
     * PUT exigiria o payload completo, que o wizard nunca tem antes do passo 4.
     */
    public function update(UpdateBrandProfileRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $profile = $this->profileFor($project);
        $profile->fill($request->validated())->save();

        return $this->ok($profile->refresh());
    }

    /** O perfil nasce vazio no primeiro acesso; nao ha nada a criar antes disso. */
    private function profileFor(Project $project): BrandProfile
    {
        return $project->brandProfile()->firstOrCreate([]);
    }

    /**
     * O ResourceResponse do Laravel devolve 201 sozinho quando o model tem
     * wasRecentlyCreated. Como criamos o perfil vazio sob demanda, um GET
     * responderia 201 na primeira vez. Aqui a resposta e sempre 200: quem cria
     * recurso e o POST, e este endpoint nao e um.
     */
    private function ok(BrandProfile $profile): JsonResponse
    {
        return BrandProfileResource::make($profile)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
