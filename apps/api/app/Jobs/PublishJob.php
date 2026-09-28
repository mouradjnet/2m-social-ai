<?php

namespace App\Jobs;

use App\Domain\Publishing\Publisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Uma rodada do Publisher numa fila so dela (`publishing`): uma geracao de IA de
 * dois minutos na fila `default` nao pode atrasar o post das 8h.
 *
 * Uma tentativa por job, de proposito. Quem decide tentar de novo e o Publisher,
 * gravando `next_attempt_at` — e o Dispatcher reenfileira. Se o worker morrer no
 * meio, a fila nao re-executa as cegas: a publicacao fica `publishing` e o
 * Dispatcher a converte em `unknown`, que pergunta a Meta antes de agir.
 */
class PublishJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly int $publicationId)
    {
        $this->onQueue(config('publishing.queue'));
    }

    public function handle(Publisher $publisher): void
    {
        $publisher->run($this->publicationId);
    }
}
