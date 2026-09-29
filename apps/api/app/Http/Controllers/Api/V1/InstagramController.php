<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Instagram\AccountConnector;
use App\Instagram\InstagramException;
use App\Instagram\InstagramGateway;
use App\Models\ActivityLog;
use App\Models\InstagramAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * A conexao do projeto com uma conta profissional do Instagram, pelo OAuth oficial.
 *
 * Conectar e desconectar e gesto de `admin`+: e decidir em nome de quem a marca fala
 * em publico, nao editar conteudo.
 */
class InstagramController extends Controller
{
    private const STATE_TTL_MINUTES = 10;

    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => $this->contaViva($project)?->load('connector'),
        ]);
    }

    /**
     * Devolve a URL do consentimento. O `state` e aleatorio, de uso unico, e vive no
     * cache amarrado a quem pediu e ao projeto — e o que impede um callback forjado
     * de plantar a conta de outra pessoa no projeto (CSRF do OAuth).
     */
    public function connect(Request $request, Project $project, InstagramGateway $gateway): JsonResponse
    {
        if ($resposta = $this->exigeAdmin($request->user(), $project)) {
            return $resposta;
        }

        $state = bin2hex(random_bytes(32));

        Cache::put("instagram-oauth:{$state}", [
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return response()->json(['authorize_url' => $gateway->authorizeUrl($state)]);
    }

    /**
     * Onde a Meta devolve o navegador. Nao ha token de API aqui — o navegador nao o
     * manda num redirect —, entao quem prova a identidade e o `state`.
     *
     * Sempre termina num redirect para a SPA, com o resultado na query: a tela le e
     * mostra. Nunca uma pagina de erro crua no meio do fluxo.
     */
    public function callback(Request $request, AccountConnector $connector): RedirectResponse
    {
        $pedido = Cache::pull('instagram-oauth:'.$request->query('state', ''));

        if ($pedido === null) {
            return $this->voltar('/', 'erro', 'O link de conexão expirou ou já foi usado. Tente de novo.');
        }

        $project = Project::withoutGlobalScopes()->findOrFail($pedido['project_id']);
        $user = User::findOrFail($pedido['user_id']);
        $destino = "/projects/{$project->id}/instagram";

        if ($request->filled('error')) {
            return $this->voltar($destino, 'erro', 'A autorização foi cancelada no Instagram.');
        }

        // O papel e conferido de novo: entre pedir e voltar, a pessoa pode ter saido
        // do workspace ou perdido o admin.
        if (! $project->workspace->roleFor($user)?->atLeast(WorkspaceRole::Admin)) {
            return $this->voltar($destino, 'erro', 'Você não administra mais este espaço de trabalho.');
        }

        try {
            $conta = $connector->connect($project, $user, (string) $request->query('code', ''));
        } catch (InstagramException $e) {
            Log::warning('Conexao do Instagram recusada', ['project_id' => $project->id, 'kind' => $e->kind, 'erro' => $e->getMessage()]);

            return $this->voltar($destino, 'erro', $e->kind === 'permanent'
                ? $e->getMessage()
                : 'O Instagram não respondeu. Tente de novo em alguns minutos.');
        }

        ActivityLog::record($user, $project, 'instagram.connected', $conta, ['username' => $conta->username]);

        return $this->voltar($destino, 'conectado', "@{$conta->username}");
    }

    public function disconnect(Request $request, Project $project, AccountConnector $connector): Response|JsonResponse
    {
        if ($resposta = $this->exigeAdmin($request->user(), $project)) {
            return $resposta;
        }

        $conta = $this->contaViva($project);

        if ($conta === null) {
            return response()->json(['message' => 'Este projeto não tem conta do Instagram conectada.'], 404);
        }

        $connector->disconnect($conta);
        ActivityLog::record($request->user(), $project, 'instagram.disconnected', $conta, ['username' => $conta->username]);

        return response()->noContent();
    }

    private function contaViva(Project $project): ?InstagramAccount
    {
        return InstagramAccount::where('project_id', $project->id)
            ->where('status', '<>', 'disconnected')
            ->first();
    }

    private function exigeAdmin(User $user, Project $project): ?JsonResponse
    {
        Gate::authorize('view', $project);

        if (! $project->workspace->roleFor($user)?->atLeast(WorkspaceRole::Admin)) {
            return response()->json([
                'message' => 'Só quem administra o espaço de trabalho conecta ou desconecta o Instagram.',
            ], 403);
        }

        return null;
    }

    /**
     * Redirect RELATIVO: atras de um proxy (o do Vite em dev, o Nginx na VPS) o host
     * que o Laravel enxerga nao e o que o navegador usa, e um Location absoluto
     * mandaria o usuario para o endereco interno.
     */
    private function voltar(string $destino, string $resultado, string $detalhe): RedirectResponse
    {
        return new RedirectResponse($destino.'?'.http_build_query(['instagram' => $resultado, 'motivo' => $detalhe]));
    }
}
