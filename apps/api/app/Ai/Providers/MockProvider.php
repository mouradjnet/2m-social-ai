<?php

namespace App\Ai\Providers;

use Carbon\CarbonImmutable;

/**
 * Desenvolvimento e CI. Nenhum teste automatizado chama a API real.
 *
 * A saida e deterministica e satisfaz o schema do agente. Os tokens sao ZERO, e
 * por consequencia o custo tambem: nenhuma chamada foi feita, nada foi pago.
 *
 * Antes o mock declarava 1400/900 tokens "plausiveis" para exercitar o calculo de
 * custo. O preco disso apareceu em producao com `AI_PROVIDER=mock`: cada geracao
 * debitava 3 centavos do orcamento mensal do workspace sem gastar nada — e esse
 * teto e a unica trava contra alguem torrar a chave. O calculo de custo ja e
 * coberto pelo AnthropicProviderTest, com os tokens que a API real devolve.
 */
class MockProvider implements LlmProvider
{
    public function generate(LlmRequest $request): LlmResponse
    {
        return new LlmResponse(
            output: $this->fixtureFor($request),
            model: $request->model,
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

        // Copywriter: schema com a chave `pieces`. O titulo carrega um sufixo unico e
        // o pilar sai do proprio <context>: o validate() agora recusa titulo repetido
        // e pilar fora do `target_pillar`, e um fixture constante falharia na segunda
        // geracao do mesmo projeto — em dev, nao em producao.
        if (isset($properties['pieces'])) {
            $context = $this->contextOf($request->userMessage);
            $alvo = $context['target_pillar'] ?? null;
            $daEstrategia = array_column($context['active_strategy']['pillars'] ?? [], 'name');
            $unico = substr(md5((string) count($context['existing_contents'] ?? [])), 0, 4);

            // O validate() agora exige que o pilar mais atrasado receba peca. Com mais
            // pilares que pecas, a distribuicao ciclica abaixo poderia nao alcanca-lo e
            // a geracao falharia em dev sem motivo. A primeira peca cobre o buraco.
            $atrasado = $alvo === null ? $this->pilarMaisAtrasado($context) : null;

            return [
                'pieces' => array_map(function (int $i) use ($alvo, $atrasado, $daEstrategia, $unico) {
                    $pilar = $alvo
                        ?? ($i === 0 ? $atrasado : null)
                        ?? ($daEstrategia[$i % max(count($daEstrategia), 1)] ?? 'Pilar');

                    return [
                        'title' => "Peca {$i} ({$unico})",
                        'caption' => "Legenda da peca {$i}, no tom da marca.",
                        'cta' => 'Fale com a gente no WhatsApp.',
                        'hashtags' => ['#marca', '#conteudo'],
                        'format' => 'post',
                        'channel' => 'instagram',
                        'pillar' => $pilar,
                    ];
                }, range(0, 4)),
            ];
        }

        // Rewriter: schema plano com `title` + `caption`, sem chave de lote. O
        // validate() recusa legenda IDENTICA a reprovada, entao o fixture nao pode ser
        // constante: le a peca do proprio <context> e devolve outra coisa.
        if (isset($properties['title'], $properties['caption']) && ! isset($properties['pieces'])) {
            $alvo = $this->contextOf($request->userMessage)['rewrite_target'] ?? [];

            return [
                'title' => $alvo['title'] ?? 'Peca reescrita',
                'caption' => 'Versao corrigida, sem o que o revisor apontou.',
                'cta' => $alvo['cta'] ?? 'Fale com a gente no WhatsApp.',
                'hashtags' => $alvo['hashtags'] ?? ['#marca'],
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

        // Reviewer: schema com a chave `reviews`. Como o social_media, precisa dos ids
        // reais. Reprova a primeira peca (para a UI de violacao ter o que mostrar em
        // dev) e aprova o resto — o validate() exige que veredito e lista batam.
        if (isset($properties['reviews'])) {
            $pecas = $this->contextOf($request->userMessage)['batch_contents'] ?? [];

            return [
                'reviews' => array_values(array_map(
                    fn (int $i, array $peca) => $i === 0
                        ? [
                            'content_id' => $peca['id'],
                            'verdict' => 'fail',
                            'summary' => 'A legenda foge do tom da marca.',
                            'violations' => [[
                                'rule' => 'tom de voz',
                                'excerpt' => 'Legenda da peca',
                                'suggestion' => 'Falar de resultado concreto antes de falar de produto.',
                            ]],
                        ]
                        : [
                            'content_id' => $peca['id'],
                            'verdict' => 'pass',
                            'summary' => 'Coerente com o perfil da marca.',
                            'violations' => [],
                        ],
                    array_keys($pecas),
                    $pecas,
                )),
            ];
        }

        // Designer: schema com a chave `designs`. Ids reais, como os dois acima.
        if (isset($properties['designs'])) {
            $pecas = $this->contextOf($request->userMessage)['batch_contents'] ?? [];

            return [
                'designs' => array_values(array_map(fn (array $peca) => [
                    'content_id' => $peca['id'],
                    'image_prompt' => 'A wide, softly lit workshop scene in emerald tones, '
                        .'shallow depth of field, no text.',
                ], $pecas)),
            ];
        }

        // Seo: schema com a chave `seo`. Ids reais, como os tres acima.
        if (isset($properties['seo'])) {
            $pecas = $this->contextOf($request->userMessage)['batch_contents'] ?? [];

            return [
                'seo' => array_values(array_map(fn (array $peca) => [
                    'content_id' => $peca['id'],
                    'title' => "Como {$peca['title']} resolve o problema do seu cliente",
                    'keywords' => ['palavra chave', 'termo de busca'],
                    'hashtags' => ['#marca', '#busca'],
                ], $pecas)),
            ];
        }

        // Analytics: schema com `score` e `insights`. Le o total do proprio <context>
        // para o relatorio do mock nao contradizer os numeros da tela.
        if (isset($properties['score'], $properties['insights'])) {
            $metrics = $this->contextOf($request->userMessage)['metrics'] ?? [];
            $total = $metrics['volume']['total'] ?? 0;

            return [
                'score' => 72,
                'summary' => "O calendario tem {$total} pecas e uma cadencia irregular.",
                'insights' => [
                    [
                        'title' => 'A cadencia tem buracos',
                        'detail' => 'Ha dias sem nenhuma peca na janela dos proximos 30 dias.',
                        'action' => 'Aprovar mais pecas e agendar o lote para fechar as lacunas.',
                    ],
                    [
                        'title' => 'Um pilar domina',
                        'detail' => 'A distribuicao real se afasta dos pesos da estrategia.',
                        'action' => 'Gerar um lote novo e priorizar os pilares em falta.',
                    ],
                ],
            ];
        }

        return [];
    }

    /** O pilar com o desvio mais negativo em `pillar_adherence`, ou null se nao ha. */
    private function pilarMaisAtrasado(array $context): ?string
    {
        $pior = null;

        foreach ($context['pillar_adherence']['pilares'] ?? [] as $pilar) {
            if ($pilar['desvio'] < 0 && ($pior === null || $pilar['desvio'] < $pior['desvio'])) {
                $pior = $pilar;
            }
        }

        return $pior['nome'] ?? null;
    }

    /** O userMessage carrega o AgentContext em JSON, entre <context> e </context>. */
    private function contextOf(string $userMessage): array
    {
        preg_match('/<context>(.*?)<\/context>/s', $userMessage, $m);

        return json_decode(trim($m[1] ?? '{}'), true) ?? [];
    }
}
