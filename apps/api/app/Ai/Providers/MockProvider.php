<?php

namespace App\Ai\Providers;

/**
 * Desenvolvimento e CI. Nenhum teste automatizado chama a API real.
 *
 * A saida e deterministica e satisfaz o schema do agente. Contagens de token
 * sao plausiveis para que o calculo de custo e o orcamento sejam exercitados.
 */
class MockProvider implements LlmProvider
{
    public function generate(LlmRequest $request): LlmResponse
    {
        return new LlmResponse(
            output: $this->fixtureFor($request->schema),
            model: $request->model,
            inputTokens: 1_400,
            outputTokens: 900,
        );
    }

    private function fixtureFor(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        // O unico agente da Fase 3. Quando houver mais, cada Agent traz o proprio fixture.
        if (isset($properties['editorial_line'], $properties['pillars'])) {
            return [
                'title' => 'Estrategia editorial trimestral',
                'summary' => 'Posicionar a marca como referencia tecnica no segmento, '
                    .'equilibrando conteudo educativo e prova social.',
                'editorial_line' => 'Falar de resultado concreto antes de falar de produto. '
                    .'Toda peca responde a uma duvida real do publico.',
                'pillars' => [
                    ['name' => 'Educacao', 'weight' => 40, 'description' => 'Explicar o que o publico ainda nao sabe que precisa saber.'],
                    ['name' => 'Prova social', 'weight' => 35, 'description' => 'Casos, depoimentos e numeros verificaveis.'],
                    ['name' => 'Bastidores', 'weight' => 25, 'description' => 'Mostrar o processo e as pessoas por tras da marca.'],
                ],
            ];
        }

        // Copywriter: schema com a chave `pieces`.
        if (isset($properties['pieces'])) {
            $pilares = ['Educacao', 'Prova social', 'Bastidores', 'Educacao', 'Prova social'];

            return [
                'pieces' => array_map(fn (int $i) => [
                    'title' => "Peca {$i}",
                    'caption' => "Legenda da peca {$i}, no tom da marca.",
                    'cta' => 'Fale com a gente no WhatsApp.',
                    'hashtags' => ['#marca', '#conteudo'],
                    'format' => 'post',
                    'channel' => 'instagram',
                    'pillar' => $pilares[$i],
                ], range(0, 4)),
            ];
        }

        return [];
    }
}
