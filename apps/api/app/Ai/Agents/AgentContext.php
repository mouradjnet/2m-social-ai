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
        /** As pecas em `review`, com o texto inteiro — e ele que esta sendo julgado. */
        public ?array $reviewContents = null,
    ) {}

    /**
     * `$input` e o `ai_runs.input` da execucao, e e ele que diz o que carregar: janela
     * (social_media) ou ids a revisar (reviewer). Carregar tudo sempre poluiria — e
     * cobraria — o prompt dos agentes que nao usam aquele lote.
     *
     * Em ambos os casos o input e registro de INTENCAO: o texto e o status das pecas
     * sao buscados aqui, no momento da execucao, que e a fonte da verdade.
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
            reviewContents: isset($input['content_ids'])
                ? self::reviewContents($project, $input['content_ids'])
                : null,
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

        if ($this->reviewContents !== null) {
            $data['review_contents'] = $this->reviewContents;
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

    /**
     * As pecas a revisar, com o texto inteiro. O `content_ids` do input e intencao;
     * o filtro por `review` aqui e a verdade — uma peca que saiu da coluna entre o
     * POST e a execucao nao e julgada.
     *
     * @param  array<int>  $ids
     */
    private static function reviewContents(Project $project, array $ids): array
    {
        $columns = ['id', 'title', 'caption', 'cta', 'hashtags', 'format', 'channel'];

        return $project->contents()
            ->where('status', 'review')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get($columns)
            ->map->only($columns)
            ->all();
    }
}
