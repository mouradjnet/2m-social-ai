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

Schedule::command('instagram:refresh-tokens')->dailyAt('03:17')->withoutOverlapping();
