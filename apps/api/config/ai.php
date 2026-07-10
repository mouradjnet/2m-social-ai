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
    ],

    'workspace_monthly_budget_cents' => (int) env('AI_WORKSPACE_MONTHLY_BUDGET_CENTS', 5000),
];
