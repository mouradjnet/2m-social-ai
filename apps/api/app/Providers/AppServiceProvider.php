<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\Agents\CopywriterAgent;
use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\MockProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Por EMAIL, nao por IP: atras do proxy do Render todo mundo chega com o
        // mesmo IP, e o que se protege e a conta. O custo e aceito: um estranho
        // consegue travar o login de alguem por um minuto.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')))
            ->response($this->muitasTentativas(...)));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())
            ->response($this->muitasTentativas(...)));
    }

    /**
     * No formato de erro de validacao, preso ao email: a tela de login so exibe
     * `errors.{campo}`, e um 429 so com `message` falharia em silencio.
     */
    private function muitasTentativas(Request $request, array $headers): JsonResponse
    {
        $mensagem = 'Muitas tentativas. Aguarde um minuto e tente de novo.';

        return response()->json([
            'message' => $mensagem,
            'errors' => ['email' => [$mensagem]],
        ], 429, $headers);
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
