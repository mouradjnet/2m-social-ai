<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * O produto e um SaaS de EQUIPE e tinha um usuario so: uma agencia tem quem escreve,
 * quem aprova e o cliente que so olha. Os papeis ja existiam e ja eram guardados por
 * teste — faltava a porta de entrada.
 *
 * O convite NAO mexe na allowlist de cadastro (`REGISTRATION_ALLOWED_EMAILS`), de
 * proposito: quem pode ter conta e quem pode entrar no meu workspace sao duas
 * perguntas diferentes, e misturar as duas abriria o buraco que a allowlist existe
 * para tapar — o convidado viraria usuario pleno e poderia criar workspace proprio,
 * com teto proprio de orcamento gastando a chave de quem convidou. Para convidar
 * alguem de fora: primeiro o email entra na allowlist (painel, sem deploy), depois
 * o convite.
 */
class InvitationController extends Controller
{
    /** Uma semana: prazo curto o bastante para um link vazado envelhecer sozinho. */
    private const VALIDADE_EM_DIAS = 7;

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json([
            'data' => $workspace->invitations()->pendente()->with('invitedBy:id,name')->latest('id')->get(),
        ]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);

        $email = strtolower($data['email']);
        $papel = WorkspaceRole::from($data['role']);

        // Quem convida nao pode fabricar alguem acima de si: sem isto, um admin cria
        // um owner e escala o proprio privilegio pela porta dos fundos.
        $meu = $request->attributes->get('workspace_role');

        if (! $meu->atLeast($papel)) {
            return response()->json([
                'message' => 'Voce nao pode convidar alguem com papel acima do seu.',
            ], 422);
        }

        if ($workspace->users()->where('users.email', $email)->exists()) {
            return response()->json([
                'message' => 'Esta pessoa ja e membro deste workspace.',
            ], 422);
        }

        // Convidar de novo o mesmo email substitui o convite pendente: dois links
        // validos para a mesma pessoa e so confusao, e o antigo pode ter vazado.
        $workspace->invitations()->pendente()->where('email', $email)->delete();

        $invitation = $workspace->invitations()->create([
            'email' => $email,
            'role' => $papel,
            // 64 hex = 32 bytes de entropia. O link vale por si: quem o tem, tem o
            // convite (mas o email ainda e conferido no aceite).
            'token' => bin2hex(random_bytes(32)),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(self::VALIDADE_EM_DIAS),
        ]);

        return response()->json(['data' => $invitation], 201);
    }

    /**
     * Aceitar NAO passa pelo middleware `workspace:{papel}`: quem aceita ainda nao e
     * membro — e esse e exatamente o ponto. Exige estar logado, e o email da conta
     * tem de bater com o do convite.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', $token)->first();

        // Mesma resposta para token inexistente, vencido e ja aceito: um 422 que
        // distinguisse os tres contaria a um estranho se o token existe.
        if ($invitation === null || $invitation->accepted_at !== null || $invitation->expires_at->isPast()) {
            return response()->json(['message' => 'Convite invalido ou expirado.'], 422);
        }

        if (strtolower($request->user()->email) !== strtolower($invitation->email)) {
            return response()->json([
                'message' => 'Este convite e para outro email. Entre com a conta convidada.',
            ], 403);
        }

        $membro = DB::transaction(function () use ($invitation, $request) {
            $membro = WorkspaceMember::create([
                'workspace_id' => $invitation->workspace_id,
                'user_id' => $request->user()->id,
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);

            $invitation->update(['accepted_at' => now()]);

            return $membro;
        });

        return response()->json(['data' => $membro], 201);
    }
}
