<?php

namespace App\Ai\Providers;

readonly class LlmResponse
{
    public function __construct(
        /** JSON ja decodificado, ainda nao validado contra as regras de dominio. */
        public array $output,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public function costCents(array $pricing): int
    {
        $rate = $pricing[$this->model] ?? null;

        if ($rate === null) {
            return 0;
        }

        $cents = $this->inputTokens / 1_000_000 * $rate['input']
            + $this->outputTokens / 1_000_000 * $rate['output']
            + $this->cacheReadTokens / 1_000_000 * ($rate['cache_read'] ?? 0)
            + $this->cacheWriteTokens / 1_000_000 * ($rate['cache_write'] ?? 0);

        // Arredonda para cima: melhor superestimar o custo que estourar o orcamento.
        return (int) ceil($cents);
    }
}
