<?php

namespace App\Ai;

/**
 * O que precisa estar certo antes de a IA real gastar um centavo. Vazio = pronto.
 *
 * Roda em tres lugares: ao montar o AnthropicProvider (nenhuma chamada sai com
 * config errada), no `php artisan ai:check` e na subida do container
 * (docker/start.sh), para o erro aparecer no deploy e nao na primeira geracao.
 * Com AI_PROVIDER=mock nada disto se aplica: o mock nao gasta.
 */
class AiConfig
{
    /** @return list<string> */
    public static function problems(): array
    {
        $provider = config('ai.provider');

        if ($provider === 'mock') {
            return [];
        }

        if ($provider !== 'anthropic') {
            return ["AI_PROVIDER invalido: {$provider} (use mock ou anthropic)."];
        }

        $problemas = [];

        if (blank(config('services.anthropic.key'))) {
            $problemas[] = 'ANTHROPIC_API_KEY nao configurada. Use AI_PROVIDER=mock em desenvolvimento.';
        }

        // Modelo sem preco custaria 0 no Budget: o teto mensal deixaria de valer
        // sem ninguem perceber. Melhor recusar a subir.
        $precos = config('ai.pricing');
        foreach (config('ai.agents') as $agente => $cfg) {
            if (! isset($precos[$cfg['model']])) {
                $problemas[] = "Modelo {$cfg['model']} (agente {$agente}) sem preco em ai.pricing.";
            }
        }

        // O worker mata o job aos `queue_timeout_seconds`. Se a pior sequencia de
        // tentativas do SDK passar disso, a chamada morre sem registrar custo nem causa.
        // Espera entre tentativas: o backoff do SDK vai ate 8 s; o `retry-after` do
        // 429 e limitado pelo CappedRetryAfterTransport.
        $espera = max(8, (int) config('ai.max_retry_wait_seconds'));
        $pior = config('ai.timeout_seconds') * (config('ai.max_retries') + 1) + config('ai.max_retries') * $espera;
        if ($pior >= config('ai.queue_timeout_seconds')) {
            $problemas[] = sprintf(
                'AI_TIMEOUT_SECONDS x (AI_MAX_RETRIES + 1) = ~%ds nao cabe no timeout do worker (%ds).',
                $pior,
                config('ai.queue_timeout_seconds'),
            );
        }

        return array_values(array_unique($problemas));
    }
}
