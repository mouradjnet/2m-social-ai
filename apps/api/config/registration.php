<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Quem pode se cadastrar
    |--------------------------------------------------------------------------
    |
    | Lista de emails separados por virgula. Vazia = cadastro aberto (o padrao
    | em dev e nos testes). Com itens = so esses emails criam conta.
    |
    | Em producao a lista NAO pode ficar vazia: o servico e publico e cada
    | workspace novo ganha o proprio teto de orcamento da IA, entao cadastro
    | aberto e chave da Anthropic ligada significa qualquer um gastando.
    |
    */

    'allowed_emails' => array_values(array_filter(array_map(
        fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('REGISTRATION_ALLOWED_EMAILS', ''))
    ))),

];
