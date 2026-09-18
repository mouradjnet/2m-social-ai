<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\SeoAgent;
use App\Ai\Exceptions\OutputRejectedException;
use LogicException;
use PHPUnit\Framework\TestCase;

class SeoAgentTest extends TestCase
{
    /** Duas pecas em producao: 7 (instagram) e 9 (blog). */
    private function context(): AgentContext
    {
        return new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: ['forbidden_words' => ['financiamento']],
            batchContents: [
                ['id' => 7, 'title' => 'a', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram'],
                ['id' => 9, 'title' => 'b', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'article', 'channel' => 'blog'],
            ],
            batchStatus: 'production',
        );
    }

    private function saida(array $seo): array
    {
        return ['seo' => $seo];
    }

    private function bloco(int $id): array
    {
        return [
            'content_id' => $id,
            'title' => 'Titulo otimizado',
            'keywords' => ['dentista', 'clareamento'],
            'hashtags' => ['#odonto'],
        ];
    }

    public function test_aceita_um_bloco_por_peca(): void
    {
        (new SeoAgent)->validate($this->saida([$this->bloco(7), $this->bloco(9)]), $this->context());

        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_lote_incompleto(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado 2 peças otimizadas, recebido 1.');

        (new SeoAgent)->validate($this->saida([$this->bloco(7)]), $this->context());
    }

    public function test_rejeita_peca_fora_do_lote(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peça 42 não está em produção.');

        (new SeoAgent)->validate($this->saida([$this->bloco(7), $this->bloco(42)]), $this->context());
    }

    public function test_rejeita_peca_otimizada_duas_vezes(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peça 7 otimizada mais de uma vez.');

        (new SeoAgent)->validate($this->saida([$this->bloco(7), $this->bloco(7)]), $this->context());
    }

    public function test_validate_sem_contexto_lanca_logicexception(): void
    {
        $this->expectException(LogicException::class);

        (new SeoAgent)->validate($this->saida([]));
    }

    public function test_user_message_sem_lote_lanca_logicexception(): void
    {
        $context = new AgentContext(projectId: 1, projectName: 'X', segment: null, brandProfile: []);

        $this->expectException(LogicException::class);

        (new SeoAgent)->userMessage($context);
    }

    /** O titulo bom depende do canal, e a regra de vocabulario da marca vale aqui. */
    public function test_instructions_falam_de_canal_e_de_vocabulario(): void
    {
        $instructions = (new SeoAgent)->instructions();

        $this->assertStringContainsString('canal', $instructions);
        $this->assertStringContainsString('forbidden_words', $instructions);
    }

    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new SeoAgent)->schema());

        foreach (['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as $kw) {
            $this->assertStringNotContainsString($kw, $schema);
        }
    }
}
