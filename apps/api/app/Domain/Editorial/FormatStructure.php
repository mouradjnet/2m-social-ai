<?php

namespace App\Domain\Editorial;

/**
 * O ROTEIRO de cada formato (CP-03), gravado em `contents.structure`. E texto para
 * alguem produzir: um carrossel aqui nao e a arte dos slides, um Reel nao e o video.
 * A midia de verdade (imagens, video) continua na biblioteca (assets).
 *
 *   post      visual: a proposta visual da imagem
 *   carousel  visual + slides[{heading, body}]: capa, desenvolvimento, encerramento
 *   story     visual + screens[{text, visual, interaction}]: texto curto por tela
 *   reel      visual + hook + scenes[{description, on_screen_text, narration}]
 *             + production_notes
 *
 * Um schema so para todos (saidas estruturadas nao tem "se formato = X"); as regras
 * por formato ficam em problem(), conferidas no codigo.
 */
class FormatStructure
{
    public const EDITORIAL_FORMATS = ['post', 'carousel', 'reel', 'story'];

    public const MAX_STORY_TEXT = 120;

    public static function schema(): array
    {
        $texto = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['visual'],
            'properties' => [
                'visual' => ['type' => 'string', 'description' => 'Proposta visual: o que a imagem, a arte ou as cenas devem mostrar.'],
                'slides' => [
                    'type' => 'array',
                    'description' => 'Carrossel: capa, slides de conteudo e encerramento com o CTA.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['heading', 'body'],
                        'properties' => ['heading' => $texto, 'body' => $texto],
                    ],
                ],
                'screens' => [
                    'type' => 'array',
                    'description' => 'Stories: uma tela por item, texto curto.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'visual', 'interaction'],
                        'properties' => [
                            'text' => $texto,
                            'visual' => $texto,
                            'interaction' => ['type' => 'string', 'description' => 'Enquete, caixa de pergunta, quiz, link... ou vazio.'],
                        ],
                    ],
                ],
                'hook' => ['type' => 'string', 'description' => 'Reels: a frase ou imagem dos primeiros segundos.'],
                'scenes' => [
                    'type' => 'array',
                    'description' => 'Reels: cenas em ordem.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['description', 'on_screen_text', 'narration'],
                        'properties' => ['description' => $texto, 'on_screen_text' => $texto, 'narration' => $texto],
                    ],
                ],
                'production_notes' => ['type' => 'string', 'description' => 'Reels: duracao, enquadramento, trilha, o que gravar.'],
            ],
        ];
    }

    /** Instrucao comum aos agentes que escrevem pecas. */
    public static function instructions(): string
    {
        $max = self::MAX_STORY_TEXT;

        return <<<TXT
        `structure` e o ROTEIRO da peca para quem vai produzi-la — nao e a arte nem o
        video pronto. Sempre traga `visual` (a proposta visual). Conforme o `format`:
        - post: so `visual`.
        - carousel: `slides` com 3 a 10 itens. O primeiro e a capa (gancho), os do
          meio desenvolvem uma ideia por slide, o ultimo encerra com o CTA.
        - story: `screens` com 2 a 10 telas; `text` curto (ate {$max} caracteres);
          `interaction` quando fizer sentido (enquete, caixa de pergunta, quiz),
          senao vazio.
        - reel: `hook` (primeiros segundos), `scenes` com pelo menos 2 cenas
          (descricao, texto na tela e narracao sugerida) e `production_notes`.
        Nao invente produto, preco, promocao ou depoimento em nenhuma parte do roteiro.
        TXT;
    }

    /** O que esta errado na estrutura para este formato, ou null. */
    public static function problem(string $format, mixed $structure): ?string
    {
        if (! is_array($structure) || trim((string) ($structure['visual'] ?? '')) === '') {
            return "A peça ({$format}) veio sem proposta visual.";
        }

        return match ($format) {
            'carousel' => self::carrossel($structure['slides'] ?? []),
            'story' => self::stories($structure['screens'] ?? []),
            'reel' => self::reel($structure),
            default => null,
        };
    }

    private static function carrossel(array $slides): ?string
    {
        if (count($slides) < 3 || count($slides) > 10) {
            return 'Carrossel precisa de 3 a 10 slides (capa, conteúdo e encerramento), veio '.count($slides).'.';
        }

        foreach ($slides as $i => $slide) {
            if (trim((string) ($slide['heading'] ?? '')) === '' && trim((string) ($slide['body'] ?? '')) === '') {
                return 'O slide '.($i + 1).' do carrossel está vazio.';
            }
        }

        return null;
    }

    private static function stories(array $telas): ?string
    {
        if (count($telas) < 2 || count($telas) > 10) {
            return 'Stories precisa de 2 a 10 telas, veio '.count($telas).'.';
        }

        foreach ($telas as $i => $tela) {
            $texto = trim((string) ($tela['text'] ?? ''));

            if ($texto === '') {
                return 'A tela '.($i + 1).' dos Stories está sem texto.';
            }

            if (mb_strlen($texto) > self::MAX_STORY_TEXT) {
                return 'A tela '.($i + 1).' dos Stories passa de '.self::MAX_STORY_TEXT.' caracteres.';
            }
        }

        return null;
    }

    private static function reel(array $roteiro): ?string
    {
        if (trim((string) ($roteiro['hook'] ?? '')) === '') {
            return 'O roteiro do Reels veio sem gancho inicial.';
        }

        if (count($roteiro['scenes'] ?? []) < 2) {
            return 'O roteiro do Reels precisa de pelo menos 2 cenas.';
        }

        if (trim((string) ($roteiro['production_notes'] ?? '')) === '') {
            return 'O roteiro do Reels veio sem orientações de produção.';
        }

        return null;
    }
}
