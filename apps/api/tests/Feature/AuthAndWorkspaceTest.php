<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthAndWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registro_devolve_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Djair',
            'email' => 'djair@example.com',
            'password' => 'senha-bem-longa',
        ])->assertCreated()->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', ['email' => 'djair@example.com']);
    }

    public function test_allowlist_barra_email_de_fora_da_lista(): void
    {
        config(['registration.allowed_emails' => ['dono@example.com']]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Intruso',
            'email' => 'qualquer@example.com',
            'password' => 'senha-bem-longa',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Cadastro fechado. Fale com o administrador.');

        $this->assertDatabaseMissing('users', ['email' => 'qualquer@example.com']);
    }

    public function test_allowlist_deixa_passar_quem_esta_na_lista_ignorando_caixa(): void
    {
        config(['registration.allowed_emails' => ['dono@example.com']]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dono',
            'email' => 'DONO@Example.com',
            'password' => 'senha-bem-longa',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'DONO@Example.com']);
    }

    public function test_login_com_senha_errada_nao_revela_se_o_email_existe(): void
    {
        User::factory()->create(['email' => 'existe@example.com']);

        $comEmailReal = $this->postJson('/api/v1/auth/login', [
            'email' => 'existe@example.com',
            'password' => 'errada',
        ])->assertStatus(422);

        $comEmailFalso = $this->postJson('/api/v1/auth/login', [
            'email' => 'naoexiste@example.com',
            'password' => 'errada',
        ])->assertStatus(422);

        $this->assertSame(
            $comEmailReal->json('errors.email'),
            $comEmailFalso->json('errors.email'),
        );
    }

    public function test_criar_workspace_torna_o_criador_dono_e_membro(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/workspaces', ['name' => '2M Negocios'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'owner');

        $id = $response->json('data.id');

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // E ele consegue usar o workspace que acabou de criar.
        $this->getJson("/api/v1/workspaces/{$id}/projects")->assertOk();
    }

    public function test_me_lista_os_workspaces_do_usuario_com_o_papel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/workspaces', ['name' => 'Alfa']);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('workspaces.0.name', 'Alfa')
            ->assertJsonPath('workspaces.0.role', 'owner');
    }

    public function test_usuario_novo_nao_tem_workspace(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(0, 'workspaces');
    }
}
