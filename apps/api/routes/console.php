<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | O agendador (`php artisan schedule:work` no container `scheduler`). Sem ele nada
 | do que segue acontece sozinho.
 */
// A publicacao automatica (ADR-13). A cada minuto: o que chegou a hora vira
// publicacao, e o que espera nova tentativa volta para a fila `publishing`.
Schedule::command('publications:dispatch')->everyMinute()->withoutOverlapping(5);

// Batimento do agendador: o healthcheck do container `scheduler` confere que este
// arquivo mudou nos ultimos minutos. Processo vivo nao prova agendador rodando.
Schedule::call(fn () => touch(storage_path('framework/scheduler-heartbeat')))->everyMinute()->name('heartbeat');

Schedule::command('instagram:refresh-tokens')->dailyAt('03:17')->withoutOverlapping();

// Metricas reais dos posts (Etapa 5). Uma vez por dia: a Meta atrasa ate 48 h.
Schedule::command('instagram:collect-insights')->dailyAt('06:40')->withoutOverlapping();
