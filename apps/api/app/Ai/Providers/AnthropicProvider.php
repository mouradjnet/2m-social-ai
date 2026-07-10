<?php

namespace App\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
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
    public function __construct(private readonly Client $client) {}

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
            );
        } catch (APIException $e) {
            throw new LlmFailedException($e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            // `stop_details` nao existe no Message do anthropic-ai/sdk 0.7.0, e o
            // trait SdkModel lanca RuntimeException em propriedade nao modelada.
            // Sem a categoria, entao, ate o SDK expor o campo.
            throw new LlmRefusedException;
        }

        if ($message->stopReason === 'max_tokens') {
            throw new LlmFailedException('Resposta truncada: aumente max_tokens.');
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
                return json_decode($block->text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new LlmFailedException('O modelo devolveu JSON invalido.', previous: $e);
            }
        }

        throw new LlmFailedException('A resposta nao trouxe nenhum bloco de texto.');
    }
}
