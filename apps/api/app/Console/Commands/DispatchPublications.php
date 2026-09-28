<?php

namespace App\Console\Commands;

use App\Domain\Publishing\Dispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('publications:dispatch')]
#[Description('Cria as publicacoes do Instagram cujo horario chegou e enfileira as que devem tentar de novo')]
class DispatchPublications extends Command
{
    public function handle(Dispatcher $dispatcher): int
    {
        $r = $dispatcher->run();

        if (array_sum($r) > 0) {
            $this->info("Criadas: {$r['criadas']}. Enfileiradas: {$r['enfileiradas']}. Destravadas: {$r['destravadas']}.");
        }

        return self::SUCCESS;
    }
}
