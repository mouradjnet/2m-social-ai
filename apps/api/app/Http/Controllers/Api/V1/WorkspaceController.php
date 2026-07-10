<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        // O workspace e a associacao do dono nascem juntos ou nao nascem:
        // um workspace sem membros seria inacessivel ate para quem o criou.
        $workspace = DB::transaction(function () use ($data, $request) {
            $workspace = Workspace::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'owner_id' => $request->user()->id,
            ]);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'role' => WorkspaceRole::Owner,
                'joined_at' => now(),
            ]);

            return $workspace;
        });

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => WorkspaceRole::Owner->value,
            ],
        ], 201);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (Workspace::where('slug', $slug)->exists());

        return $slug;
    }
}
