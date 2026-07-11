<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\SocialMediaAgent;
use App\Ai\Exceptions\OutputRejectedException;
use LogicException;
use PHPUnit\Framework\TestCase;

class SocialMediaAgentTest extends TestCase
{
    /** Duas pecas aprovadas (7 e 9) e uma janela de 14 dias a partir de 01/08. */
    private function context(): AgentContext
    {
        return new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: [],
            activeStrategy: null,
            scheduleWindow: ['starts_on' => '2026-08-01', 'days' => 14],
            approvedContents: [
                ['id' => 7, 'title' => 'a', 'format' => 'post', 'channel' => 'instagram'],
                ['id' => 9, 'title' => 'b', 'format' => 'reel', 'channel' => 'instagram'],
            ],
        );
    }

    private function saida(array $entries): array
    {
        return ['schedule' => $entries];
    }

    private function entry(int $id, string $when): array
    {
        return ['content_id' => $id, 'scheduled_for' => $when, 'reason' => 'porque sim'];
    }

    public function test_aceita_uma_entrada_por_peca_dentro_da_janela(): void
    {
        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, '2026-08-03T10:00:00'),
                $this->entry(9, '2026-08-06T18:30:00'),
            ]),
            $this->context(),
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_peca_que_nao_esta_no_lote(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peca 42 nao esta entre as aprovadas.');

        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, '2026-08-03T10:00:00'),
                $this->entry(42, '2026-08-04T10:00:00'),
            ]),
            $this->context(),
        );
    }

    public function test_rejeita_peca_agendada_duas_vezes(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peca 7 agendada mais de uma vez.');

        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, '2026-08-03T10:00:00'),
                $this->entry(7, '2026-08-04T10:00:00'),
            ]),
            $this->context(),
        );
    }

    public function test_rejeita_lote_incompleto(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado 2 pecas agendadas, recebido 1.');

        (new SocialMediaAgent)->validate(
            $this->saida([$this->entry(7, '2026-08-03T10:00:00')]),
            $this->context(),
        );
    }

    public function test_rejeita_data_antes_da_janela(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('fora da janela');

        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, '2026-07-30T10:00:00'),
                $this->entry(9, '2026-08-06T10:00:00'),
            ]),
            $this->context(),
        );
    }

    /** A janela de 14 dias a partir de 01/08 termina no fim do dia 14/08. */
    public function test_rejeita_data_depois_da_janela(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('fora da janela');

        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, '2026-08-03T10:00:00'),
                $this->entry(9, '2026-08-15T10:00:00'),
            ]),
            $this->context(),
        );
    }

    public function test_aceita_o_ultimo_instante_da_janela(): void
    {
        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, '2026-08-01T00:00:00'),
                $this->entry(9, '2026-08-14T23:59:00'),
            ]),
            $this->context(),
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_data_que_nao_e_data(): void
    {
        $this->expectException(OutputRejectedException::class);

        (new SocialMediaAgent)->validate(
            $this->saida([
                $this->entry(7, 'quinta que vem'),
                $this->entry(9, '2026-08-06T10:00:00'),
            ]),
            $this->context(),
        );
    }

    public function test_validate_sem_contexto_lanca_logicexception(): void
    {
        $this->expectException(LogicException::class);

        (new SocialMediaAgent)->validate($this->saida([]));
    }

    public function test_user_message_sem_janela_lanca_logicexception(): void
    {
        $context = new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: [],
        );

        $this->expectException(LogicException::class);

        (new SocialMediaAgent)->userMessage($context);
    }

    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new SocialMediaAgent)->schema());

        foreach (['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as $kw) {
            $this->assertStringNotContainsString($kw, $schema);
        }
    }
}
