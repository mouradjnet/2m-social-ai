<?php

namespace App\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use App\Ai\Exceptions\LlmFailedException;
use App\Ai\Exceptions\LlmRefusedException;
use JsonException;

/**
 * Quatro coisas que a API do claude-opus-4-8 exige e que o desenho ingenuo erra:
 *
 * 1. `temperature`, `top_p` e `top_k` foram REMOVIDOS e retornam 400. Nao ha
 *    "copywriter com temperatura alta". Determinismo vem de `effort` baixo;
 *    variedade criativa vem do prompt.
 * 2. Omitir `thinking` roda SEM raciocinio. Nao e o default esperado.
 * 3. Prefill de assistant retorna 400. Formato vem de `outputConfig.format`.
 * 4. `stopReason === 'refusal'` chega como HTTP 200 com content vazio ou
 *    parcial. Ler `content[0]->text` direto quebra.
 */
class AnthropicProvider implements LlmProvider
{
    /**
     * `maxRetries` vai em CADA chamada, nao no Client: no SDK 0.7 o
     * `parseRequest()` monta um RequestOptions novo por requisicao, com os padroes
     * (maxRetries 2, timeout 600), e ele sobrescreve o que foi dado ao Client. O
     * transporte (com o timeout real) sobrevive porque nao tem valor padrao.
     */
    public function __construct(
        private readonly Client $client,
        private readonly int $maxRetries = 2,
    ) {}

    public function generate(LlmRequest $request): LlmResponse
    {
        try {
            $message = $this->client->messages->create(
                model: $request->model,
                maxTokens: $request->maxTokens,
                thinking: ['type' => 'adaptive'],
                outputConfig: [
                    'effort' => $request->effort,
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $request->schema,
                    ],
                ],
                system: [['type' => 'text', 'text' => $request->instructions]],
                messages: [['role' => 'user', 'content' => $request->userMessage]],
                requestOptions: RequestOptions::with(maxRetries: $this->maxRetries),
            );
        } catch (APIException $e) {
            // O SDK ja repetiu 429 e 5xx (`maxRetries`, ver AppServiceProvider);
            // chegar aqui e ter esgotado as tentativas.
            throw new LlmFailedException(self::logSafe($e), self::kindOf($e), $e);
        }

        if ($message->stopReason === 'refusal') {
            // `stop_details` nao existe no Message do anthropic-ai/sdk 0.7.0, e o
            // trait SdkModel lanca RuntimeException em propriedade nao modelada.
            // Sem a categoria, entao, ate o SDK expor o campo.
            throw new LlmRefusedException;
        }

        if ($message->stopReason === 'max_tokens') {
            throw new LlmFailedException('Resposta truncada: aumente max_tokens.', 'invalid_output');
        }

        return new LlmResponse(
            output: $this->decodeJson($message->content),
            model: $request->model,
            inputTokens: $message->usage->inputTokens ?? 0,
            outputTokens: $message->usage->outputTokens ?? 0,
            cacheReadTokens: $message->usage->cacheReadInputTokens ?? 0,
            cacheWriteTokens: $message->usage->cacheCreationInputTokens ?? 0,
        );
    }

    /**
     * Blocos de thinking podem vir antes do texto. Nunca assumir content[0].
     */
    private function decodeJson(array $content): array
    {
        foreach ($content as $block) {
            if ($block->type !== 'text') {
                continue;
            }

            try {
                $output = json_decode($block->text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new LlmFailedException('O modelo devolveu JSON invalido.', 'invalid_output', $e);
            }

            // `"texto"` e `42` sao JSON valido, mas nao o objeto do schema.
            if (! is_array($output)) {
                throw new LlmFailedException('O modelo devolveu JSON que nao e objeto.', 'invalid_output');
            }

            return $output;
        }

        throw new LlmFailedException('A resposta nao trouxe nenhum bloco de texto.', 'invalid_output');
    }

    /**
     * Mais especifico primeiro: 401/403 → quem configura o servidor; 429 e 529 →
     * esperar; conexao/timeout → tentar de novo. O SDK 0.7 embrulha o timeout do
     * transporte (Guzzle) numa APIConnectionException; o `previous` diz qual foi.
     */
    private static function kindOf(APIException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => 'auth',
            $e instanceof RateLimitException => 'rate_limited',
            $e instanceof InternalServerException && $e->status === 529 => 'overloaded',
            $e instanceof InternalServerException => 'server_error',
            $e instanceof BadRequestException => 'bad_request',
            $e instanceof APIConnectionException => str_contains(strtolower((string) $e->getPrevious()?->getMessage()), 'timed out')
                ? 'timeout'
                : 'connection',
            default => 'provider',
        };
    }

    /**
     * A mensagem do SDK traz o corpo da resposta (tipo e texto do erro da API),
     * nunca os cabecalhos, entao nao traz a chave. Ainda assim o log leva so o
     * status e o tipo, que e o que o operador precisa para decidir.
     */
    private static function logSafe(APIException $e): string
    {
        if ($e instanceof APIStatusException) {
            $corpo = json_decode((string) $e->response->getBody(), true);

            return sprintf('Anthropic HTTP %d (%s).', $e->status, $corpo['error']['type'] ?? 'sem tipo');
        }

        return 'Anthropic: falha de conexao ('.($e->getPrevious() ? $e->getPrevious()::class : 'sem causa').').';
    }
}
