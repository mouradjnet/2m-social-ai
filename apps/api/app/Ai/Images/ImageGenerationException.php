<?php

namespace App\Ai\Images;

use RuntimeException;

/**
 * `refused`: o filtro do provedor recusou o prompt — repetir nao adianta, e o
 * texto do prompt que precisa mudar. `failed`: rede, 5xx, chave — pode repetir.
 */
class ImageGenerationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'failed')
    {
        parent::__construct($message);
    }
}
