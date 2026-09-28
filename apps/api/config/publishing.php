<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publicacao automatica (ADR-13)
    |--------------------------------------------------------------------------
    */

    // Se o worker ficou parado e o horario passou ha mais que isto, NAO publica: um
    // post de "bom dia" as 23h ou uma promocao vencida sao piores que nenhum. A
    // publicacao falha com o motivo e o humano remarca.
    'max_delay_minutes' => (int) env('PUBLISHING_MAX_DELAY_MINUTES', 720),

    // Tentativas em falha transitoria (limite de taxa, Meta fora do ar) e a espera
    // antes de cada uma, em minutos.
    'backoff_minutes' => [1, 5, 15, 30, 60],

    // O container da Meta pode levar alguns segundos para ficar FINISHED.
    'container_poll_minutes' => 1,
    'max_container_polls' => 10,

    // Depois de um resultado desconhecido no media_publish, espera antes de
    // perguntar a Meta o que aconteceu.
    'reconcile_after_minutes' => 2,

    // Um `publishing` parado ha mais que isto e um worker que morreu no meio.
    'stuck_after_minutes' => 10,

    'queue' => 'publishing',

];
