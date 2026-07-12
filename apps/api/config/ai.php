<?php

return [
    /*
     | 'anthropic' em producao, 'mock' em desenvolvimento e CI.
     | Nenhum teste automatizado chama a API real.
     */
    'provider' => env('AI_PROVIDER', 'mock'),

    'default_model' => env('AI_MODEL_DEFAULT', 'claude-opus-4-8'),

    /*
     | Centavos de dolar por 1 milhao de tokens.
     | claude-opus-4-8: US$ 5 de entrada, US$ 25 de saida.
     | Leitura de cache ~0,1x da entrada; escrita de cache 1,25x (TTL 5min).
     */
    'pricing' => [
        'claude-opus-4-8' => [
            'input' => 500,
            'output' => 2500,
            'cache_read' => 50,
            'cache_write' => 625,
        ],
    ],

    /*
     | A alavanca de custo e o `effort`, nao rebaixar o modelo. Rebaixar para
     | Sonnet ou Haiku e decisao de produto, com custo de qualidade: o campo
     | existe, mas o default nao a toma sozinho.
     */
    'agents' => [
        'strategist' => [
            'model' => env('AI_MODEL_STRATEGIST', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            // `high` e o default do Opus 4.8; `xhigh` e para coding e trabalho
            // agentico. Subir daqui exige max_tokens >= 64000 e streaming, senao
            // o raciocinio consome o orcamento e a resposta trunca.
            'effort' => 'high',
            'max_tokens' => 16000,
        ],
        'copywriter' => [
            'model' => env('AI_MODEL_COPYWRITER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'high',
            'max_tokens' => 16000,
            'batch_size' => 5,
        ],
        // Distribuir pecas ja escritas por um calendario e a tarefa mais barata dos
        // tres: nao escreve texto, so decide quando. Dai o effort `medium`.
        'social_media' => [
            'model' => env('AI_MODEL_SOCIAL_MEDIA', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'medium',
            'max_tokens' => 16000,
            'default_days' => 14,
        ],
        // Julgar texto contra o perfil da marca e trabalho de leitura fina: `high`.
        'reviewer' => [
            'model' => env('AI_MODEL_REVIEWER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'high',
            'max_tokens' => 16000,
        ],
        // Descrever uma cena a partir de um texto pronto: `medium`.
        'designer' => [
            'model' => env('AI_MODEL_DESIGNER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'medium',
            'max_tokens' => 16000,
        ],
        'seo' => [
            'model' => env('AI_MODEL_SEO', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'medium',
            'max_tokens' => 16000,
        ],
    ],

    'workspace_monthly_budget_cents' => (int) env('AI_WORKSPACE_MONTHLY_BUDGET_CENTS', 5000),
];
