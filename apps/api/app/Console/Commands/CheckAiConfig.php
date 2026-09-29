<?php

namespace App\Console\Commands;

use App\Ai\AiConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Confere a configuracao da IA SEM chamar a API (nao gasta nada). Roda na subida
 * do container: com AI_PROVIDER=anthropic e config errada, o container nao sobe,
 * e o erro aparece no deploy em vez de na primeira geracao de alguem.
 *
 * Nunca imprime a chave: so se ela existe.
 */
#[Signature('ai:check')]
#[Description('Confere a configuracao da IA (provedor, chave, precos, timeouts) sem chamar a API')]
class CheckAiConfig extends Command
{
    public function handle(): int
    {
        $problemas = AiConfig::problems();

        $this->line('Provedor: '.config('ai.provider'));

        if (config('ai.provider') === 'anthropic') {
            $this->line('Chave: '.(filled(config('services.anthropic.key')) ? 'presente' : 'AUSENTE'));
            $this->line('Modelos: '.implode(', ', array_unique(array_column(config('ai.agents'), 'model'))));
            $this->line(sprintf(
                'Timeout: %ds x %d tentativa(s); worker: %ds',
                config('ai.timeout_seconds'),
                config('ai.max_retries') + 1,
                config('ai.queue_timeout_seconds'),
            ));
        }

        if ($problemas === []) {
            $this->info('Configuracao da IA ok.');

            return self::SUCCESS;
        }

        foreach ($problemas as $problema) {
            $this->error($problema);
        }

        return self::FAILURE;
    }
}
