<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\StrategistAgent;
use App\Ai\Exceptions\OutputRejectedException;
use PHPUnit\Framework\TestCase;

/**
 * O JSON Schema da API nao expressa "de 3 a 5 pilares" nem "a soma dos pesos e
 * 100" — a API rejeita minItems, maximum e afins. Essas regras viram prosa nas
 * instrucoes e validacao no servidor. Se validate() afrouxar, o modelo passa a
 * gravar estrategias incoerentes sem que nada reclame.
 */
class StrategistAgentTest extends TestCase
{
    private function pillar(string $name, int $weight): array
    {
        return ['name' => $name, 'weight' => $weight, 'description' => 'd'];
    }

    private function outputWith(array $pillars, array $diretrizes = []): array
    {
        return [
            'title' => 't',
            'summary' => 's',
            'editorial_line' => 'e',
            'pillars' => $pillars,
            'guidelines' => [...[
                'objectives' => ['Educar', 'Crescer a audiência'],
                'themes' => ['Autocuidado', 'Rotina'],
                'formats' => ['post', 'carousel'],
                'weekly_frequency' => 3,
                'content_mix' => ['educational' => 70, 'institutional' => 30, 'commercial' => 0],
            ], ...$diretrizes],
        ];
    }

    private function pilaresValidos(): array
    {
        return [$this->pillar('a', 40), $this->pillar('b', 35), $this->pillar('c', 25)];
    }

    private function contexto(array $perfil): AgentContext
    {
        return new AgentContext(projectId: 1, projectName: 'p', segment: null, brandProfile: $perfil);
    }

    private function rejeita(array $output, ?AgentContext $contexto, string $trecho): void
    {
        try {
            (new StrategistAgent)->validate($output, $contexto);
        } catch (OutputRejectedException $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());

            return;
        }

        $this->fail("Esperava rejeição contendo \"{$trecho}\".");
    }

    public function test_aceita_tres_pilares_somando_cem(): void
    {
        $agent = new StrategistAgent;

        $agent->validate($this->outputWith([
            $this->pillar('Educacao', 40),
            $this->pillar('Prova social', 35),
            $this->pillar('Bastidores', 25),
        ]));

        $this->expectNotToPerformAssertions();
    }

    public function test_aceita_cinco_pilares_somando_cem(): void
    {
        $agent = new StrategistAgent;

        $agent->validate($this->outputWith([
            $this->pillar('a', 20),
            $this->pillar('b', 20),
            $this->pillar('c', 20),
            $this->pillar('d', 20),
            $this->pillar('e', 20),
        ]));

        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_menos_de_tres_pilares(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado de 3 a 5 pilares, recebido 2.');

        (new StrategistAgent)->validate($this->outputWith([
            $this->pillar('a', 50),
            $this->pillar('b', 50),
        ]));
    }

    public function test_rejeita_mais_de_cinco_pilares(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado de 3 a 5 pilares, recebido 6.');

        (new StrategistAgent)->validate($this->outputWith([
            $this->pillar('a', 20),
            $this->pillar('b', 20),
            $this->pillar('c', 20),
            $this->pillar('d', 20),
            $this->pillar('e', 10),
            $this->pillar('f', 10),
        ]));
    }

    public function test_rejeita_soma_diferente_de_cem(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('A soma dos pesos deve ser 100, recebido 99.');

        (new StrategistAgent)->validate($this->outputWith([
            $this->pillar('a', 40),
            $this->pillar('b', 35),
            $this->pillar('c', 24),
        ]));
    }

    public function test_rejeita_peso_fora_do_intervalo(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peso fora de 1..100: 0.');

        (new StrategistAgent)->validate($this->outputWith([
            $this->pillar('a', 0),
            $this->pillar('b', 50),
            $this->pillar('c', 50),
        ]));
    }

    /**
     * A API rejeita essas palavras-chave no schema com 400. Elas sao faceis de
     * reintroduzir sem querer, e o erro so aparece na primeira chamada real.
     */
    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new StrategistAgent)->schema());

        foreach (['minLength', 'maxLength', 'minItems', 'maxItems', 'minimum', 'maximum', 'multipleOf'] as $keyword) {
            $this->assertStringNotContainsString($keyword, $schema);
        }
    }

    public function test_schema_fecha_propriedades_adicionais(): void
    {
        $schema = (new StrategistAgent)->schema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['pillars']['items']['additionalProperties']);
    }

    /**
     * O AgentContext envia forbidden_words/required_words no perfil, mas o
     * modelo so os respeita se o prompt mandar. Sem esta regra, dar tela a
     * esses campos nao muda a saida — o contrato fica pela metade.
     */
    public function test_instructions_mandam_respeitar_o_vocabulario_da_marca(): void
    {
        $instructions = (new StrategistAgent)->instructions();

        $this->assertStringContainsString('forbidden_words', $instructions);
        $this->assertStringContainsString('required_words', $instructions);
    }

    // --- CP-03: diretrizes editoriais ------------------------------------------------

    public function test_mistura_que_nao_soma_cem_e_rejeitada(): void
    {
        $this->rejeita(
            $this->outputWith($this->pilaresValidos(), ['content_mix' => ['educational' => 60, 'institutional' => 30, 'commercial' => 0]]),
            null,
            'deve somar 100',
        );
    }

    public function test_frequencia_fora_do_limite_e_rejeitada(): void
    {
        $this->rejeita($this->outputWith($this->pilaresValidos(), ['weekly_frequency' => 0]), null, 'Frequência semanal');
        $this->rejeita($this->outputWith($this->pilaresValidos(), ['weekly_frequency' => 15]), null, 'Frequência semanal');
    }

    public function test_sem_objetivos_ou_sem_temas_e_rejeitada(): void
    {
        $this->rejeita($this->outputWith($this->pilaresValidos(), ['objectives' => []]), null, 'objetivos');
        $this->rejeita($this->outputWith($this->pilaresValidos(), ['themes' => ['  ']]), null, 'sem temas');
    }

    /** Perfil incompleto (sem oferta): comercial tem de ser 0, nada de vender o que nao existe. */
    public function test_comercial_sem_produto_nem_servico_e_rejeitado(): void
    {
        $comercial = ['content_mix' => ['educational' => 50, 'institutional' => 30, 'commercial' => 20]];

        $this->rejeita($this->outputWith($this->pilaresValidos(), $comercial), $this->contexto([]), 'Conteúdo comercial sem produto');
        $this->rejeita(
            $this->outputWith($this->pilaresValidos(), $comercial),
            $this->contexto(['products' => ['A CONFIRMAR'], 'services' => ['Nenhum — perfil educativo']]),
            'Conteúdo comercial sem produto',
        );
    }

    /** Perfil completo, com oferta real: comercial pode entrar. */
    public function test_comercial_com_oferta_real_e_aceito(): void
    {
        (new StrategistAgent)->validate(
            $this->outputWith($this->pilaresValidos(), ['content_mix' => ['educational' => 50, 'institutional' => 30, 'commercial' => 20]]),
            $this->contexto(['products' => ['Sabonete íntimo 200 ml'], 'services' => []]),
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_estrategia_com_expressao_proibida_e_rejeitada(): void
    {
        $saida = $this->outputWith($this->pilaresValidos());
        $saida['editorial_line'] = 'Mostrar resultados com Cura Garantida.';

        $this->rejeita($saida, $this->contexto(['forbidden_words' => ['cura garantida']]), 'cura garantida');
    }
}
