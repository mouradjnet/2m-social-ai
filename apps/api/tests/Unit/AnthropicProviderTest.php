<?php

namespace Tests\Unit;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Exceptions\LlmRefusedException;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\LlmRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Nenhum teste aqui fala com a API. O Client do SDK aceita um transporter
 * PSR-18, entao trocamos so o transporte e exercitamos o parsing real do SDK.
 *
 * Isso importa porque os erros do Opus 4.8 nao chegam como excecao do SDK: uma
 * recusa dos classificadores vem como HTTP 200 com `stop_reason: refusal`.
 */
class AnthropicProviderTest extends TestCase
{
    private function providerReturning(array $payload): AnthropicProvider
    {
        $transporter = new class($payload) implements ClientInterface
        {
            public function __construct(private readonly array $payload) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->payload),
                );
            }
        };

        return new AnthropicProvider(new Client(
            apiKey: 'chave-de-teste',
            requestOptions: RequestOptions::with(transporter: $transporter),
        ));
    }

    private function request(): LlmRequest
    {
        return new LlmRequest(
            model: 'claude-opus-4-8',
            instructions: 'instrucoes',
            userMessage: 'mensagem',
            schema: ['type' => 'object'],
        );
    }

    /** Molde de uma resposta da Messages API, com os campos que o SDK exige. */
    private function message(array $overrides): array
    {
        return array_merge([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-4-8',
            'content' => [],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ], $overrides);
    }

    /**
     * Regressao: a versao anterior lia `$message->stopDetails`, propriedade que
     * o anthropic-ai/sdk 0.7.0 nao modela. O trait SdkModel lanca RuntimeException
     * nesse acesso, entao a recusa chegava ao usuario como falha generica ("tente
     * novamente"), convidando a repetir um prompt que seria recusado de novo.
     */
    public function test_recusa_vira_llm_refused_exception_e_nao_erro_generico(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [],
            'stop_reason' => 'refusal',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 0],
        ]));

        $this->expectException(LlmRefusedException::class);
        $this->expectExceptionMessage('O modelo recusou esta requisição.');

        $provider->generate($this->request());
    }

    public function test_resposta_truncada_vira_llm_failed_exception(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [['type' => 'text', 'text' => '{"parcial":']],
            'stop_reason' => 'max_tokens',
        ]));

        $this->expectException(LlmFailedException::class);
        $this->expectExceptionMessage('Resposta truncada');

        $provider->generate($this->request());
    }

    /**
     * Com thinking adaptativo, o bloco de raciocinio vem antes do texto. Ler
     * `content[0]` direto devolveria o bloco errado.
     */
    public function test_ignora_o_bloco_de_thinking_e_le_o_json_do_bloco_de_texto(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => '{"title":"Estrategia"}'],
            ],
            'usage' => ['input_tokens' => 1400, 'output_tokens' => 900],
        ]));

        $response = $provider->generate($this->request());

        $this->assertSame(['title' => 'Estrategia'], $response->output);
        $this->assertSame(1400, $response->inputTokens);
        $this->assertSame(900, $response->outputTokens);
    }

    public function test_json_invalido_vira_llm_failed_exception(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [['type' => 'text', 'text' => 'isto nao e json']],
        ]));

        $this->expectException(LlmFailedException::class);
        $this->expectExceptionMessage('JSON invalido');

        $provider->generate($this->request());
    }

    public function test_resposta_sem_bloco_de_texto_vira_llm_failed_exception(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']],
        ]));

        $this->expectException(LlmFailedException::class);
        $this->expectExceptionMessage('nenhum bloco de texto');

        $provider->generate($this->request());
    }

    /**
     * A tabela de precos e o que alimenta o orcamento mensal do workspace.
     * Opus 4.8: US$ 5 de entrada e US$ 25 de saida por milhao de tokens.
     */
    public function test_custo_arredonda_para_cima_com_a_tabela_do_opus_4_8(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [['type' => 'text', 'text' => '{}']],
            'usage' => ['input_tokens' => 1400, 'output_tokens' => 900],
        ]));

        $pricing = ['claude-opus-4-8' => [
            'input' => 500, 'output' => 2500, 'cache_read' => 50, 'cache_write' => 625,
        ]];

        // 1400/1e6*500 + 900/1e6*2500 = 0,70 + 2,25 = 2,95 centavos -> 3
        $this->assertSame(3, $provider->generate($this->request())->costCents($pricing));
    }

    public function test_modelo_desconhecido_na_tabela_custa_zero(): void
    {
        $provider = $this->providerReturning($this->message([
            'content' => [['type' => 'text', 'text' => '{}']],
            'usage' => ['input_tokens' => 1400, 'output_tokens' => 900],
        ]));

        $this->assertSame(0, $provider->generate($this->request())->costCents([]));
    }
}
