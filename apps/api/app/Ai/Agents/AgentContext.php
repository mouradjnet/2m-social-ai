<?php

namespace App\Ai\Agents;

use App\Models\Project;

/**
 * Snapshot do projeto no momento da execucao, nao a entidade viva: uma geracao
 * antiga continua explicavel mesmo se a marca (ou a estrategia) mudar depois.
 */
readonly class AgentContext
{
    public function __construct(
        public int $projectId,
        public string $projectName,
        public ?string $segment,
        public array $brandProfile,
        /** A estrategia `active` do projeto, congelada. Null se nao houver. */
        public ?array $activeStrategy = null,
    ) {}

    public static function forProject(Project $project): self
    {
        $profile = $project->brandProfile()->firstOrCreate([]);

        $strategy = $project->strategies()
            ->where('status', 'active')
            ->latest()
            ->first();

        return new self(
            projectId: $project->id,
            projectName: $project->name,
            segment: $project->segment,
            brandProfile: $profile->only([
                'brand_name', 'description', 'products', 'services', 'audience',
                'persona', 'tone_of_voice', 'differentiators', 'competitors',
                'required_words', 'forbidden_words',
            ]),
            activeStrategy: $strategy?->only(['title', 'summary', 'editorial_line', 'pillars']),
        );
    }

    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'segment' => $this->segment,
            'brand_profile' => $this->brandProfile,
            'active_strategy' => $this->activeStrategy,
        ];
    }
}
