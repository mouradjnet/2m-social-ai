<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Djair Falcao',
            'email' => 'djair@2msocial.test',
        ]);

        $workspace = Workspace::factory()->create([
            'name' => '2M Negocios',
            'slug' => '2m-negocios',
            'owner_id' => $owner->id,
        ]);

        // Um membro de cada papel, para exercitar autorizacao em desenvolvimento.
        foreach ([WorkspaceRole::Admin, WorkspaceRole::Editor, WorkspaceRole::Reviewer, WorkspaceRole::Viewer] as $role) {
            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => User::factory()->create()->id,
                'role' => $role,
                'joined_at' => now(),
            ]);
        }

        Project::factory()->count(3)->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
        ]);

        // Segundo workspace, sem relacao com o primeiro: o isolamento entre
        // tenants fica visivel em desenvolvimento, nao so no teste.
        $rival = Workspace::factory()->create(['name' => 'Agencia Rival']);
        Project::factory()->count(2)->create(['workspace_id' => $rival->id]);
    }
}
