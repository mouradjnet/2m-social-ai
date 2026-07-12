<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\ReviewerAgent;
use App\Ai\Exceptions\OutputRejectedException;
use LogicException;
use PHPUnit\Framework\TestCase;

class ReviewerAgentTest extends TestCase
{
    /** Duas pecas em revisao: 7 e 9. */
    private function context(): AgentContext
    {
        return new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: ['forbidden_words' => ['financiamento']],
            reviewContents: [
                ['id' => 7, 'title' => 'a', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram'],
                ['id' => 9, 'title' => 'b', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'reel', 'channel' => 'instagram'],
            ],
        );
    }

    private function saida(array $reviews): array
    {
        return ['reviews' => $reviews];
    }

    private function aprovada(int $id): array
    {
        return ['content_id' => $id, 'verdict' => 'pass', 'summary' => 'ok', 'violations' => []];
    }

    private function reprovada(int $id, ?array $violations = null): array
    {
        return [
            'content_id' => $id,
            'verdict' => 'fail',
            'summary' => 'tem problema',
            'violations' => $violations ?? [
                ['rule' => 'forbidden_words', 'excerpt' => 'financiamento facil', 'suggestion' => 'trocar por credito'],
            ],
        ];
    }

    public function test_aceita_uma_review_por_peca(): void
    {
        (new ReviewerAgent)->validate($this->saida([$this->aprovada(7), $this->reprovada(9)]), $this->context());

        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_lote_incompleto(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado 2 pecas revisadas, recebido 1.');

        (new ReviewerAgent)->validate($this->saida([$this->aprovada(7)]), $this->context());
    }

    public function test_rejeita_peca_fora_do_lote(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peca 42 nao esta em revisao.');

        (new ReviewerAgent)->validate($this->saida([$this->aprovada(7), $this->aprovada(42)]), $this->context());
    }

    public function test_rejeita_peca_revisada_duas_vezes(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peca 7 revisada mais de uma vez.');

        (new ReviewerAgent)->validate($this->saida([$this->aprovada(7), $this->aprovada(7)]), $this->context());
    }

    /** Um veredito que nao bate com a lista e saida incoerente, nao opiniao. */
    public function test_rejeita_fail_sem_violacao(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peca 9 reprovada sem apontar violacao.');

        (new ReviewerAgent)->validate(
            $this->saida([$this->aprovada(7), $this->reprovada(9, [])]),
            $this->context(),
        );
    }

    public function test_rejeita_pass_com_violacao(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peca 7 aprovada, mas com violacao apontada.');

        $incoerente = [
            'content_id' => 7,
            'verdict' => 'pass',
            'summary' => 'ok',
            'violations' => [['rule' => 'tom', 'excerpt' => 'e', 'suggestion' => 's']],
        ];

        (new ReviewerAgent)->validate($this->saida([$incoerente, $this->aprovada(9)]), $this->context());
    }

    public function test_validate_sem_contexto_lanca_logicexception(): void
    {
        $this->expectException(LogicException::class);

        (new ReviewerAgent)->validate($this->saida([]));
    }

    public function test_user_message_sem_lote_lanca_logicexception(): void
    {
        $context = new AgentContext(projectId: 1, projectName: 'X', segment: null, brandProfile: []);

        $this->expectException(LogicException::class);

        (new ReviewerAgent)->userMessage($context);
    }

    public function test_instructions_julgam_contra_o_vocabulario_da_marca(): void
    {
        $instructions = (new ReviewerAgent)->instructions();

        $this->assertStringContainsString('forbidden_words', $instructions);
        $this->assertStringContainsString('tone_of_voice', $instructions);
    }

    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new ReviewerAgent)->schema());

        foreach (['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as $kw) {
            $this->assertStringNotContainsString($kw, $schema);
        }
    }
}
