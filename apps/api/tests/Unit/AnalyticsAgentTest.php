<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\AnalyticsAgent;
use App\Ai\Exceptions\OutputRejectedException;
use LogicException;
use PHPUnit\Framework\TestCase;

class AnalyticsAgentTest extends TestCase
{
    private function context(): AgentContext
    {
        return new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: [],
            projectMetrics: ['volume' => ['total' => 5]],
        );
    }

    private function saida(array $overrides = []): array
    {
        return array_merge([
            'score' => 72,
            'summary' => 'O calendario esta saudavel, mas concentrado em um pilar.',
            'insights' => [[
                'title' => 'Educacao domina',
                'detail' => 'Tres de cada quatro pecas saem do mesmo pilar.',
                'action' => 'Gerar duas pecas de Prova social.',
            ]],
        ], $overrides);
    }

    public function test_aceita_um_relatorio_coerente(): void
    {
        (new AnalyticsAgent)->validate($this->saida(), $this->context());

        $this->expectNotToPerformAssertions();
    }

    /** O JSON Schema da API nao aceita minimum/maximum: a regra vive no validate. */
    public function test_rejeita_score_acima_de_100(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Pontuacao fora de 0-100: 140.');

        (new AnalyticsAgent)->validate($this->saida(['score' => 140]), $this->context());
    }

    public function test_rejeita_score_negativo(): void
    {
        $this->expectException(OutputRejectedException::class);

        (new AnalyticsAgent)->validate($this->saida(['score' => -1]), $this->context());
    }

    public function test_rejeita_relatorio_sem_insight(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Relatorio sem nenhum insight.');

        (new AnalyticsAgent)->validate($this->saida(['insights' => []]), $this->context());
    }

    /** Diagnostico sem proximo passo nao serve para nada. */
    public function test_rejeita_insight_sem_acao(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Insight sem acao: Educacao domina.');

        $semAcao = [[
            'title' => 'Educacao domina',
            'detail' => 'Tres de cada quatro pecas saem do mesmo pilar.',
            'action' => '  ',
        ]];

        (new AnalyticsAgent)->validate($this->saida(['insights' => $semAcao]), $this->context());
    }

    public function test_validate_sem_contexto_lanca_logicexception(): void
    {
        $this->expectException(LogicException::class);

        (new AnalyticsAgent)->validate($this->saida());
    }

    public function test_user_message_sem_metricas_lanca_logicexception(): void
    {
        $context = new AgentContext(projectId: 1, projectName: 'X', segment: null, brandProfile: []);

        $this->expectException(LogicException::class);

        (new AnalyticsAgent)->userMessage($context);
    }

    /** Nao ha dado de desempenho: o agente e proibido de falar do que nao mediu. */
    public function test_instructions_proibem_falar_de_desempenho(): void
    {
        $instructions = (new AnalyticsAgent)->instructions();

        $this->assertStringContainsString('desempenho', $instructions);
        $this->assertStringContainsString('metrics', $instructions);
    }

    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new AnalyticsAgent)->schema());

        foreach (['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as $kw) {
            $this->assertStringNotContainsString($kw, $schema);
        }
    }
}
