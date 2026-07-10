<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->company(),
            'company' => fake()->company(),
            'segment' => fake()->randomElement(['Clinica', 'Escritorio', 'Agencia', 'Varejo']),
            'description' => fake()->sentence(12),
            'status' => 'active',
            'color' => fake()->hexColor(),
        ];
    }
}
