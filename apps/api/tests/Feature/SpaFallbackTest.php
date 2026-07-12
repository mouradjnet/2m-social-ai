<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Em producao o Laravel serve a propria SPA, e o catch-all do web.php e o que faz
 * um deep link funcionar. Um regex errado ali quebra em silencio o healthcheck ou a
 * API — e so em producao.
 */
class SpaFallbackTest extends TestCase
{
    public function test_o_healthcheck_nao_e_engolido_pelo_catch_all(): void
    {
        // O Render mede a saude do app por aqui. O framework registra o /up antes das
        // rotas web, entao o catch-all nao o alcanca — este teste guarda essa ordem.
        $this->get('/up')->assertOk()->assertSee('Application up');
    }

    public function test_rota_de_api_inexistente_devolve_404_json_e_nao_a_spa(): void
    {
        $this->getJson('/api/v1/nao-existe')->assertNotFound();
    }

    public function test_deep_link_do_frontend_nao_da_404(): void
    {
        // `/projects/4/content` nao e arquivo nem rota de API: quem responde e o
        // catch-all (com a SPA em producao; com a welcome em dev, onde nao ha build).
        $this->get('/projects/4/content')->assertOk();
    }
}
