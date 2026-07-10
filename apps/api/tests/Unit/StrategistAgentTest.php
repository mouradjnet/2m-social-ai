<?php

namespace Tests\Unit;

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

    private function outputWith(array $pillars): array
    {
        return [
            'title' => 't',
            'summary' => 's',
            'editorial_line' => 'e',
            'pillars' => $pillars,
        ];
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
}
