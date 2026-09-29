<?php

namespace Tests\Unit;

use App\Domain\Editorial\BrandRules;
use App\Domain\Editorial\FormatStructure;
use PHPUnit\Framework\TestCase;

/** CP-03: as regras editoriais conferidas no codigo, sem IA. */
class EditorialRulesTest extends TestCase
{
    // --- Formatos: roteiro, nao midia ---------------------------------------------

    public function test_feed_precisa_de_proposta_visual(): void
    {
        $this->assertNull(FormatStructure::problem('post', ['visual' => 'Foto da rotina de autocuidado.']));
        $this->assertStringContainsString('proposta visual', FormatStructure::problem('post', ['visual' => ' ']));
        $this->assertStringContainsString('proposta visual', FormatStructure::problem('post', null));
    }

    public function test_carrossel_tem_capa_conteudo_e_encerramento(): void
    {
        $slide = ['heading' => 'h', 'body' => 'b'];

        $this->assertNull(FormatStructure::problem('carousel', ['visual' => 'v', 'slides' => [$slide, $slide, $slide]]));
        $this->assertStringContainsString('3 a 10 slides', FormatStructure::problem('carousel', ['visual' => 'v', 'slides' => [$slide, $slide]]));
        $this->assertStringContainsString('3 a 10 slides', FormatStructure::problem('carousel', ['visual' => 'v', 'slides' => array_fill(0, 11, $slide)]));
        $this->assertStringContainsString('slide 2', FormatStructure::problem('carousel', ['visual' => 'v', 'slides' => [$slide, ['heading' => '', 'body' => ' '], $slide]]));
    }

    public function test_stories_tem_telas_com_texto_curto(): void
    {
        $tela = ['text' => 'Você já fez seu autoexame?', 'visual' => 'v', 'interaction' => 'enquete'];

        $this->assertNull(FormatStructure::problem('story', ['visual' => 'v', 'screens' => [$tela, $tela]]));
        $this->assertStringContainsString('2 a 10 telas', FormatStructure::problem('story', ['visual' => 'v', 'screens' => [$tela]]));
        $this->assertStringContainsString('sem texto', FormatStructure::problem('story', ['visual' => 'v', 'screens' => [$tela, ['text' => '', 'visual' => 'v', 'interaction' => '']]]));
        $longo = ['text' => str_repeat('a', FormatStructure::MAX_STORY_TEXT + 1), 'visual' => 'v', 'interaction' => ''];
        $this->assertStringContainsString('passa de', FormatStructure::problem('story', ['visual' => 'v', 'screens' => [$tela, $longo]]));
    }

    public function test_reel_e_roteiro_com_gancho_cenas_e_producao(): void
    {
        $cena = ['description' => 'd', 'on_screen_text' => 't', 'narration' => 'n'];
        $ok = ['visual' => 'v', 'hook' => 'Gancho', 'scenes' => [$cena, $cena], 'production_notes' => '9:16, 20 s'];

        $this->assertNull(FormatStructure::problem('reel', $ok));
        $this->assertStringContainsString('gancho', FormatStructure::problem('reel', [...$ok, 'hook' => '']));
        $this->assertStringContainsString('2 cenas', FormatStructure::problem('reel', [...$ok, 'scenes' => [$cena]]));
        $this->assertStringContainsString('produção', FormatStructure::problem('reel', [...$ok, 'production_notes' => '']));
    }

    // --- Oferta e expressoes proibidas ---------------------------------------------

    public function test_marcadores_nao_contam_como_oferta(): void
    {
        $this->assertFalse(BrandRules::hasRealOffer([]));
        $this->assertFalse(BrandRules::hasRealOffer(['products' => ['A CONFIRMAR'], 'services' => ['Nenhum — perfil educativo', '  ']]));
        $this->assertTrue(BrandRules::hasRealOffer(['products' => ['A CONFIRMAR', 'Hidratante corporal 200 ml']]));
        $this->assertTrue(BrandRules::hasRealOffer(['services' => ['Consultoria de autocuidado online']]));
    }

    public function test_expressao_proibida_ignora_caixa_e_acento_e_respeita_palavra_inteira(): void
    {
        $proibidas = ['cura garantida', 'milagre'];

        $this->assertSame(['cura garantida'], BrandRules::forbiddenIn('Com CURA GARANTÍDA em 7 dias', $proibidas));
        $this->assertSame(['milagre'], BrandRules::forbiddenIn('Um verdadeiro milagre!', $proibidas));
        $this->assertSame([], BrandRules::forbiddenIn('Milagres do cotidiano e curas', $proibidas));
    }

    public function test_texto_da_peca_inclui_o_roteiro(): void
    {
        $peca = ['title' => 't', 'caption' => 'c', 'structure' => ['slides' => [['heading' => 'h', 'body' => 'efeito milagroso']]]];

        $this->assertSame(['efeito milagroso'], BrandRules::forbiddenIn(BrandRules::pieceText($peca), ['efeito milagroso']));
    }
}
