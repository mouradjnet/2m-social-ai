<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | `graph` fala com a API oficial da Meta. `fake` simula a conexao e a
    | publicacao sem sair da maquina — e o padrao, como o `AI_PROVIDER=mock`:
    | nada vai para o Instagram de verdade sem alguem trocar esta variavel.
    |
    */

    'driver' => env('INSTAGRAM_DRIVER', 'fake'),

    // "Instagram API with Instagram Login" (ADR-14): o app da Meta, produto Instagram.
    'app_id' => env('INSTAGRAM_APP_ID'),
    'app_secret' => env('INSTAGRAM_APP_SECRET'),

    // Tem de ser IDENTICA a cadastrada no app da Meta, byte a byte.
    'redirect_uri' => env('INSTAGRAM_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/instagram/callback'),

    // Confira a versao corrente em developers.facebook.com/docs/graph-api/changelog.
    'graph_version' => env('INSTAGRAM_GRAPH_VERSION', 'v23.0'),

    // O minimo para publicar: ler o perfil e publicar. Nada de comentarios, DMs ou insights.
    'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],

    // O token longo vale 60 dias e so renova depois de 24 h de vida. Renovar com
    // folga: um token vencido e uma publicacao que nao sai de madrugada.
    'refresh_when_days_left' => 10,

    'timeout_seconds' => 30,

];
