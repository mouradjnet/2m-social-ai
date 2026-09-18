<?php

namespace Tests\Unit;

use App\Ai\Agents\AgentContext;
use App\Ai\Agents\CopywriterAgent;
use App\Ai\Exceptions\OutputRejectedException;
use LogicException;
use PHPUnit\Framework\TestCase;

class CopywriterAgentTest extends TestCase
{
    private function piece(array $overrides = []): array
    {
        return array_merge([
            'title' => 't',
            'caption' => 'c',
            'cta' => 'fale',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'pillar' => 'Educacao',
        ], $overrides);
    }

    private function outputWith(int $n): array
    {
        return ['pieces' => array_fill(0, $n, $this->piece())];
    }

    public function test_aceita_exatamente_cinco_pecas(): void
    {
        (new CopywriterAgent)->validate($this->outputWith(5));
        $this->expectNotToPerformAssertions();
    }

    public function test_rejeita_numero_diferente_de_cinco(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Esperado 5 peças, recebido 4.');

        (new CopywriterAgent)->validate($this->outputWith(4));
    }

    public function test_rejeita_format_fora_do_enum(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Formato inválido: banner.');

        $pieces = ['pieces' => [
            $this->piece(['format' => 'banner']),
            $this->piece(), $this->piece(), $this->piece(), $this->piece(),
        ]];

        (new CopywriterAgent)->validate($pieces);
    }

    public function test_rejeita_channel_fora_do_enum(): void
    {
        $this->expectException(OutputRejectedException::class);
        $this->expectExceptionMessage('Canal inválido: telegram.');

        $pieces = ['pieces' => [
            $this->piece(['channel' => 'telegram']),
            $this->piece(), $this->piece(), $this->piece(), $this->piece(),
        ]];

        (new CopywriterAgent)->validate($pieces);
    }

    public function test_instructions_respeitam_o_vocabulario_da_marca(): void
    {
        $instructions = (new CopywriterAgent)->instructions();

        $this->assertStringContainsString('forbidden_words', $instructions);
        $this->assertStringContainsString('required_words', $instructions);
    }

    public function test_schema_nao_usa_palavras_chave_recusadas_pela_api(): void
    {
        $schema = json_encode((new CopywriterAgent)->schema());

        foreach (['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as $kw) {
            $this->assertStringNotContainsString($kw, $schema);
        }
    }

    public function test_user_message_sem_estrategia_ativa_lanca_logicexception(): void
    {
        $context = new AgentContext(
            projectId: 1,
            projectName: 'X',
            segment: null,
            brandProfile: [],
            activeStrategy: null,
        );

        $this->expectException(LogicException::class);

        (new CopywriterAgent)->userMessage($context);
    }
}
