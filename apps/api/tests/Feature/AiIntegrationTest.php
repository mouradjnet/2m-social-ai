<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Ai\AiConfig;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\CappedRetryAfterTransport;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\LlmRequest;
use App\Ai\Providers\LlmResponse;
use App\Ai\Providers\MockProvider;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * CP-02: a integracao real com a Anthropic, provada SEM chamar a API. Onde o
 * AnthropicProvider real entra, o transporte HTTP e falso (nenhum byte sai da
 * maquina); o resto usa o MockProvider ou um fake no container.
 *
 * Cuidado ao mexer: o Http::preventStrayRequests() do TestCase NAO cobre o Guzzle
 * do SDK. Montar o provedor real com chave e deixar a fila rodar seria uma chamada
 * paga de verdade.
 */
class AiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    // Sem o prefixo das chaves reais: scanner de segredo nao deve disparar num teste.
    private const CHAVE_FALSA = 'chave-falsa-cp02-NAO-PODE-VAZAR-0123456789';

    private function cena(): array
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => $workspace->id, 'user_id' => $editor->id,
            'role' => WorkspaceRole::Editor, 'joined_at' => now(),
        ]);
        Sanctum::actingAs($editor);

        return [$workspace, $editor, Project::factory()->create(['workspace_id' => $workspace->id])];
    }

    private function gerarEstrategia(Project $project): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/strategies:generate");
    }

    private function ultimaExecucao(): AiRun
    {
        return AiRun::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** Provedor fake que guarda cada LlmRequest e devolve uma estrategia valida do mock. */
    private function provedorQueGrava(array &$pedidos, int $inputTokens = 0): LlmProvider
    {
        $provider = new class($pedidos, $inputTokens) implements LlmProvider
        {
            public function __construct(private array &$pedidos, private readonly int $inputTokens) {}

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->pedidos[] = $request;
                $mock = (new MockProvider)->generate($request);

                return new LlmResponse($mock->output, $request->model, inputTokens: $this->inputTokens);
            }
        };
        $this->app->bind(LlmProvider::class, fn () => $provider);

        return $provider;
    }

    // --- 1. Mock e configuracao ------------------------------------------------------

    public function test_mock_passa_no_ai_check_e_e_o_provedor_montado(): void
    {
        $this->artisan('ai:check')->expectsOutputToContain('Configuracao da IA ok.')->assertSuccessful();
        $this->assertInstanceOf(MockProvider::class, app(LlmProvider::class));
    }

    /** 2. Sem chave: nada sai, e a pessoa ve que e configuracao, nao "tente de novo". */
    public function test_anthropic_sem_chave_falha_com_mensagem_de_configuracao_sem_chamar_nada(): void
    {
        config(['ai.provider' => 'anthropic', 'services.anthropic.key' => null]);
        [, , $project] = $this->cena();

        $this->artisan('ai:check')->expectsOutputToContain('ANTHROPIC_API_KEY nao configurada')->assertFailed();

        $this->gerarEstrategia($project)->assertStatus(202);

        $run = $this->ultimaExecucao();
        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_failed', $run->error_code);
        $this->assertStringContainsString('não está configurada corretamente', $run->error);
        $this->assertSame(0, (int) $run->cost_cents);
    }

    public function test_modelo_sem_preco_e_recusado_na_configuracao(): void
    {
        config([
            'ai.provider' => 'anthropic',
            'services.anthropic.key' => self::CHAVE_FALSA,
            'ai.agents.strategist.model' => 'claude-modelo-sem-preco',
        ]);

        $this->assertContains(
            'Modelo claude-modelo-sem-preco (agente strategist) sem preco em ai.pricing.',
            AiConfig::problems(),
        );
    }

    public function test_timeout_que_nao_cabe_no_worker_e_recusado(): void
    {
        config(['ai.provider' => 'anthropic', 'services.anthropic.key' => self::CHAVE_FALSA]);
        $this->assertSame([], AiConfig::problems());

        config(['ai.timeout_seconds' => 90, 'ai.max_retries' => 1]);
        $this->assertStringContainsString('nao cabe no timeout do worker', implode(' ', AiConfig::problems()));
    }

    /** ai:check mostra se a chave existe, nunca a chave. */
    public function test_ai_check_nao_imprime_a_chave(): void
    {
        config(['ai.provider' => 'anthropic', 'services.anthropic.key' => self::CHAVE_FALSA]);

        $this->artisan('ai:check')
            ->expectsOutputToContain('Chave: presente')
            ->doesntExpectOutputToContain('NAO-PODE-VAZAR')
            ->assertSuccessful();
    }

    /**
     * O Client montado de verdade (sem chamar nada): timeout no transporte, teto do
     * retry-after e o maxRetries por chamada — os tres que o SDK 0.7 nao faz sozinho.
     */
    public function test_provedor_real_e_montado_com_timeout_e_tentativas_limitadas(): void
    {
        config(['ai.provider' => 'anthropic', 'services.anthropic.key' => self::CHAVE_FALSA]);

        $provider = app(LlmProvider::class);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame(1, (new ReflectionProperty($provider, 'maxRetries'))->getValue($provider));

        $client = (new ReflectionProperty($provider, 'client'))->getValue($provider);
        $transporte = (new ReflectionProperty($client, 'options'))->getValue($client)->transporter;
        $this->assertInstanceOf(CappedRetryAfterTransport::class, $transporte);

        $guzzle = (new ReflectionProperty($transporte, 'inner'))->getValue($transporte);
        $this->assertInstanceOf(GuzzleClient::class, $guzzle);
        $this->assertSame(75.0, $guzzle->getConfig('timeout'));
        $this->assertSame(10.0, $guzzle->getConfig('connect_timeout'));
    }

    /** O worker e o config precisam concordar, e a subida do container confere a IA. */
    public function test_start_sh_roda_o_ai_check_e_o_timeout_do_worker_bate_com_o_config(): void
    {
        $start = (string) file_get_contents(base_path('../../docker/start.sh'));
        $supervisor = (string) file_get_contents(base_path('../../docker/supervisord.conf'));
        $timeout = '--timeout='.config('ai.queue_timeout_seconds').' ';

        $this->assertStringContainsString("\nphp artisan ai:check\n", str_replace("\r\n", "\n", $start));
        $this->assertStringContainsString($timeout, $start);
        $this->assertStringContainsString($timeout, $supervisor);
    }

    // --- Contexto da marca e isolamento --------------------------------------------

    /** 11 e 12: o prompt leva o perfil DESTE projeto, e nada do perfil de outro. */
    public function test_prompt_leva_so_o_perfil_da_propria_marca(): void
    {
        $pedidos = [];
        $this->provedorQueGrava($pedidos);
        [$workspace, , $project] = $this->cena();
        $outro = Project::factory()->create(['workspace_id' => $workspace->id]);

        $project->brandProfile()->updateOrCreate([], [
            'brand_name' => '2M Saúde Feminina', 'forbidden_words' => ['cura garantida'],
        ]);
        $outro->brandProfile()->updateOrCreate([], [
            'brand_name' => 'Marca Vizinha', 'forbidden_words' => ['palavra-da-vizinha'],
        ]);

        $this->gerarEstrategia($project)->assertStatus(202);

        $this->assertCount(1, $pedidos);
        $mensagem = $pedidos[0]->userMessage;
        $this->assertStringContainsString('2M Saúde Feminina', $mensagem);
        $this->assertStringContainsString('cura garantida', $mensagem);
        $this->assertStringNotContainsString('Marca Vizinha', $mensagem);
        $this->assertStringNotContainsString('palavra-da-vizinha', $mensagem);

        // 10: persistiu, com o que a resposta informou.
        $this->assertSame('succeeded', $this->ultimaExecucao()->status);
        // 14: gerar conteudo nunca publica nada sozinho.
        $this->assertSame(0, Publication::withoutGlobalScopes()->count());
    }

    // --- Controle de consumo ---------------------------------------------------------

    public function test_teto_por_projeto_barra_so_a_marca_que_estourou(): void
    {
        config(['ai.project_monthly_budget_cents' => 100]);
        [$workspace, $editor, $project] = $this->cena();
        $outro = Project::factory()->create(['workspace_id' => $workspace->id]);

        AiRun::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id, 'agent' => 'strategist',
            'provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'status' => 'succeeded',
            'input' => [], 'cost_cents' => 100, 'created_by' => $editor->id,
        ]);

        $this->gerarEstrategia($project)
            ->assertStatus(402)
            ->assertJsonPath('message', 'Orçamento mensal de IA esgotado para este projeto.')
            ->assertJsonPath('limit_cents', 100);

        // A outra marca do mesmo workspace segue.
        $this->gerarEstrategia($outro)->assertStatus(202);
    }

    public function test_sem_teto_por_projeto_vale_so_o_do_workspace(): void
    {
        config(['ai.project_monthly_budget_cents' => null]);
        [$workspace, $editor, $project] = $this->cena();

        AiRun::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id, 'agent' => 'strategist',
            'provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'status' => 'succeeded',
            'input' => [], 'cost_cents' => 100, 'created_by' => $editor->id,
        ]);

        $this->gerarEstrategia($project)->assertStatus(202);
    }

    /** 9: entrada grande demais nao chega a ser paga. */
    public function test_entrada_acima_do_limite_nao_chama_o_provedor(): void
    {
        config(['ai.max_input_tokens' => 10]);
        $pedidos = [];
        $this->provedorQueGrava($pedidos);
        [, , $project] = $this->cena();

        $this->gerarEstrategia($project)->assertStatus(202);

        $this->assertSame([], $pedidos);
        $run = $this->ultimaExecucao();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('conteúdo demais', $run->error);
    }

    public function test_execucao_cara_gera_alerta_no_log(): void
    {
        Log::spy();
        config(['ai.alert_run_cost_cents' => 100]);
        $pedidos = [];
        // 1 milhao de tokens de entrada no Opus 4.8 = 500 centavos (estimativa).
        $this->provedorQueGrava($pedidos, inputTokens: 1_000_000);
        [, , $project] = $this->cena();

        $this->gerarEstrategia($project)->assertStatus(202);

        $this->assertSame(500, (int) $this->ultimaExecucao()->cost_cents);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'custo acima do alerta') && $ctx['cost_cents'] === 500)
            ->once();
    }

    // --- 13. Credenciais fora do log -------------------------------------------------

    /**
     * O AnthropicProvider real, com a chave falsa e um 401 no transporte: o log
     * formatado de verdade (com stack trace) nao pode conter a chave. A excecao do SDK
     * carrega a requisicao inteira, com o cabecalho x-api-key.
     */
    public function test_falha_de_autenticacao_nao_vaza_a_chave_no_log(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'cp02-log-');
        config([
            'logging.channels.cp02' => ['driver' => 'single', 'path' => $arquivo, 'level' => 'debug'],
            'logging.default' => 'cp02',
        ]);

        $transporte = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(401, ['Content-Type' => 'application/json'], json_encode([
                    'type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
                ]));
            }
        };
        $provider = new AnthropicProvider(new Client(
            apiKey: self::CHAVE_FALSA,
            requestOptions: RequestOptions::with(transporter: $transporte),
        ), 0);
        $this->app->bind(LlmProvider::class, fn () => $provider);
        [, , $project] = $this->cena();

        $this->gerarEstrategia($project)->assertStatus(202);

        $log = (string) file_get_contents($arquivo);
        @unlink($arquivo);

        // O log existe e diz o que houve...
        $this->assertStringContainsString('"provider_error":"auth"', $log);
        $this->assertStringContainsString('Anthropic HTTP 401 (authentication_error)', $log);
        // ...sem a chave, inteira ou em pedaco.
        $this->assertStringNotContainsString(self::CHAVE_FALSA, $log);
        $this->assertStringNotContainsString('NAO-PODE-VAZAR', $log);
        // E a pessoa recebe a frase de configuracao, nao o erro da API.
        $this->assertStringContainsString('não está configurada corretamente', $this->ultimaExecucao()->error);
    }
}
