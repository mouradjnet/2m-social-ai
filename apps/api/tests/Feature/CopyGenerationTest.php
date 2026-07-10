<?php

namespace Tests\Feature;

use App\Ai\Agents\CopywriterAgent;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\LlmRequest;
use Tests\TestCase;

class CopyGenerationTest extends TestCase
{
    public function test_o_mockprovider_devolve_cinco_pecas_para_o_schema_do_copywriter(): void
    {
        $agent = new CopywriterAgent;
        $request = new LlmRequest(
            model: 'claude-opus-4-8',
            instructions: 'x',
            userMessage: 'x',
            schema: $agent->schema(),
        );

        $response = app(LlmProvider::class)->generate($request);

        $this->assertCount(5, $response->output['pieces']);
        // A saida do mock satisfaz o validate do agente.
        $agent->validate($response->output);
    }
}
