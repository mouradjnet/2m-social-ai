<?php

namespace App\Ai\Providers;

interface LlmProvider
{
    /**
     * @throws \App\Ai\Exceptions\LlmRefusedException  classificadores recusaram
     * @throws \App\Ai\Exceptions\LlmFailedException   qualquer outra falha do provedor
     */
    public function generate(LlmRequest $request): LlmResponse;
}
