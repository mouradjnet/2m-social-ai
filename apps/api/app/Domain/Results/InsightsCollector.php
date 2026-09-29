<?php

namespace App\Domain\Results;

use App\Instagram\InstagramException;
use App\Instagram\InstagramGateway;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\PublicationMetric;

/**
 * Le da Meta as metricas dos posts publicados pelo sistema (Etapa 5). Roda uma vez
 * por dia: a Meta atrasa os numeros em ate 48 h, e consultar mais nao traz nada novo.
 *
 * So mede o que tem autorizacao: conta ativa E escopo de insights concedido. Uma
 * recusa da Meta vira linha com `error` — nunca zero, que seria mentir que o post
 * nao teve alcance.
 */
class InsightsCollector
{
    public function __construct(private readonly InstagramGateway $gateway) {}

    /** @return array{medidas: int, recusadas: int, sem_permissao: int} */
    public function run(): array
    {
        $resultado = ['medidas' => 0, 'recusadas' => 0, 'sem_permissao' => 0];

        $publicacoes = Publication::withoutGlobalScopes()
            ->where('status', 'published')
            ->whereNotNull('media_id')
            ->where('published_at', '>=', now()->subDays((int) config('instagram.insights_days')))
            ->get();

        $contas = InstagramAccount::withoutGlobalScopes()
            ->whereIn('id', $publicacoes->pluck('instagram_account_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        foreach ($publicacoes as $publicacao) {
            $conta = $contas->get($publicacao->instagram_account_id);

            // O post pode ter saido por uma conta que depois foi desconectada: o
            // token dela foi apagado, e a conta nova do projeto nao e dona da midia.
            if ($conta === null || ! $conta->isUsable() || ! $conta->hasInsights()) {
                $resultado['sem_permissao']++;

                continue;
            }

            $metricas = config("instagram.insights_metrics.{$publicacao->media_type}")
                ?? config('instagram.insights_metrics.IMAGE');

            try {
                $valores = $this->gateway->mediaInsights($publicacao->media_id, (string) $conta->access_token, $metricas);
                PublicationMetric::create(['publication_id' => $publicacao->id, 'metrics' => $valores, 'collected_at' => now()]);
                $resultado['medidas']++;
            } catch (InstagramException $e) {
                PublicationMetric::create([
                    'publication_id' => $publicacao->id,
                    'metrics' => [],
                    'error' => $e->getMessage(),
                    'collected_at' => now(),
                ]);
                $resultado['recusadas']++;
            }
        }

        return $resultado;
    }
}
