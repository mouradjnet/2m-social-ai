<?php

return [
    /*
     | 'anthropic' em producao, 'mock' em desenvolvimento e CI.
     | Nenhum teste automatizado chama a API real.
     */
    'provider' => env('AI_PROVIDER', 'mock'),

    'default_model' => env('AI_MODEL_DEFAULT', 'claude-opus-4-8'),

    /*
     | Chamada a Anthropic. O SDK 0.7 nao aplica timeout sozinho (ver
     | AppServiceProvider::anthropicClient). A pior sequencia — timeout x
     | (retries + 1) + esperas — precisa caber no `--timeout` do worker
     | (docker/start.sh), senao o job morre sem gravar custo nem causa. O
     | `php artisan ai:check` confere a conta.
     */
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 75),
    'connect_timeout_seconds' => 10,
    // O SDK repete 408/409/429/5xx e queda de conexao. Uma vez basta: o resto
    // vira mensagem para a pessoa tentar mais tarde.
    'max_retries' => (int) env('AI_MAX_RETRIES', 1),
    // Teto para o `retry-after` de um 429 (CappedRetryAfterTransport).
    'max_retry_wait_seconds' => 10,
    // O `--timeout` do queue:work em docker/start.sh e supervisord.conf.
    'queue_timeout_seconds' => 180,

    /*
     | Trava de entrada por chamada, estimada ANTES de chamar (caracteres / 3, que
     | superestima tokens em portugues). A saida ja tem teto em `max_tokens` de cada
     | agente. E estimativa, nao contagem: a contagem real vem no `usage` da resposta.
     */
    'max_input_tokens' => (int) env('AI_MAX_INPUT_TOKENS', 60000),

    /*
     | Alertas no log (Log::warning), para consumo fora do normal: uma execucao cara
     | demais, ou o workspace/projeto passando de uma fracao do teto do mes.
     */
    'alert_run_cost_cents' => (int) env('AI_ALERT_RUN_COST_CENTS', 100),
    'alert_budget_fraction' => 0.8,

    /*
     | Centavos de dolar por 1 milhao de tokens — ESTIMATIVA a partir da tabela
     | publica da Anthropic (conferida em 29/09/2026). O valor faturado de verdade
     | esta no console da Anthropic, nao aqui.
     | Leitura de cache ~0,1x da entrada; escrita de cache 1,25x (TTL 5min).
     | Todo modelo usado por um agente PRECISA estar aqui: sem preco, o custo seria
     | 0 e o teto mensal nao valeria (AiConfig recusa subir).
     */
    'pricing' => [
        'claude-opus-4-8' => [
            'input' => 500,
            'output' => 2500,
            'cache_read' => 50,
            'cache_write' => 625,
        ],
        // Sucessor do Opus 4.8, mais barato. Migrar exige validar com chamada real.
        'claude-opus-5-5' => [
            'input' => 400,
            'output' => 2000,
            'cache_read' => 20,
            'cache_write' => 500,
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
        // Ler numeros, priorizar e concluir: `high`.
        // Consertar UMA peca reprovada: le o veredito, o trecho apontado e reescreve.
        // `high` porque o erro costuma ser conceitual (um depoimento inventado nao se
        // conserta trocando palavras), e e leitura fina do que o revisor disse.
        'rewriter' => [
            'model' => env('AI_MODEL_REWRITER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'high',
            'max_tokens' => 16000,
        ],
        // Montar a semana: le estrategia, aderencia e o que ja existe, e decide
        // dia, hora, pilar e formato. Nao escreve texto: `medium`.
        'planner' => [
            'model' => env('AI_MODEL_PLANNER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'medium',
            'max_tokens' => 16000,
            'default_posts' => 3,
            'max_posts' => 7,
        ],
        // Adaptar uma peca pronta a outro formato: mesmo assunto, outra forma.
        'repurposer' => [
            'model' => env('AI_MODEL_REPURPOSER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'medium',
            'max_tokens' => 16000,
        ],
        // Ler resultados reais com cautela e sugerir: `high`, como o analytics.
        'results' => [
            'model' => env('AI_MODEL_RESULTS', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'high',
            'max_tokens' => 16000,
            // Com menos posts medidos que isto, a leitura seria palpite.
            'min_measured' => 3,
        ],
        'analytics' => [
            'model' => env('AI_MODEL_ANALYTICS', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
            'effort' => 'high',
            'max_tokens' => 16000,
        ],
    ],

    'workspace_monthly_budget_cents' => (int) env('AI_WORKSPACE_MONTHLY_BUDGET_CENTS', 5000),

    /*
     | Teto por projeto (= por marca), alem do do workspace: uma marca nao consome o
     | mes das outras. Vazio = sem teto proprio, so o do workspace.
     */
    'project_monthly_budget_cents' => env('AI_PROJECT_MONTHLY_BUDGET_CENTS') === null
        || env('AI_PROJECT_MONTHLY_BUDGET_CENTS') === ''
        ? null
        : (int) env('AI_PROJECT_MONTHLY_BUDGET_CENTS'),
];
