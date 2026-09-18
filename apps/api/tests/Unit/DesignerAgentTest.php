<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\DesignerAgent;
use App\Ai\Exceptions\OutputRejectedException;
use LogicException;
use PHPUnit\Framework\TestCase;

class DesignerAgentTest extends TestCase
{
    /** Duas pecas em producao: 7 e 9. */
    private function context(): AgentContext
    {
        return new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: ['colors' => ['#006c49']],
            batchContents: [
                ['id' => 7, 'title' => 'a', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'post', 'channel' => 'instagram'],
                ['id' => 9, 'title' => 'b', 'caption' => 'c', 'cta' => 'x', 'hashtags' => [], 'format' => 'reel', 'channel' => 'instagram'],
            ],
            batchStatus: 'production',
        );
    }

    private function saida(array $designs): array
    {
        return ['designs' => $designs];
    }

    private function design(int $id): array
    {
        return ['content_id' => $id, 'image_prompt' => 'A wide shot of a workshop, emerald tones.'];
    }

    public function test_aceita_um_design_por_peca(): void
    {
        (new DesignerAgent)->validate($this->saida([$this->design(7), $this->design(9)]), $this->context());

        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_lote_incompleto(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado 2 prompts de imagem, recebido 1.');

        (new DesignerAgent)->validate($this->saida([$this->design(7)]), $this->context());
    }

    public function test_rejeita_peca_fora_do_lote(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peça 42 não está em produção.');

        (new DesignerAgent)->validate($this->saida([$this->design(7), $this->design(42)]), $this->context());
    }

    public function test_rejeita_peca_desenhada_duas_vezes(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Peça 7 desenhada mais de uma vez.');

        (new DesignerAgent)->validate($this->saida([$this->design(7), $this->design(7)]), $this->context());
    }

    public function test_validate_sem_contexto_lanca_logicexception(): void
    {
        $this->expectException(LogicException::class);

        (new DesignerAgent)->validate($this->saida([]));
    }

    public function test_user_message_sem_lote_lanca_logicexception(): void
    {
        $context = new AgentContext(projectId: 1, projectName: 'X', segment: null, brandProfile: []);

        $this->expectException(LogicException::class);

        (new DesignerAgent)->userMessage($context);
    }

    /** O prompt e colado num gerador de imagem: sai em ingles, respeitando a paleta. */
    public function test_instructions_pedem_ingles_paleta_e_nada_de_texto_na_imagem(): void
    {
        $instructions = (new DesignerAgent)->instructions();

        $this->assertStringContainsString('INGLES', $instructions);
        $this->assertStringContainsString('colors', $instructions);
        $this->assertStringContainsString('texto', $instructions);
    }

    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new DesignerAgent)->schema());

        foreach (['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as $kw) {
            $this->assertStringNotContainsString($kw, $schema);
        }
    }
}
