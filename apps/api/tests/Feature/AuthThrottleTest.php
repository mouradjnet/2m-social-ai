<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O servico e publico. Sem limite, o login aceita tentativas de senha sem fim, e
 * um token vazado valia para sempre.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function register(string $email): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Alguem',
            'email' => $email,
            'password' => 'senha-bem-longa',
        ]);
    }

    /**
     * Depois de 5 erros, nem a senha CERTA entra: senao o bloqueio nao impede o
     * atacante de acertar na 6a.
     */
    public function test_sexta_tentativa_de_login_no_mesmo_email_e_barrada(): void
    {
        User::factory()->create(['email' => 'dono@example.com', 'password' => 'senha-certa-123']);

        foreach (range(1, 5) as $_) {
            $this->login('dono@example.com', 'errada')->assertStatus(422);
        }

        $this->login('dono@example.com', 'senha-certa-123')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Muitas tentativas. Aguarde um minuto e tente de novo.')
            // A tela de login so exibe erro preso a campo.
            ->assertJsonPath('errors.email.0', 'Muitas tentativas. Aguarde um minuto e tente de novo.');
    }

    /** Variar a caixa do email nao abre uma cota nova. */
    public function test_caixa_do_email_nao_escapa_do_limite(): void
    {
        foreach (range(1, 5) as $_) {
            $this->login('dono@example.com', 'errada');
        }

        $this->login('DONO@Example.com', 'errada')->assertStatus(429);
    }

    public function test_bloqueio_de_um_email_nao_trava_o_login_de_outro(): void
    {
        User::factory()->create(['email' => 'outro@example.com', 'password' => 'senha-certa-123']);

        foreach (range(1, 5) as $_) {
            $this->login('dono@example.com', 'errada');
        }

        $this->login('outro@example.com', 'senha-certa-123')->assertOk();
    }

    public function test_limite_de_login_zera_depois_de_um_minuto(): void
    {
        User::factory()->create(['email' => 'dono@example.com', 'password' => 'senha-certa-123']);

        foreach (range(1, 5) as $_) {
            $this->login('dono@example.com', 'errada');
        }

        $this->travel(61)->seconds();

        $this->login('dono@example.com', 'senha-certa-123')->assertOk();
    }

    public function test_sexto_cadastro_do_mesmo_ip_e_barrado(): void
    {
        foreach (range(1, 5) as $i) {
            $this->register("pessoa{$i}@example.com")->assertCreated();
        }

        $this->register('pessoa6@example.com')->assertStatus(429);
        $this->assertDatabaseMissing('users', ['email' => 'pessoa6@example.com']);
    }

    /** Um token vazado deixa de valer sozinho. */
    public function test_token_expira_depois_do_prazo(): void
    {
        $token = $this->register('dono@example.com')->assertCreated()->json('token');
        $comToken = ['Authorization' => "Bearer {$token}"];

        $this->travel(config('sanctum.expiration') - 1)->minutes();
        $this->getJson('/api/v1/me', $comToken)->assertOk();

        // O guard guarda o usuario ja resolvido dentro do mesmo teste.
        $this->app['auth']->forgetGuards();

        $this->travel(2)->minutes();
        $this->getJson('/api/v1/me', $comToken)->assertUnauthorized();
    }
}
