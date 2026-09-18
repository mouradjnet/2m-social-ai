<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * Os classificadores de seguranca recusaram a requisicao. Vem como HTTP 200 com
 * `stopReason: refusal` e `content` vazio ou parcial — nao como excecao do SDK.
 * Reexecutar o mesmo prompt nao adianta.
 */
class LlmRefusedException extends RuntimeException
{
    public function __construct(public readonly ?string $category = null)
    {
        parent::__construct(
            'O modelo recusou esta requisição'.($category ? " (categoria: {$category})" : '').'.'
        );
    }
}
