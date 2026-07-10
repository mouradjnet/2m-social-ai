<?php

namespace App\Ai\Providers;

readonly class LlmRequest
{
    public function __construct(
        public string $model,
        /** Instrucoes do agente. Congeladas: nada de timestamp ou id aqui dentro. */
        public string $instructions,
        /** Dados do projeto, delimitados. Sempre no turno do usuario. */
        public string $userMessage,
        /** JSON Schema do output esperado. */
        public array $schema,
        public string $effort = 'high',
        public int $maxTokens = 16000,
    ) {}
}
