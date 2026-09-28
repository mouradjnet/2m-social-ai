<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\Agents\CopywriterAgent;
use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\MockProvider;
use App\Instagram\FakeInstagramGateway;
use App\Instagram\GraphInstagramGateway;
use App\Instagram\InstagramGateway;
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

        // Como o LlmProvider: `fake` por padrao. So `graph` fala com a Meta — e exige
        // as credenciais do app, sem as quais nem chega a montar.
        $this->app->bind(InstagramGateway::class, fn () => match (config('instagram.driver')) {
            'graph' => new GraphInstagramGateway(
                (string) config('instagram.app_id') ?: throw new \RuntimeException('INSTAGRAM_APP_ID nao configurado.'),
                (string) config('instagram.app_secret') ?: throw new \RuntimeException('INSTAGRAM_APP_SECRET nao configurado.'),
                (string) config('instagram.redirect_uri'),
                (string) config('instagram.graph_version'),
                (int) config('instagram.timeout_seconds'),
            ),
            'fake' => new FakeInstagramGateway,
            default => throw new \RuntimeException('INSTAGRAM_DRIVER invalido: '.config('instagram.driver')),
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
