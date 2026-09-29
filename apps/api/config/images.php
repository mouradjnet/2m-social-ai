<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Geracao de imagens por IA (ADR-15)
    |--------------------------------------------------------------------------
    |
    | `fake` (padrao) desenha uma imagem lisa sem sair da maquina: dev, CI e
    | demonstracao. `openai` chama a Images API. O provedor e trocavel porque o
    | resto do sistema so ve bytes de imagem: a biblioteca reencoda tudo para o
    | JPEG que o Instagram aceita (ImageProcessor), venha de upload ou de IA.
    |
    | A Anthropic nao gera imagem; por isso este provedor e separado do LlmProvider.
    |
    */

    'provider' => env('IMAGE_PROVIDER', 'fake'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('IMAGE_MODEL', 'gpt-image-1'),
        // Quadrado: cabe no feed (4:5 a 1.91:1) e no carrossel. Os retratos
        // oferecidos pela API (2:3) ficam FORA da proporcao do Instagram.
        'size' => env('IMAGE_SIZE', '1024x1024'),
        'quality' => env('IMAGE_QUALITY', 'medium'),
        'timeout_seconds' => 120,
    ],

    // Centavos de dolar por imagem, gravados em `ai_runs.cost_cents` para o
    // orcamento do workspace. A Images API cobra por imagem conforme modelo,
    // tamanho e qualidade: CONFIRA a tabela de precos do provedor antes de ligar e
    // ajuste aqui. Arredonde para cima — o teto protege quem paga.
    'cost_cents_per_image' => (int) env('IMAGE_COST_CENTS', 5),

];
