<?php

namespace App\Console\Commands;

use App\Domain\Results\InsightsCollector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('instagram:collect-insights')]
#[Description('Le da Meta as metricas dos posts publicados nos ultimos 30 dias')]
class CollectInstagramInsights extends Command
{
    public function handle(InsightsCollector $collector): int
    {
        $r = $collector->run();

        $this->line("medidas: {$r['medidas']} · recusadas pela Meta: {$r['recusadas']} · sem permissão de insights: {$r['sem_permissao']}");

        return self::SUCCESS;
    }
}
