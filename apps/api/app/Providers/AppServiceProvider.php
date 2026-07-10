<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\Agents\CopywriterAgent;
use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\MockProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmProvider::class, function () {
            return match (config('ai.provider')) {
                'anthropic' => new AnthropicProvider($this->anthropicClient()),
                'mock' => new MockProvider,
                default => throw new LlmFailedException(
                    'AI_PROVIDER invalido: '.config('ai.provider')
                ),
            };
        });

        // O batch_size do copywriter e canonico no config; injetado aqui para
        // que o agente nao dependa de config() nos seus metodos (testavel puro).
        $this->app->bind(CopywriterAgent::class, fn () => new CopywriterAgent(
            (int) config('ai.agents.copywriter.batch_size'),
        ));
    }

    public function boot(): void
    {
        //
    }

    private function anthropicClient(): Client
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new LlmFailedException(
                'ANTHROPIC_API_KEY nao configurada. Use AI_PROVIDER=mock em desenvolvimento.'
            );
        }

        return new Client(apiKey: $key);
    }
}
