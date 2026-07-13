<?php

namespace App\Ai\Providers;

use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Exceptions\LlmRefusedException;

interface LlmProvider
{
    /**
     * @throws LlmRefusedException classificadores recusaram
     * @throws LlmFailedException qualquer outra falha do provedor
     */
    public function generate(LlmRequest $request): LlmResponse;
}
