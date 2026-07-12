<?php

namespace Tests\Feature;

use App\Domain\Analytics\Metrics;
use App\Models\AiRun;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $workspace = Workspace::factory()->create();
        $this->project = Project::factory()->create(['workspace_id' => $workspace->id]);
    }

    private function content(array $overrides = []): Content
    {
        return Content::create(array_merge([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'title' => 'Peca',
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => [],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => 'idea',
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ], $overrides));
    }

    private function activeStrategy(array $pillars): void
    {
        Strategy::create([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'title' => 'E',
            'summary' => 's',
            'editorial_line' => 'e',
            'pillars' => $pillars,
            'status' => 'active',
        ]);
    }

    public function test_volume_conta_as_pecas_por_status(): void
    {
        $this->content(['status' => 'idea']);
        $this->content(['status' => 'idea']);
        $this->content(['status' => 'approved']);

        $metrics = Metrics::for($this->project);

        $this->assertSame(3, $metrics['volume']['total']);
        $this->assertSame(2, $metrics['volume']['por_status']['idea']);
        $this->assertSame(1, $metrics['volume']['por_status']['approved']);
    }

    /**
     * O coracao do relatorio: o que a estrategia pediu contra o que foi feito.
     * Estrategia pede 50/50; entregamos 3 de Educacao e 1 de Prova social = 75/25.
     */
    public function test_aderencia_compara_o_pedido_com_o_entregue(): void
    {
        $this->activeStrategy([
            ['name' => 'Educacao', 'weight' => 50, 'description' => 'd'],
            ['name' => 'Prova social', 'weight' => 50, 'description' => 'd'],
        ]);

        $this->content(['pillar' => 'Educacao']);
        $this->content(['pillar' => 'Educacao']);
        $this->content(['pillar' => 'Educacao']);
        $this->content(['pillar' => 'Prova social']);

        $aderencia = Metrics::for($this->project)['aderencia'];

        $educacao = collect($aderencia['pilares'])->firstWhere('nome', 'Educacao');
        $this->assertSame(50, $educacao['peso_pedido']);
        $this->assertSame(75, $educacao['peso_real']);
        $this->assertSame(25, $educacao['desvio']);

        $prova = collect($aderencia['pilares'])->firstWhere('nome', 'Prova social');
        $this->assertSame(-25, $prova['desvio']);
    }

    /** Peca antiga nao tem pilar. O relatorio conta isso em vez de fingir. */
    public function test_pecas_sem_pilar_sao_contadas_a_parte(): void
    {
        $this->activeStrategy([['name' => 'Educacao', 'weight' => 100, 'description' => 'd']]);

        $this->content(['pillar' => 'Educacao']);
        $this->content(['pillar' => null]);
        $this->content(['pillar' => null]);

        $aderencia = Metrics::for($this->project)['aderencia'];

        $this->assertSame(2, $aderencia['sem_pilar']);
        // O peso real e sobre as pecas COM pilar: 1 de 1 = 100%.
        $this->assertSame(100, collect($aderencia['pilares'])->firstWhere('nome', 'Educacao')['peso_real']);
    }

    public function test_sem_estrategia_ativa_nao_ha_aderencia(): void
    {
        $this->content(['pillar' => 'Educacao']);

        $this->assertNull(Metrics::for($this->project)['aderencia']);
    }

    public function test_cadencia_conta_dias_cobertos_e_a_maior_lacuna(): void
    {
        // Agendadas para daqui a 1, 2 e 10 dias. Entre o dia 2 e o dia 10 ficam 7 dias
        // vazios (3 a 9): a lacuna e o buraco, nao a distancia.
        $this->content(['status' => 'scheduled', 'scheduled_for' => now()->addDays(1)]);
        $this->content(['status' => 'scheduled', 'scheduled_for' => now()->addDays(2)]);
        $this->content(['status' => 'scheduled', 'scheduled_for' => now()->addDays(10)]);
        // Fora da janela de 30 dias: nao conta.
        $this->content(['status' => 'scheduled', 'scheduled_for' => now()->addDays(45)]);

        $cadencia = Metrics::for($this->project)['cadencia'];

        $this->assertSame(3, $cadencia['agendadas_30_dias']);
        $this->assertSame(3, $cadencia['dias_com_peca']);
        $this->assertSame(7, $cadencia['maior_lacuna_dias']);
    }

    public function test_qualidade_resume_os_vereditos_e_as_regras_mais_violadas(): void
    {
        $a = $this->content(['status' => 'review']);
        $b = $this->content(['status' => 'review']);

        $run = AiRun::create([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'agent' => 'reviewer',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'succeeded',
            'input' => [],
            'created_by' => User::factory()->create()->id,
        ]);

        ContentReview::create([
            'content_id' => $a->id, 'ai_run_id' => $run->id, 'verdict' => 'fail',
            'summary' => 's', 'violations' => [
                ['rule' => 'tom de voz', 'excerpt' => 'e', 'suggestion' => 's'],
                ['rule' => 'palavra proibida', 'excerpt' => 'e', 'suggestion' => 's'],
            ],
        ]);
        ContentReview::create([
            'content_id' => $b->id, 'ai_run_id' => $run->id, 'verdict' => 'pass',
            'summary' => 's', 'violations' => [],
        ]);

        $qualidade = Metrics::for($this->project)['qualidade'];

        $this->assertSame(1, $qualidade['aprovadas']);
        $this->assertSame(1, $qualidade['reprovadas']);
        $this->assertSame(2, $qualidade['violacoes']);
        $this->assertContains('tom de voz', array_column($qualidade['regras_mais_violadas'], 'regra'));
    }

    public function test_mix_conta_canais_e_formatos(): void
    {
        $this->content(['channel' => 'instagram', 'format' => 'post']);
        $this->content(['channel' => 'instagram', 'format' => 'reel']);
        $this->content(['channel' => 'blog', 'format' => 'article']);

        $mix = Metrics::for($this->project)['mix'];

        $this->assertSame(2, $mix['por_canal']['instagram']);
        $this->assertSame(1, $mix['por_canal']['blog']);
        $this->assertSame(1, $mix['por_formato']['reel']);
    }
}
