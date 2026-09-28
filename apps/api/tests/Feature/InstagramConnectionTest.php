<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O OAuth oficial, com a Meta simulada por Http::fake. Nenhum teste fala com a rede.
 */
class InstagramConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const ME = 'https://graph.instagram.com/v23.0/me*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'instagram.driver' => 'graph',
            'instagram.app_id' => 'app-123',
            'instagram.app_secret' => 'segredo-do-app',
            'instagram.redirect_uri' => 'https://social.exemplo.test/api/v1/instagram/callback',
            'instagram.graph_version' => 'v23.0',
        ]);

        Http::preventStrayRequests();
    }

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
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

    /** @return array{Project, User} */
    private function scene(WorkspaceRole $role = WorkspaceRole::Admin): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $user = $this->memberOf($workspace, $role);
        Sanctum::actingAs($user);

        return [$project, $user];
    }

    private function fakeMeta(array $permissoes = ['instagram_business_basic', 'instagram_business_content_publish'], string $tipo = 'BUSINESS'): void
    {
        Http::fake([
            'https://api.instagram.com/oauth/access_token' => Http::response([
                'data' => [['access_token' => 'token-curto', 'user_id' => '999', 'permissions' => implode(',', $permissoes)]],
            ]),
            'https://graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'token-longo-secreto', 'token_type' => 'bearer', 'expires_in' => 5184000,
            ]),
            self::ME => Http::response([
                'id' => 'app-scoped', 'user_id' => '17841455555555555', 'username' => '2msaudefeminina', 'account_type' => $tipo === 'BUSINESS' ? 'BUSINESS' : $tipo,
            ]),
        ]);
    }

    /** Pede a conexao pela API e devolve o `state` que foi para a URL da Meta. */
    private function pedirConexao(Project $project): string
    {
        $url = $this->postJson("/api/v1/projects/{$project->id}/instagram:connect")
            ->assertOk()
            ->json('authorize_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_url_de_consentimento_pede_so_os_escopos_de_publicar(): void
    {
        [$project] = $this->scene();

        $url = $this->postJson("/api/v1/projects/{$project->id}/instagram:connect")->assertOk()->json('authorize_url');

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('app-123', $query['client_id']);
        $this->assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope']);
        $this->assertSame(64, strlen($query['state']));
    }

    public function test_editor_nao_conecta(): void
    {
        [$project] = $this->scene(WorkspaceRole::Editor);

        $this->postJson("/api/v1/projects/{$project->id}/instagram:connect")->assertForbidden();
    }

    public function test_callback_conecta_a_conta_com_token_criptografado(): void
    {
        [$project, $user] = $this->scene();
        $this->fakeMeta();
        $state = $this->pedirConexao($project);

        $this->get("/api/v1/instagram/callback?code=abc%23_&state={$state}")
            ->assertRedirect("/projects/{$project->id}/instagram?instagram=conectado&motivo=%402msaudefeminina");

        $conta = InstagramAccount::withoutGlobalScopes()->sole();
        $this->assertSame('active', $conta->status);
        $this->assertSame('17841455555555555', $conta->ig_user_id);
        $this->assertSame('2msaudefeminina', $conta->username);
        $this->assertSame($user->id, $conta->connected_by);
        $this->assertSame('token-longo-secreto', $conta->access_token);
        $this->assertEqualsWithDelta(60, now()->diffInDays($conta->token_expires_at), 1);

        // No banco, o token nao esta em claro.
        $cru = DB::table('instagram_accounts')->value('access_token');
        $this->assertStringNotContainsString('token-longo-secreto', $cru);

        // O `#_` que a Meta anexa foi tirado do code antes da troca.
        Http::assertSent(fn ($r) => $r->url() === 'https://api.instagram.com/oauth/access_token' && $r['code'] === 'abc');

        // E o token nunca sai pela API.
        $this->getJson("/api/v1/projects/{$project->id}/instagram")
            ->assertOk()
            ->assertJsonPath('data.username', '2msaudefeminina')
            ->assertJsonPath('data.connector.id', $user->id)
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_state_desconhecido_nao_conecta(): void
    {
        $this->fakeMeta();

        $this->get('/api/v1/instagram/callback?code=abc&state=forjado')
            ->assertRedirect('/?instagram=erro&motivo=O+link+de+conex%C3%A3o+expirou+ou+j%C3%A1+foi+usado.+Tente+de+novo.');

        $this->assertSame(0, InstagramAccount::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_state_e_de_uso_unico(): void
    {
        [$project] = $this->scene();
        $this->fakeMeta();
        $state = $this->pedirConexao($project);

        $this->get("/api/v1/instagram/callback?code=abc&state={$state}");
        $this->get("/api/v1/instagram/callback?code=abc&state={$state}")
            ->assertRedirectContains('instagram=erro');

        $this->assertSame(1, InstagramAccount::withoutGlobalScopes()->count());
    }

    public function test_sem_permissao_de_publicar_nao_conecta(): void
    {
        [$project] = $this->scene();
        $this->fakeMeta(['instagram_business_basic']);
        $state = $this->pedirConexao($project);

        $this->get("/api/v1/instagram/callback?code=abc&state={$state}")
            ->assertRedirectContains('instagram=erro')
            ->assertRedirectContains('instagram_business_content_publish');

        $this->assertSame(0, InstagramAccount::withoutGlobalScopes()->count());
    }

    public function test_conta_pessoal_nao_conecta(): void
    {
        [$project] = $this->scene();
        $this->fakeMeta(tipo: 'PERSONAL');
        $state = $this->pedirConexao($project);

        $this->get("/api/v1/instagram/callback?code=abc&state={$state}")
            ->assertRedirectContains('instagram=erro')
            ->assertRedirectContains('conta+profissional');

        $this->assertSame(0, InstagramAccount::withoutGlobalScopes()->count());
    }

    public function test_usuario_que_cancela_na_meta_volta_com_erro(): void
    {
        [$project] = $this->scene();
        $state = $this->pedirConexao($project);

        $this->get("/api/v1/instagram/callback?error=access_denied&state={$state}")
            ->assertRedirect("/projects/{$project->id}/instagram?instagram=erro&motivo=A+autoriza%C3%A7%C3%A3o+foi+cancelada+no+Instagram.");
    }

    public function test_quem_perdeu_o_admin_no_meio_do_caminho_nao_conecta(): void
    {
        [$project, $user] = $this->scene();
        $this->fakeMeta();
        $state = $this->pedirConexao($project);

        WorkspaceMember::where('user_id', $user->id)->update(['role' => WorkspaceRole::Editor]);

        $this->get("/api/v1/instagram/callback?code=abc&state={$state}")->assertRedirectContains('instagram=erro');
        $this->assertSame(0, InstagramAccount::withoutGlobalScopes()->count());
    }

    public function test_reconectar_substitui_a_conta_anterior_sem_apagar(): void
    {
        [$project] = $this->scene();
        $this->fakeMeta();

        $this->get('/api/v1/instagram/callback?code=a&state='.$this->pedirConexao($project));
        $this->get('/api/v1/instagram/callback?code=b&state='.$this->pedirConexao($project));

        $this->assertSame(2, InstagramAccount::withoutGlobalScopes()->count());
        $this->assertSame(1, InstagramAccount::withoutGlobalScopes()->where('status', 'active')->count());
        $this->assertNull(InstagramAccount::withoutGlobalScopes()->where('status', 'disconnected')->value('access_token'));
    }

    public function test_desconectar_apaga_o_token_e_guarda_a_linha(): void
    {
        [$project] = $this->scene();
        $this->fakeMeta();
        $this->get('/api/v1/instagram/callback?code=a&state='.$this->pedirConexao($project));

        $this->deleteJson("/api/v1/projects/{$project->id}/instagram")->assertNoContent();

        $conta = InstagramAccount::withoutGlobalScopes()->sole();
        $this->assertSame('disconnected', $conta->status);
        $this->assertNull($conta->access_token);
        $this->getJson("/api/v1/projects/{$project->id}/instagram")->assertJsonPath('data', null);
    }

    public function test_editor_nao_desconecta_e_outro_tenant_nao_existe(): void
    {
        [$project] = $this->scene(WorkspaceRole::Editor);
        $this->deleteJson("/api/v1/projects/{$project->id}/instagram")->assertForbidden();

        $alheio = Project::factory()->create();
        $this->getJson("/api/v1/projects/{$alheio->id}/instagram")->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$alheio->id}/instagram")->assertNotFound();
    }

    private function conta(Project $project, array $attrs = []): InstagramAccount
    {
        return InstagramAccount::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'ig_user_id' => '1784',
            'username' => '2msaudefeminina',
            'account_type' => 'BUSINESS',
            'access_token' => 'token-velho',
            'token_expires_at' => now()->addDays(5),
            'token_refreshed_at' => now()->subDays(55),
            'status' => 'active',
            'connected_by' => User::factory()->create()->id,
            'connected_at' => now()->subDays(55),
            ...$attrs,
        ]);
    }

    public function test_renovacao_troca_o_token_perto_de_vencer(): void
    {
        $conta = $this->conta(Project::factory()->create());
        Http::fake(['https://graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'token-novo', 'expires_in' => 5184000])]);

        $this->artisan('instagram:refresh-tokens')->assertSuccessful();

        $conta->refresh();
        $this->assertSame('token-novo', $conta->access_token);
        $this->assertEqualsWithDelta(60, now()->diffInDays($conta->token_expires_at), 1);
        $this->assertSame('active', $conta->status);
    }

    public function test_token_recusado_na_renovacao_vira_expirado(): void
    {
        $conta = $this->conta(Project::factory()->create());
        Http::fake(['https://graph.instagram.com/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
        ], 400)]);

        $this->artisan('instagram:refresh-tokens')->assertSuccessful();

        $this->assertSame('expired', $conta->fresh()->status);
        $this->assertSame('Error validating access token', $conta->fresh()->last_error);
    }

    public function test_falha_transitoria_na_renovacao_nao_derruba_a_conta(): void
    {
        $conta = $this->conta(Project::factory()->create());
        Http::fake(['https://graph.instagram.com/refresh_access_token*' => Http::response('', 503)]);

        $this->artisan('instagram:refresh-tokens')->assertSuccessful();

        $this->assertSame('active', $conta->fresh()->status);
        $this->assertSame('token-velho', $conta->fresh()->access_token);
        $this->assertNotNull($conta->fresh()->last_error);
    }

    public function test_token_vencido_vira_expirado_sem_chamar_a_meta(): void
    {
        $conta = $this->conta(Project::factory()->create(), ['token_expires_at' => now()->subHour()]);

        $this->artisan('instagram:refresh-tokens')->assertSuccessful();

        $this->assertSame('expired', $conta->fresh()->status);
        $this->assertFalse($conta->fresh()->isUsable());
        Http::assertNothingSent();
    }

    public function test_token_longe_de_vencer_nao_e_renovado(): void
    {
        $conta = $this->conta(Project::factory()->create(), ['token_expires_at' => now()->addDays(40)]);

        $this->artisan('instagram:refresh-tokens')->assertSuccessful();

        $this->assertSame('token-velho', $conta->fresh()->access_token);
        Http::assertNothingSent();
    }

    public function test_driver_fake_conecta_sem_sair_da_maquina(): void
    {
        config(['instagram.driver' => 'fake']);
        [$project] = $this->scene();

        $url = $this->postJson("/api/v1/projects/{$project->id}/instagram:connect")->json('authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->get("/api/v1/instagram/callback?code={$query['code']}&state={$query['state']}")
            ->assertRedirectContains('instagram=conectado');

        $this->assertSame('conta_de_teste', InstagramAccount::withoutGlobalScopes()->sole()->username);
        Http::assertNothingSent();
        $this->assertNull(Cache::get('instagram-oauth:'.$query['state']));
    }
}
