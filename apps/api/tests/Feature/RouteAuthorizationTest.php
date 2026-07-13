<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A rede de autorizacao das rotas que MUDAM alguma coisa.
 *
 * Todo controller de agente comeca com `Gate::authorize('update', $project)` e o
 * `WorkspaceMemberScope` faz o projeto de outro tenant sumir (404, nao 403). Mas
 * cinco dessas rotas — schedule, review, design, seo e analytics — nao tinham UM
 * teste sequer provando isso: apagar o `Gate::authorize` num refactor deixaria a
 * suite inteira verde, e um `viewer` passaria a disparar geracao paga no projeto.
 *
 * O defeito de hoje (duas estrategias ativas) tinha a mesma forma: o codigo estava
 * certo por sorte, nao por contrato. Aqui o contrato fica escrito.
 *
 * A autorizacao roda ANTES das pre-condicoes (422 sem estrategia, 402 sem
 * orcamento), entao a tabela nao precisa montar cenario nenhum: 404 e 403 valem
 * mesmo com o projeto vazio.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As rotas mutantes que NAO sao deste teste, e por que. Uma rota so entra aqui
     * com uma razao escrita: a lista e a confissao, nao a desculpa.
     */
    private const COBERTAS_EM_OUTRO_LUGAR = [
        'POST api/v1/auth/register' => 'publica por definicao (AuthAndWorkspaceTest, incluindo a allowlist)',
        'POST api/v1/auth/login' => 'publica por definicao (AuthAndWorkspaceTest)',
        'POST api/v1/auth/logout' => 'so exige token, nao papel (AuthAndWorkspaceTest: revoga o token usado)',
        'POST api/v1/workspaces' => 'cria o tenant; nao ha projeto a autorizar (WorkspaceIsolationTest)',
        'POST api/v1/workspaces/{workspace}/projects' => 'middleware workspace:{papel} (WorkspaceIsolationTest)',
        'PATCH api/v1/contents/{content}' => 'ContentTransitionTest: viewer 403, outro tenant 404',
        'POST api/v1/contents/{content}/seo:apply' => 'SeoGenerationTest: outro tenant 404',
        'PATCH api/v1/strategies/{strategy}' => 'aqui embaixo, em test_*_estrategia_*',
        'PATCH api/v1/projects/{project}' => 'ProjectUpdateTest: viewer 403, outro tenant 404, sem token 401',
        'POST api/v1/contents/{content}/rewrite:generate' => 'RewriteGenerationTest: viewer 403, outro tenant 404, sem token 401',
    ];

    /** Toda rota que dispara IA (e cobra por isso) exige pelo menos `editor`. */
    public static function rotasQueMudam(): array
    {
        return [
            'gerar estrategia' => ['post', 'strategies:generate'],
            'gerar copy' => ['post', 'copy:generate'],
            'agendar' => ['post', 'schedule:generate'],
            'revisar' => ['post', 'review:generate'],
            'gerar imagem' => ['post', 'design:generate'],
            'otimizar seo' => ['post', 'seo:generate'],
            'analisar' => ['post', 'analytics:generate'],
            'editar perfil da marca' => ['patch', 'brand-profile'],
        ];
    }

    /**
     * O GUARDIAO. Le as rotas em tempo de execucao e exige que toda rota que MUDA
     * alguma coisa esteja coberta: ou pela tabela acima, ou por uma linha em
     * COBERTAS_EM_OUTRO_LUGAR com a razao escrita.
     *
     * Sem isto, a tabela protege o que existe HOJE e a proxima rota nasce descoberta
     * de novo — que e a historia deste projeto: cinco rotas de agente ficaram sem
     * teste de autorizacao porque ninguem TINHA que lembrar. Agora esquecer quebra o
     * CI, em vez de aparecer em producao.
     */
    public function test_nenhuma_rota_que_muda_fica_sem_teste_de_autorizacao(): void
    {
        $daTabela = array_map(
            fn (array $r): string => strtoupper($r[0]).' api/v1/projects/{project}/'.$r[1],
            array_values(self::rotasQueMudam()),
        );

        $cobertas = array_merge($daTabela, array_keys(self::COBERTAS_EM_OUTRO_LUGAR));

        $descobertas = [];

        foreach (Route::getRoutes() as $rota) {
            if (! str_starts_with($rota->uri(), 'api/')) {
                continue;
            }

            foreach (array_intersect($rota->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']) as $verbo) {
                $assinatura = "{$verbo} {$rota->uri()}";

                if (! in_array($assinatura, $cobertas, true)) {
                    $descobertas[] = $assinatura;
                }
            }
        }

        $this->assertSame([], $descobertas, sprintf(
            "Rota(s) que mudam o estado sem teste de autorizacao:\n  - %s\n\n".
            "Escreva o teste (viewer -> 403, outro tenant -> 404, sem token -> 401): se for\n".
            "uma rota de projeto, basta acrescenta-la a rotasQueMudam(). Se ja estiver\n".
            'coberta em outro arquivo, declare em COBERTAS_EM_OUTRO_LUGAR com a razao.',
            implode("\n  - ", $descobertas),
        ));
    }

    private function membro(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /**
     * `viewer` LE, nao escreve. Sem isto, qualquer convidado do workspace dispara
     * geracao de IA — que custa dinheiro do dono da chave.
     */
    #[DataProvider('rotasQueMudam')]
    public function test_viewer_nao_dispara_rota_que_muda(string $verbo, string $rota): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->membro($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($viewer);

        $this->json($verbo, "/api/v1/projects/{$project->id}/{$rota}")->assertForbidden();
    }

    /**
     * Projeto de outro tenant nao existe para mim: 404, nao 403 — um 403 confirmaria
     * que o id existe (o `WorkspaceMemberScope` e quem faz isso).
     */
    #[DataProvider('rotasQueMudam')]
    public function test_projeto_de_outro_workspace_nao_existe(string $verbo, string $rota): void
    {
        $meu = Workspace::factory()->create();
        $alheio = Workspace::factory()->create();
        $intruso = $this->membro($meu, WorkspaceRole::Owner);
        $alvo = Project::factory()->create(['workspace_id' => $alheio->id]);

        Sanctum::actingAs($intruso);

        $this->json($verbo, "/api/v1/projects/{$alvo->id}/{$rota}")->assertNotFound();
    }

    /** Sem token, nenhuma delas responde. */
    #[DataProvider('rotasQueMudam')]
    public function test_sem_autenticacao_nao_passa(string $verbo, string $rota): void
    {
        $project = Project::factory()->create();

        $this->json($verbo, "/api/v1/projects/{$project->id}/{$rota}")->assertUnauthorized();
    }

    private function estrategia(Project $project): Strategy
    {
        return Strategy::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Estrategia',
            'summary' => 's',
            'editorial_line' => 'e',
            'pillars' => [['name' => 'Educacao', 'weight' => 100, 'description' => 'd']],
            'status' => 'draft',
        ]);
    }

    /**
     * Aprovar uma estrategia libera o copywriter (e o gasto). Nao e leitura: o
     * `viewer` nao aprova.
     */
    public function test_viewer_nao_aprova_estrategia(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->membro($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $strategy = $this->estrategia($project);

        Sanctum::actingAs($viewer);

        $this->patchJson("/api/v1/strategies/{$strategy->id}", ['status' => 'active'])
            ->assertForbidden();

        $this->assertSame('draft', $strategy->refresh()->status);
    }

    /** Estrategia de outro tenant nao existe para mim. */
    public function test_estrategia_de_outro_workspace_nao_existe(): void
    {
        $meu = Workspace::factory()->create();
        $alheio = Workspace::factory()->create();
        $intruso = $this->membro($meu, WorkspaceRole::Owner);
        $alvo = $this->estrategia(Project::factory()->create(['workspace_id' => $alheio->id]));

        Sanctum::actingAs($intruso);

        $this->patchJson("/api/v1/strategies/{$alvo->id}", ['status' => 'active'])
            ->assertNotFound();

        $this->assertSame('draft', $alvo->refresh()->status);
    }
}
