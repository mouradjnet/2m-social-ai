<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O produto era um SaaS de EQUIPE com um usuario so. Os papeis ja existiam e ja eram
 * guardados por teste — faltava a porta de entrada.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function membro(Workspace $workspace, WorkspaceRole $role, ?string $email = null): User
    {
        $user = User::factory()->create($email !== null ? ['email' => $email] : []);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function convite(Workspace $workspace, User $por, array $extra = []): WorkspaceInvitation
    {
        return WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'convidada@exemplo.com',
            'role' => WorkspaceRole::Editor,
            'token' => bin2hex(random_bytes(32)),
            'invited_by' => $por->id,
            'expires_at' => now()->addDays(7),
            ...$extra,
        ]);
    }

    public function test_admin_convida_e_recebe_o_link(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);

        Sanctum::actingAs($admin);

        $r = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'Nova@Exemplo.com',
            'role' => 'editor',
        ])->assertStatus(201);

        // Sem mailer no projeto: o token E a entrega. Se ele nao voltar na resposta,
        // quem convidou nao tem o que copiar e o convite morre no banco.
        $token = $r->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));

        // Email normalizado: senao "Nova@" e "nova@" viram convites diferentes e a
        // checagem do aceite falha para a pessoa certa.
        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'nova@exemplo.com',
            'role' => 'editor',
            'invited_by' => $admin->id,
            'accepted_at' => null,
        ]);
    }

    /** Convidar e administrar. Quem escreve conteudo nao decide quem entra. */
    public function test_editor_nao_convida(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->membro($workspace, WorkspaceRole::Editor);

        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'nova@exemplo.com',
            'role' => 'viewer',
        ])->assertForbidden();
    }

    public function test_nao_membro_nao_sabe_que_o_workspace_existe(): void
    {
        $alheio = Workspace::factory()->create();
        $estranho = User::factory()->create();

        Sanctum::actingAs($estranho);

        $this->postJson("/api/v1/workspaces/{$alheio->id}/invitations", [
            'email' => 'nova@exemplo.com',
            'role' => 'viewer',
        ])->assertNotFound();
    }

    public function test_convidar_sem_token_devolve_401(): void
    {
        $workspace = Workspace::factory()->create();

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'nova@exemplo.com',
            'role' => 'viewer',
        ])->assertUnauthorized();
    }

    /**
     * A ESCALADA PELA PORTA DOS FUNDOS: sem esta regra, um admin convida um `owner`,
     * entra com a outra conta e passa a mandar no workspace de quem o convidou.
     */
    public function test_ninguem_convida_papel_acima_do_proprio(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'nova@exemplo.com',
            'role' => 'owner',
        ])->assertStatus(422);

        // O mesmo papel, sim: admin convida admin.
        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'nova@exemplo.com',
            'role' => 'admin',
        ])->assertStatus(201);
    }

    public function test_nao_convida_quem_ja_e_membro(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $this->membro($workspace, WorkspaceRole::Viewer, 'jaesta@exemplo.com');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'jaesta@exemplo.com',
            'role' => 'editor',
        ])->assertStatus(422);
    }

    /** Dois links validos para a mesma pessoa e so confusao — e o antigo pode ter vazado. */
    public function test_convidar_de_novo_substitui_o_pendente(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $velho = $this->convite($workspace, $admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'convidada@exemplo.com',
            'role' => 'viewer',
        ])->assertStatus(201);

        $this->assertDatabaseMissing('workspace_invitations', ['token' => $velho->token]);
        $this->assertSame(1, WorkspaceInvitation::where('workspace_id', $workspace->id)->count());
    }

    public function test_aceitar_cria_o_membro_com_o_papel_do_convite(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $convite = $this->convite($workspace, $admin, ['role' => WorkspaceRole::Reviewer]);

        $convidada = User::factory()->create(['email' => 'convidada@exemplo.com']);
        Sanctum::actingAs($convidada);

        $this->postJson("/api/v1/invitations/{$convite->token}:accept")->assertStatus(201);

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $convidada->id,
            'role' => 'reviewer',
        ]);

        $this->assertNotNull($convite->refresh()->accepted_at);
    }

    /**
     * O LINK VAI POR WHATSAPP. Sem esta checagem, encaminhar a mensagem basta para um
     * estranho entrar num workspace que tem a chave da Anthropic atras.
     */
    public function test_o_convite_e_para_um_email_so(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $convite = $this->convite($workspace, $admin);

        $intrusa = User::factory()->create(['email' => 'outra@exemplo.com']);
        Sanctum::actingAs($intrusa);

        $this->postJson("/api/v1/invitations/{$convite->token}:accept")->assertForbidden();

        $this->assertDatabaseMissing('workspace_members', ['user_id' => $intrusa->id]);
        $this->assertNull($convite->refresh()->accepted_at);
    }

    public function test_convite_vencido_nao_vale(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $convite = $this->convite($workspace, $admin, ['expires_at' => now()->subMinute()]);

        $convidada = User::factory()->create(['email' => 'convidada@exemplo.com']);
        Sanctum::actingAs($convidada);

        $this->postJson("/api/v1/invitations/{$convite->token}:accept")->assertStatus(422);

        // Contar a tabela inteira amarraria o teste a quantos membros a factory de
        // workspace cria por dentro. O que importa e ela NAO ter entrado.
        $this->assertDatabaseMissing('workspace_members', ['user_id' => $convidada->id]);
    }

    /** Aceitar duas vezes daria dois membros — e o unique(workspace_id,user_id) e 500. */
    public function test_convite_nao_se_aceita_duas_vezes(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $convite = $this->convite($workspace, $admin);

        $convidada = User::factory()->create(['email' => 'convidada@exemplo.com']);
        Sanctum::actingAs($convidada);

        $this->postJson("/api/v1/invitations/{$convite->token}:accept")->assertStatus(201);
        $this->postJson("/api/v1/invitations/{$convite->token}:accept")->assertStatus(422);

        // UMA associacao, nao duas: o segundo aceite tem de parar no 422 e nao no
        // unique(workspace_id, user_id), que seria um 500 na cara do convidado.
        $this->assertSame(1, WorkspaceMember::where('user_id', $convidada->id)->count());
    }

    public function test_token_que_nao_existe_devolve_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/invitations/'.bin2hex(random_bytes(32)).':accept')->assertStatus(422);
    }

    public function test_aceitar_sem_token_devolve_401(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $convite = $this->convite($workspace, $admin);

        $this->postJson("/api/v1/invitations/{$convite->token}:accept")->assertUnauthorized();
    }

    public function test_a_lista_traz_so_os_pendentes(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);

        $this->convite($workspace, $admin, ['email' => 'pendente@exemplo.com']);
        $this->convite($workspace, $admin, ['email' => 'vencido@exemplo.com', 'expires_at' => now()->subDay()]);
        $this->convite($workspace, $admin, ['email' => 'aceito@exemplo.com', 'accepted_at' => now()]);

        Sanctum::actingAs($admin);

        $r = $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations")->assertOk();

        $this->assertCount(1, $r->json('data'));
        $this->assertSame('pendente@exemplo.com', $r->json('data.0.email'));
    }

    /**
     * O convite NAO e uma porta lateral para a allowlist. Quem pode ter conta e quem
     * pode entrar no meu workspace sao perguntas diferentes: se o convite furasse o
     * cadastro, o convidado viraria usuario pleno e poderia criar workspace proprio,
     * com teto proprio de orcamento gastando a chave de quem o convidou.
     */
    public function test_convite_nao_fura_a_allowlist_de_cadastro(): void
    {
        config(['registration.allowed_emails' => ['sozinho@exemplo.com']]);

        $workspace = Workspace::factory()->create();
        $admin = $this->membro($workspace, WorkspaceRole::Admin);
        $this->convite($workspace, $admin, ['email' => 'defora@exemplo.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'De Fora',
            'email' => 'defora@exemplo.com',
            'password' => 'senha-bem-longa',
        ])->assertStatus(422);
    }
}
