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
        /** `{starts_on, days}`, vindo do input da execucao. Null fora do social_media. */
        public ?array $scheduleWindow = null,
        /** As pecas `approved`, o lote a distribuir. Null quando nao ha janela. */
        public ?array $approvedContents = null,
    ) {}

    /**
     * `$input` e o `ai_runs.input` da execucao. So o social_media manda janela; as
     * pecas aprovadas so sao buscadas quando ela vem. Carrega-las sempre poluiria
     * (e cobraria) o prompt do strategist e do copywriter com um lote que eles nao
     * usam.
     */
    public static function forProject(Project $project, array $input = []): self
    {
        $profile = $project->brandProfile()->firstOrCreate([]);

        $strategy = $project->strategies()
            ->where('status', 'active')
            ->latest()
            ->first();

        $window = isset($input['starts_on'], $input['days'])
            ? ['starts_on' => $input['starts_on'], 'days' => (int) $input['days']]
            : null;

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
            scheduleWindow: $window,
            approvedContents: $window === null ? null : self::approvedContents($project),
        );
    }

    public function toArray(): array
    {
        $data = [
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'segment' => $this->segment,
            'brand_profile' => $this->brandProfile,
            'active_strategy' => $this->activeStrategy,
        ];

        if ($this->scheduleWindow !== null) {
            $data['schedule_window'] = $this->scheduleWindow;
            $data['approved_contents'] = $this->approvedContents;
        }

        return $data;
    }

    /** As aprovadas no momento da execucao — nao no momento do POST. */
    private static function approvedContents(Project $project): array
    {
        return $project->contents()
            ->where('status', 'approved')
            ->orderBy('id')
            ->get(['id', 'title', 'format', 'channel'])
            ->map->only(['id', 'title', 'format', 'channel'])
            ->all();
    }
}
