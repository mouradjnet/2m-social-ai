<?php

namespace App\Ai\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Images API (`POST /v1/images/generations`), resposta em base64 — nao ha
 * URL temporaria para buscar depois. A chave nunca entra em log nem em mensagem.
 */
class OpenAiImageProvider implements ImageProvider
{
    private const URL = 'https://api.openai.com/v1/images/generations';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $size,
        private readonly string $quality,
        private readonly int $timeoutSeconds,
        private readonly int $costCents,
    ) {}

    public function generate(string $prompt): GeneratedImage
    {
        try {
            $resposta = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post(self::URL, [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'size' => $this->size,
                    'quality' => $this->quality,
                    'n' => 1,
                ]);
        } catch (ConnectionException) {
            throw new ImageGenerationException('O gerador de imagens não respondeu. Tente de novo.');
        }

        if ($resposta->failed()) {
            $codigo = (string) $resposta->json('error.code');

            // Filtro de conteudo: o prompt e que tem de mudar.
            if (in_array($codigo, ['moderation_blocked', 'content_policy_violation'], true)) {
                throw new ImageGenerationException(
                    'O gerador recusou este prompt pelas regras de conteúdo dele. Ajuste o prompt da imagem e tente de novo.',
                    'refused',
                );
            }

            throw new ImageGenerationException(sprintf('O gerador de imagens falhou (HTTP %d).', $resposta->status()));
        }

        $base64 = $resposta->json('data.0.b64_json');
        $bytes = is_string($base64) ? base64_decode($base64, true) : false;

        if ($bytes === false || $bytes === '') {
            throw new ImageGenerationException('O gerador de imagens respondeu sem imagem.');
        }

        return new GeneratedImage($bytes, $this->costCents);
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }
}
