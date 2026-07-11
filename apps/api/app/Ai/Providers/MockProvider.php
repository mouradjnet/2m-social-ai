<?php

namespace App\Ai\Providers;

use Carbon\CarbonImmutable;

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
            output: $this->fixtureFor($request),
            model: $request->model,
            inputTokens: 1_400,
            outputTokens: 900,
        );
    }

    private function fixtureFor(LlmRequest $request): array
    {
        $properties = $request->schema['properties'] ?? [];

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

        // Social media: schema com a chave `schedule`. Diferente dos outros dois, este
        // fixture nao pode ser constante: os `content_id` precisam ser os das pecas
        // aprovadas de verdade, senao o validate() do agente rejeita a saida e a
        // geracao falha em dev. Le o lote e a janela do proprio <context>.
        if (isset($properties['schedule'])) {
            $context = $this->contextOf($request->userMessage);
            $inicio = CarbonImmutable::parse($context['schedule_window']['starts_on'])->setTime(10, 0);
            $dias = (int) $context['schedule_window']['days'];

            return [
                'schedule' => array_values(array_map(
                    fn (int $i, array $peca) => [
                        'content_id' => $peca['id'],
                        // Uma por dia. Se houver mais pecas que dias, volta ao inicio
                        // da janela: o mock nao precisa ser esperto, precisa ser valido.
                        'scheduled_for' => $inicio->addDays($i % max($dias, 1))->toIso8601String(),
                        'reason' => 'Distribuida pela janela.',
                    ],
                    array_keys($context['approved_contents']),
                    $context['approved_contents'],
                )),
            ];
        }

        return [];
    }

    /** O userMessage carrega o AgentContext em JSON, entre <context> e </context>. */
    private function contextOf(string $userMessage): array
    {
        preg_match('/<context>(.*?)<\/context>/s', $userMessage, $m);

        return json_decode(trim($m[1] ?? '{}'), true) ?? [];
    }
}
