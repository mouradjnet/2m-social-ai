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

    // O que se PEDE no consentimento: ler o perfil, publicar e ler as metricas dos
    // posts (Etapa 5). Nada de comentarios nem DMs.
    'scopes' => [
        'instagram_business_basic',
        'instagram_business_content_publish',
        'instagram_business_manage_insights',
    ],

    // O que a conexao EXIGE. Insights e opcional: quem desmarcar na tela da Meta
    // continua publicando, so sem a tela de Resultados.
    'required_scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],

    'insights_scope' => 'instagram_business_manage_insights',

    /*
     | Metricas por tipo de midia (IG Media Insights, consultado em 29/09/2026).
     | `impressions` foi descontinuada para midia criada depois de 02/07/2024 e fica
     | de fora. A Meta atrasa os numeros em ate 48 h e os guarda por 2 anos. Para o
     | carrossel a documentacao nao diz se o album (o post, nao os itens) tem
     | metricas: pede-se o conjunto do feed e, se a Meta recusar, fica registrado.
     */
    'insights_metrics' => [
        'IMAGE' => ['reach', 'views', 'likes', 'comments', 'saved', 'shares', 'total_interactions'],
        'CAROUSEL' => ['reach', 'views', 'likes', 'comments', 'saved', 'shares', 'total_interactions'],
        'REELS' => ['reach', 'views', 'likes', 'comments', 'saved', 'shares', 'total_interactions', 'ig_reels_avg_watch_time'],
    ],

    // Por quantos dias depois de publicado o post continua sendo medido. Depois, o
    // ultimo numero coletado fica como o resultado dele.
    'insights_days' => 30,

    // O token longo vale 60 dias e so renova depois de 24 h de vida. Renovar com
    // folga: um token vencido e uma publicacao que nao sai de madrugada.
    'refresh_when_days_left' => 10,

    'timeout_seconds' => 30,

];
