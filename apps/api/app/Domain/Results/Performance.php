<?php

namespace App\Domain\Results;

use App\Models\Content;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationMetric;

/**
 * Os RESULTADOS reais do projeto, a partir do que a Meta devolveu (publication_metrics).
 * Separado de proposito do Domain\Analytics\Metrics, que mede o calendario (o que foi
 * planejado e entregue) e nunca desempenho.
 *
 * Contas feitas aqui, em PHP — o agente de IA recebe os numeros prontos e so
 * interpreta. Post sem coleta ainda (a Meta atrasa ate 48 h) ou com recusa da Meta
 * aparece como tal e fica fora das medias: nao e um post de alcance zero.
 */
class Performance
{
    /** O que se soma e se compara entre posts. */
    public const METRICAS = ['reach', 'views', 'likes', 'comments', 'saved', 'shares', 'total_interactions'];

    public static function for(Project $project, int $dias = 30): array
    {
        $publicacoes = Publication::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays($dias))
            ->orderByDesc('published_at')
            ->get();

        $ultimas = PublicationMetric::query()
            ->whereIn('publication_id', $publicacoes->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('publication_id'); // a ultima coleta de cada post vence

        $pecas = Content::withoutGlobalScopes()
            ->whereIn('id', $publicacoes->pluck('content_id'))
            ->get(['id', 'title', 'pillar', 'format'])
            ->keyBy('id');

        $posts = $publicacoes->map(function (Publication $p) use ($ultimas, $pecas) {
            $coleta = $ultimas->get($p->id);
            $peca = $pecas->get($p->content_id);

            return [
                'publication_id' => $p->id,
                'content_id' => $p->content_id,
                'title' => $peca?->title,
                'pillar' => $peca?->pillar,
                'format' => $peca?->format,
                'published_at' => $p->published_at?->toIso8601String(),
                'permalink' => $p->permalink,
                // measured | pending (a Meta ainda nao entregou) | unavailable (recusou)
                'state' => $coleta === null ? 'pending' : ($coleta->error === null ? 'measured' : 'unavailable'),
                'metrics' => $coleta?->error === null ? ($coleta?->metrics ?? null) : null,
                'error' => $coleta?->error,
                'collected_at' => $coleta?->collected_at?->toIso8601String(),
            ];
        })->values();

        $medidos = $posts->where('state', 'measured')->values();

        return [
            'days' => $dias,
            'published' => $posts->count(),
            'measured' => $medidos->count(),
            'totals' => self::somar($medidos),
            'engagement_rate' => self::taxa($medidos),
            'by_pillar' => self::agrupar($medidos, 'pillar'),
            'by_format' => self::agrupar($medidos, 'format'),
            'top' => $medidos->sortByDesc(fn ($p) => $p['metrics']['total_interactions'] ?? 0)->take(3)->values()->all(),
            'posts' => $posts->all(),
        ];
    }

    private static function somar($posts): array
    {
        return collect(self::METRICAS)->mapWithKeys(fn (string $m) => [
            $m => $posts->sum(fn ($p) => $p['metrics'][$m] ?? 0),
        ])->all();
    }

    /** Interacoes / alcance, em %. Null sem alcance medido (dividir por zero nao e taxa). */
    private static function taxa($posts): ?float
    {
        $alcance = $posts->sum(fn ($p) => $p['metrics']['reach'] ?? 0);

        return $alcance > 0
            ? round($posts->sum(fn ($p) => $p['metrics']['total_interactions'] ?? 0) * 100 / $alcance, 1)
            : null;
    }

    /** Media por post dentro de cada grupo (pilar, formato). */
    private static function agrupar($posts, string $chave): array
    {
        return $posts->groupBy(fn ($p) => $p[$chave] ?? 'sem '.$chave)
            ->map(fn ($grupo, $nome) => [
                'name' => $nome,
                'posts' => $grupo->count(),
                'avg_reach' => (int) round($grupo->avg(fn ($p) => $p['metrics']['reach'] ?? 0)),
                'avg_interactions' => (int) round($grupo->avg(fn ($p) => $p['metrics']['total_interactions'] ?? 0)),
                'engagement_rate' => self::taxa($grupo),
            ])
            ->sortByDesc('avg_interactions')
            ->values()
            ->all();
    }
}
