<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Workspace> */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'owner_id' => User::factory(),
            'plan' => 'free',
        ];
    }

    /** O dono sempre e membro com papel `owner`. */
    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace) {
            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $workspace->owner_id,
                'role' => WorkspaceRole::Owner,
                'joined_at' => now(),
            ]);
        });
    }
}
