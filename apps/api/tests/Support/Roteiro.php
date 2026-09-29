<?php

namespace Tests\Support;

/**
 * CP-03: toda peca escrita por agente traz `structure`, validada por formato
 * (FormatStructure::problem). Nos testes que montam a saida na mao e testam OUTRA
 * regra (pilar, titulo repetido, copia), este roteiro satisfaz qualquer formato.
 */
final class Roteiro
{
    public static function valido(): array
    {
        return [
            'visual' => 'Foto clara, luz natural.',
            'slides' => [
                ['heading' => 'Capa', 'body' => 'A pergunta.'],
                ['heading' => 'Meio', 'body' => 'A resposta.'],
                ['heading' => 'Fim', 'body' => 'Salve.'],
            ],
            'screens' => [
                ['text' => 'Tela 1', 'visual' => 'v', 'interaction' => ''],
                ['text' => 'Tela 2', 'visual' => 'v', 'interaction' => 'enquete'],
            ],
            'hook' => 'Gancho.',
            'scenes' => [
                ['description' => 'Cena 1', 'on_screen_text' => 't', 'narration' => 'n'],
                ['description' => 'Cena 2', 'on_screen_text' => 't', 'narration' => 'n'],
            ],
            'production_notes' => 'Vertical, 20 s.',
        ];
    }
}
