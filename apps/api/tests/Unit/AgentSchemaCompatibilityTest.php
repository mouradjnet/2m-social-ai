<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentRegistry;
use Tests\TestCase;

/**
 * CP-02: os schemas vao para `output_config.format` (saidas estruturadas), que so
 * aceita um subconjunto de JSON Schema. O SDK PHP NAO remove o que a API recusa
 * (Python e TypeScript removem): um `maxLength` num agente viraria 400 na primeira
 * chamada real — e o mock, que nao valida schema, nunca mostraria.
 *
 * A regra de tamanho/quantidade mora no `validate()` de cada agente, nao no schema.
 */
class AgentSchemaCompatibilityTest extends TestCase
{
    private const AGENTES = [
        'strategist', 'copywriter', 'social_media', 'reviewer', 'designer', 'seo',
        'analytics', 'rewriter', 'planner', 'repurposer', 'results',
    ];

    private const PROIBIDAS = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems',
        'minProperties', 'maxProperties',
    ];

    /** @return list<string> */
    private function problemas(array $schema, string $caminho = '$'): array
    {
        $achados = [];

        foreach (self::PROIBIDAS as $palavra) {
            if (array_key_exists($palavra, $schema)) {
                $achados[] = "{$caminho}.{$palavra}";
            }
        }

        $tipo = (array) ($schema['type'] ?? []);
        if (in_array('object', $tipo, true) && ($schema['additionalProperties'] ?? null) !== false) {
            $achados[] = "{$caminho}: objeto sem additionalProperties:false";
        }

        foreach ($schema as $chave => $valor) {
            if (is_array($valor)) {
                array_push($achados, ...$this->problemas($valor, "{$caminho}.{$chave}"));
            }
        }

        return $achados;
    }

    /** O detector acusa o que deve: sem isto, "nenhum problema" nao provaria nada. */
    public function test_o_detector_acusa_um_schema_incompativel(): void
    {
        $ruim = [
            'type' => 'object',
            'properties' => ['titulo' => ['type' => 'string', 'maxLength' => 80]],
        ];

        $this->assertEqualsCanonicalizing(
            ['$: objeto sem additionalProperties:false', '$.properties.titulo.maxLength'],
            $this->problemas($ruim),
        );
    }

    public function test_os_11_agentes_usam_so_o_que_as_saidas_estruturadas_aceitam(): void
    {
        $registro = app(AgentRegistry::class);

        foreach (self::AGENTES as $nome) {
            $this->assertSame([], $this->problemas($registro->get($nome)->schema()), "Agente {$nome}");
        }
    }
}
