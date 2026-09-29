<?php

namespace App\Ai\Providers;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * O SDK 0.7 espera o `retry-after` da resposta sem teto: um 429 com
 * `retry-after: 60` dorme 60 s dentro do job, e o worker (180 s) o mata no meio,
 * sem custo nem causa gravados. Aqui o cabecalho e limitado. Repetir antes do que
 * a API pediu pode dar 429 de novo; ai a falha chega ao job como rate_limited, e
 * a pessoa tenta mais tarde.
 */
class CappedRetryAfterTransport implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly int $maxSeconds,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $header = $response->getHeaderLine('retry-after');

        if ($header !== '' && (! is_numeric($header) || (float) $header > $this->maxSeconds)) {
            return $response->withHeader('retry-after', (string) $this->maxSeconds);
        }

        return $response;
    }
}
