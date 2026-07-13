<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
