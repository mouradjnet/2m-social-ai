<?php

namespace App\Domain\Analytics;

use App\Models\Content;
use App\Models\ContentReview;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Os numeros do projeto — calculados aqui, em PHP.
 *
 * NAO se pede conta a um LLM. O AnalyticsAgent recebe isto pronto e faz o que ele
 * faz bem: ler, priorizar e dizer o que fazer.
 *
 * Sem API das redes sociais nao existe desempenho: nao ha curtida, alcance nem
 * clique, e inventa-los seria mentir. O que existe e produtividade, aderencia a
 * estrategia e qualidade interna.
 */
class Metrics
{
    private const JANELA_DIAS = 30;

    public static function for(Project $project): array
    {
        $contents = $project->contents()->get();

        return [
            'volume' => self::volume($contents),
            'aderencia' => self::aderencia($project, $contents),
            'cadencia' => self::cadencia($contents),
            'qualidade' => self::qualidade($contents),
            'mix' => self::mix($contents),
        ];
    }

    /** @param  Collection<int, Content>  $contents */
    private static function volume(Collection $contents): array
    {
        return [
            'total' => $contents->count(),
            'por_status' => $contents->countBy('status')->all(),
        ];
    }

    /**
     * O coracao do relatorio: o que a estrategia pediu contra o que foi entregue.
     * Null sem estrategia ativa — nao ha com o que comparar.
     *
     * O peso real e calculado sobre as pecas COM pilar; as sem pilar (as anteriores a
     * coluna existir) sao contadas a parte, para o relatorio nao fingir que a amostra
     * e completa.
     *
     * @param  Collection<int, Content>  $contents
     */
    private static function aderencia(Project $project, Collection $contents): ?array
    {
        $strategy = $project->strategies()->where('status', 'active')->latest()->first();

        if ($strategy === null) {
            return null;
        }

        $comPilar = $contents->whereNotNull('pillar');
        $total = $comPilar->count();
        $porPilar = $comPilar->countBy('pillar');

        $pilares = collect($strategy->pillars)->map(function (array $pilar) use ($porPilar, $total) {
            $pedido = (int) $pilar['weight'];
            $real = $total === 0 ? 0 : (int) round($porPilar->get($pilar['name'], 0) / $total * 100);

            return [
                'nome' => $pilar['name'],
                'peso_pedido' => $pedido,
                'peso_real' => $real,
                'pecas' => $porPilar->get($pilar['name'], 0),
                // Em pontos percentuais. Positivo = entregou mais do que pediu.
                'desvio' => $real - $pedido,
            ];
        })->all();

        return [
            'pilares' => $pilares,
            'pecas_com_pilar' => $total,
            'sem_pilar' => $contents->whereNull('pillar')->count(),
        ];
    }

    /**
     * A janela dos proximos 30 dias: quantas pecas, quantos dias tem peca, e a maior
     * sequencia de dias sem nenhuma — a lacuna e o que um calendario editorial teme.
     *
     * @param  Collection<int, Content>  $contents
     */
    private static function cadencia(Collection $contents): array
    {
        $hoje = CarbonImmutable::now()->startOfDay();
        $fim = $hoje->addDays(self::JANELA_DIAS);

        $dias = $contents
            ->filter(fn (Content $c) => $c->scheduled_for !== null
                && $c->scheduled_for->between($hoje, $fim))
            ->map(fn (Content $c) => $c->scheduled_for->startOfDay()->toDateString());

        $unicos = $dias->unique()->sort()->values();

        return [
            'agendadas_30_dias' => $dias->count(),
            'dias_com_peca' => $unicos->count(),
            'maior_lacuna_dias' => self::maiorLacuna($unicos),
        ];
    }

    /** @param  Collection<int, string>  $dias  datas ordenadas */
    private static function maiorLacuna(Collection $dias): int
    {
        $maior = 0;

        for ($i = 1; $i < $dias->count(); $i++) {
            $anterior = CarbonImmutable::parse($dias[$i - 1]);
            $lacuna = $anterior->diffInDays(CarbonImmutable::parse($dias[$i])) - 1;
            $maior = max($maior, (int) $lacuna);
        }

        return $maior;
    }

    /** @param  Collection<int, Content>  $contents */
    private static function qualidade(Collection $contents): array
    {
        $reviews = ContentReview::whereIn('content_id', $contents->pluck('id'))->get();

        $violacoes = $reviews->flatMap(fn (ContentReview $r) => $r->violations);

        $regras = $violacoes
            ->countBy('rule')
            ->sortDesc()
            ->take(3)
            ->map(fn (int $n, string $regra) => ['regra' => $regra, 'vezes' => $n])
            ->values()
            ->all();

        return [
            'revisadas' => $reviews->count(),
            'aprovadas' => $reviews->where('verdict', 'pass')->count(),
            'reprovadas' => $reviews->where('verdict', 'fail')->count(),
            'violacoes' => $violacoes->count(),
            'regras_mais_violadas' => $regras,
        ];
    }

    /** @param  Collection<int, Content>  $contents */
    private static function mix(Collection $contents): array
    {
        return [
            'por_canal' => $contents->countBy('channel')->all(),
            'por_formato' => $contents->countBy('format')->all(),
        ];
    }
}
