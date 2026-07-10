<?php

namespace App\Providers;

use Anthropic\Client;
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
