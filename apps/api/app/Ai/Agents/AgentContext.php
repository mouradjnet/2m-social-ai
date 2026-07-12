<?php

namespace App\Ai\Agents;

use App\Models\ContentReview;
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
        /**
         * `{starts_on, days, timezone}`. `starts_on`/`days` vem do input da execucao;
         * o `timezone` e o do projeto, e diz em que fuso as horas da janela — e as que
         * o agente devolver — sao lidas. Null fora do social_media.
         */
        public ?array $scheduleWindow = null,
        /** As pecas `approved`, o lote a distribuir. Null quando nao ha janela. */
        public ?array $approvedContents = null,
        /**
         * O lote de pecas de UMA coluna, com o texto inteiro. Generico de proposito:
         * o reviewer julga o lote de `review`, o designer desenha o de `production`.
         * Quem diz qual coluna e o `batch_status` do input.
         */
        public ?array $batchContents = null,
        /** A coluna de onde veio o lote: `review`, `production`, ... */
        public ?string $batchStatus = null,
        /**
         * Os numeros do projeto, calculados por Domain\Analytics\Metrics no momento
         * do POST e congelados no `input` da execucao. NAO sao recalculados aqui: o
         * relatorio precisa citar exatamente os numeros que ele leu.
         */
        public ?array $projectMetrics = null,
        /**
         * O que a marca JA tem (titulo + pilar das pecas vivas). Sem isso o copywriter
         * escreve como se fosse a primeira vez, todas as vezes: em producao ele
         * regenerou titulo identico ao de uma peca existente e reincidiu num erro que
         * o reviewer ja tinha reprovado.
         */
        public ?array $existingContents = null,
        /**
         * O pilar que o lote deve cobrir, quando o humano escolhe um. Null = distribuir
         * pelos pesos (o default). Existe porque um pilar leve nunca era sorteado:
         * 15% de um lote de 5 da 0,75, que vira zero peca — e ficava zerado para sempre.
         */
        public ?string $targetPillar = null,
        /**
         * As regras que o reviewer JA reprovou neste projeto, com a sugestao dele.
         * Sem isso o copywriter reincide: em producao ele reescreveu o depoimento
         * fabricado (a persona interna vendida como cliente real) depois de o
         * reviewer ter reprovado exatamente isso. O guard de titulo nao pega — o
         * erro e conceitual, e o titulo era outro.
         */
        public ?array $pastViolations = null,
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
            ? [
                'starts_on' => $input['starts_on'],
                'days' => (int) $input['days'],
                'timezone' => $project->timezone,
            ]
            : null;

        return new self(
            projectId: $project->id,
            projectName: $project->name,
            segment: $project->segment,
            brandProfile: $profile->only([
                'brand_name', 'description', 'products', 'services', 'audience',
                'persona', 'tone_of_voice', 'differentiators', 'competitors',
                'required_words', 'forbidden_words', 'colors',
            ]),
            activeStrategy: $strategy?->only(['title', 'summary', 'editorial_line', 'pillars']),
            scheduleWindow: $window,
            approvedContents: $window === null ? null : self::approvedContents($project),
            batchContents: isset($input['content_ids'], $input['batch_status'])
                ? self::batchContents($project, $input['content_ids'], $input['batch_status'])
                : null,
            batchStatus: $input['batch_status'] ?? null,
            projectMetrics: $input['metrics'] ?? null,
            // Quem pede o inventario e o input (hoje, so o copywriter): o prompt do
            // strategist nao paga tokens por uma lista que ele nao usa.
            existingContents: ($input['with_existing_contents'] ?? false)
                ? self::existingContents($project)
                : null,
            targetPillar: $input['pillar'] ?? null,
            pastViolations: ($input['with_past_violations'] ?? false)
                ? self::pastViolations($project)
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

        if ($this->batchContents !== null) {
            $data['batch_status'] = $this->batchStatus;
            $data['batch_contents'] = $this->batchContents;
        }

        if ($this->projectMetrics !== null) {
            $data['metrics'] = $this->projectMetrics;
        }

        if ($this->existingContents !== null) {
            $data['existing_contents'] = $this->existingContents;
        }

        if ($this->targetPillar !== null) {
            $data['target_pillar'] = $this->targetPillar;
        }

        if ($this->pastViolations !== null) {
            $data['past_violations'] = $this->pastViolations;
        }

        return $data;
    }

    /**
     * As regras ja reprovadas pelo reviewer neste projeto, uma vez cada. Vai a `rule`
     * e a `suggestion` — o `excerpt` fica de fora de proposito: e o texto da peca
     * velha, e mandar o erro por extenso convida o modelo a imita-lo.
     */
    private static function pastViolations(Project $project): array
    {
        $reviews = ContentReview::query()
            ->whereIn('content_id', $project->contents()->select('id'))
            ->where('verdict', 'fail')
            ->orderBy('id')
            ->get(['violations']);

        $porRegra = [];

        foreach ($reviews as $review) {
            foreach ($review->violations ?? [] as $violacao) {
                $regra = $violacao['rule'] ?? null;

                if ($regra === null || isset($porRegra[$regra])) {
                    continue;
                }

                $porRegra[$regra] = [
                    'rule' => $regra,
                    'suggestion' => $violacao['suggestion'] ?? null,
                ];
            }
        }

        return array_values($porRegra);
    }

    /**
     * As pecas que ainda contam como conteudo da marca. Arquivadas ficam de fora: uma
     * peca reprovada e arquivada PODE (e deve) ser reescrita.
     */
    private static function existingContents(Project $project): array
    {
        return $project->contents()
            ->where('status', '!=', 'archived')
            ->orderBy('id')
            ->get(['title', 'pillar'])
            ->map->only(['title', 'pillar'])
            ->all();
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
     * O lote de uma coluna, com o texto inteiro. O `content_ids` do input e intencao;
     * o filtro por status aqui e a verdade — uma peca que saiu da coluna entre o POST
     * e a execucao nao e atendida.
     *
     * @param  array<int>  $ids
     */
    private static function batchContents(Project $project, array $ids, string $status): array
    {
        $columns = ['id', 'title', 'caption', 'cta', 'hashtags', 'format', 'channel'];

        return $project->contents()
            ->where('status', $status)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get($columns)
            ->map->only($columns)
            ->all();
    }
}
