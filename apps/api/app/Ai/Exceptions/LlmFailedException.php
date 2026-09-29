<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Qualquer falha do provedor que nao seja recusa. O `kind` diz ao job o que
 * contar ao usuario e ao operador: esperar (rate_limited, overloaded), avisar
 * quem configura o servidor (auth, config) ou tentar de novo (timeout,
 * connection, server_error). A mensagem e para o log; o usuario nunca a ve.
 */
class LlmFailedException extends RuntimeException
{
    public const KINDS = [
        'auth', 'config', 'rate_limited', 'overloaded', 'timeout',
        'connection', 'server_error', 'bad_request', 'invalid_output', 'input_too_large', 'provider',
    ];

    public function __construct(
        string $message = '',
        public readonly string $kind = 'provider',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
