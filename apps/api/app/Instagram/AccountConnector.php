<?php

namespace App\Instagram;

use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Conectar, renovar e desconectar a conta. A senha do Instagram nunca passa por
 * aqui: o usuario autoriza na propria Meta e o que volta e um token com os escopos
 * que ele aceitou.
 */
class AccountConnector
{
    /** Contas pessoais nao publicam pela API. */
    private const TIPOS_PROFISSIONAIS = ['BUSINESS', 'MEDIA_CREATOR'];

    public function __construct(private readonly InstagramGateway $gateway) {}

    /**
     * Do `code` do callback a uma conta ativa no projeto. Qualquer recusa sai como
     * InstagramException `permanent` com a mensagem para o humano.
     */
    public function connect(Project $project, User $user, string $code): InstagramAccount
    {
        $grant = $this->gateway->exchangeCode($code);

        // O usuario pode desmarcar permissoes na tela da Meta. Sem publicar, a conta
        // conectada seria uma promessa que falha na primeira publicacao.
        $faltando = array_diff(config('instagram.scopes'), $grant['permissions']);

        if ($faltando !== []) {
            throw new InstagramException(
                'Faltou autorizar: '.implode(', ', $faltando).'. Conecte de novo e aceite todas as permissões.',
                'permanent',
            );
        }

        $longo = $this->gateway->longLivedToken($grant['access_token']);
        $perfil = $this->gateway->profile($longo['access_token']);

        if (! in_array(strtoupper($perfil['account_type']), self::TIPOS_PROFISSIONAIS, true)) {
            throw new InstagramException(
                "@{$perfil['username']} não é uma conta profissional. Mude para Empresa ou Criador de conteúdo no app do Instagram.",
                'permanent',
            );
        }

        return DB::transaction(function () use ($project, $user, $grant, $longo, $perfil) {
            // Reconectar substitui: a conta anterior sai de cena sem apagar a linha
            // (as publicacoes antigas apontam para ela).
            InstagramAccount::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('status', '<>', 'disconnected')
                ->update(['status' => 'disconnected', 'access_token' => null, 'disconnected_at' => now()]);

            return InstagramAccount::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'ig_user_id' => $perfil['ig_user_id'],
                'username' => $perfil['username'],
                'account_type' => strtoupper($perfil['account_type']),
                'access_token' => $longo['access_token'],
                'token_expires_at' => now()->addSeconds($longo['expires_in']),
                'token_refreshed_at' => now(),
                'scopes' => array_values($grant['permissions']),
                'status' => 'active',
                'connected_by' => $user->id,
                'connected_at' => now(),
            ]);
        });
    }

    /**
     * Renova o token longo. Token recusado pela Meta vira `expired` (a tela avisa e
     * a publicacao para); falha transitoria so fica registrada — amanha tenta de novo.
     */
    public function refresh(InstagramAccount $account): void
    {
        try {
            $novo = $this->gateway->refreshToken((string) $account->access_token);
        } catch (InstagramException $e) {
            $account->update([
                'last_error' => $e->getMessage(),
                ...$e->kind === 'auth' ? ['status' => 'expired'] : [],
            ]);

            return;
        }

        $account->update([
            'access_token' => $novo['access_token'],
            'token_expires_at' => now()->addSeconds($novo['expires_in']),
            'token_refreshed_at' => now(),
            'last_error' => null,
        ]);
    }

    public function disconnect(InstagramAccount $account): void
    {
        $account->update(['status' => 'disconnected', 'access_token' => null, 'disconnected_at' => now()]);
    }
}
