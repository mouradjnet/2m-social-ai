<?php

namespace App\Domain\Editorial;

use App\Domain\Publishing\Caption;
use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentRevision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * CP-04: a decisao humana sobre UMA versao de uma peca. Regra inegociavel: nada e
 * agendado nem publicado sem aprovacao humana explicita, valida e presa a versao
 * exata do conteudo.
 *
 * - A aprovacao exige a `version` que a pessoa viu; se a peca mudou nesse meio
 *   tempo, e recusada (ApprovalConflict -> 409). Trava a linha (lockForUpdate): uma
 *   edicao concorrente espera ou e esperada, nunca se mistura.
 * - A aprovacao grava o SNAPSHOT do que vai ao ar e o sha256 dele. validApproval()
 *   so reconhece a aprovacao se a versao e o hash da peca ATUAL baterem.
 * - Fail-closed: sem decisao, com decisao de outra versao, ou com hash diferente, nao
 *   ha aprovacao.
 *
 * Nenhum agente chama isto: so os endpoints humanos (ContentDecisionController).
 */
class Approval
{
    /** O que vai ao ar e o que foi julgado. Mudar qualquer um invalida a aprovacao. */
    public static function snapshot(Content $content): array
    {
        return [
            'content_id' => $content->id,
            'project_id' => $content->project_id,
            'version' => (int) $content->version,
            'format' => $content->format,
            'channel' => $content->channel,
            'title' => (string) $content->title,
            'caption' => (string) $content->caption,
            'cta' => (string) $content->cta,
            'hashtags' => array_values($content->hashtags ?? []),
            'structure' => $content->structure,
            'image_asset_id' => $content->image_asset_id,
            'video_asset_id' => $content->video_asset_id,
            'slide_asset_ids' => $content->slides()->pluck('assets.id')->map(fn ($id) => (int) $id)->all(),
            // A legenda exatamente como o Publisher a manda: e ela que a Meta recebe.
            'published_caption' => Caption::compose($content),
        ];
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::ordenado($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** A aprovacao que vale para a peca como ela esta AGORA, ou null (fail-closed). */
    public static function validApproval(Content $content): ?ContentDecision
    {
        $ultima = ContentDecision::where('content_id', $content->id)->latest('id')->first();

        if ($ultima === null || $ultima->decision !== 'approved') {
            return null;
        }

        if ((int) $ultima->version !== (int) $content->version) {
            return null;
        }

        if ($ultima->snapshot_hash === null || ! hash_equals($ultima->snapshot_hash, self::hash(self::snapshot($content)))) {
            return null;
        }

        return $ultima;
    }

    /**
     * CP-04A. So peca em `pending_approval` (a IA revisou e aprovou ESTA versao, ou a
     * aprovacao antiga caiu) pode ser aprovada.
     *
     * @throws ApprovalConflict estado ou versao nao batem (409)
     * @throws IdempotencyConflict chave reutilizada com outro pedido (422)
     */
    public static function approve(Content $content, int $version, User $user, string $requestKey): ApprovalOutcome
    {
        return self::comChave($user, $requestKey, "approve|{$content->id}|{$version}",
            fn (string $impressao) => self::decidir($content, $version, $user, 'approved', null, 'approved', $requestKey, $impressao));
    }

    /**
     * CP-04B. Rejeitar tira a peca do fluxo (arquivada), com o motivo registrado. Uma
     * rejeitada nunca volta direto para aprovada: desarquivar a devolve a producao, e
     * ela passa de novo por revisao e aprovacao.
     */
    public static function reject(Content $content, int $version, User $user, string $reason, string $requestKey): ApprovalOutcome
    {
        return self::comChave($user, $requestKey, "reject|{$content->id}|{$version}|".hash('sha256', $reason),
            fn (string $impressao) => self::decidir($content, $version, $user, 'rejected', $reason, 'archived', $requestKey, $impressao));
    }

    /**
     * CP-04B. Pedir ajustes devolve a peca para producao, com o que precisa mudar. Se
     * ela estava aprovada, a aprovacao cai (a ultima decisao deixa de ser `approved`).
     */
    public static function requestChanges(Content $content, int $version, User $user, string $reason, string $requestKey): ApprovalOutcome
    {
        return self::comChave($user, $requestKey, "changes|{$content->id}|{$version}|".hash('sha256', $reason),
            fn (string $impressao) => self::decidir($content, $version, $user, 'changes_requested', $reason, 'production', $requestKey, $impressao));
    }

    /**
     * Idempotencia pela `request_key` (gerada pelo cliente por intencao de decidir): a
     * mesma chave com o mesmo pedido devolve a decisao original, sem gravar nada; com
     * outro pedido, IdempotencyConflict. Duas requisicoes simultaneas com a mesma
     * chave: o indice unico deixa passar uma, e a outra devolve a que ficou.
     *
     * @param  callable(string): ContentDecision  $decidir
     */
    private static function comChave(User $user, string $requestKey, string $pedido, callable $decidir): ApprovalOutcome
    {
        $impressao = hash('sha256', $pedido);

        if ($original = self::pedidoAnterior($user, $requestKey, $impressao)) {
            return new ApprovalOutcome($original, replayed: true);
        }

        try {
            return new ApprovalOutcome($decidir($impressao), replayed: false);
        } catch (UniqueConstraintViolationException) {
            return new ApprovalOutcome(self::pedidoAnterior($user, $requestKey, $impressao)
                ?? throw new IdempotencyConflict('Chave de requisição em uso. Tente de novo.'), replayed: true);
        }
    }

    private static function pedidoAnterior(User $user, string $requestKey, string $impressao): ?ContentDecision
    {
        $anterior = ContentDecision::where('user_id', $user->id)->where('request_key', $requestKey)->first();

        if ($anterior !== null && ! hash_equals((string) $anterior->request_fingerprint, $impressao)) {
            throw new IdempotencyConflict('Esta chave de requisição já foi usada para outro pedido. Gere uma nova e confira a peça.');
        }

        return $anterior;
    }

    private static function decidir(
        Content $content, int $version, User $user, string $decisao, ?string $motivo, string $para,
        ?string $requestKey = null, ?string $impressao = null,
    ): ContentDecision {
        return DB::transaction(function () use ($content, $version, $user, $decisao, $motivo, $para, $requestKey, $impressao) {
            // Relida COM trava: a versao conferida e a gravada sao a mesma.
            $atual = Content::withoutGlobalScopes()->whereKey($content->id)->lockForUpdate()->firstOrFail();

            // Aprovar: em revisao, ou `approved` cuja aprovacao CAIU. Rejeitar e pedir
            // ajustes (CP-04B): em revisao ou aprovada (a aprovacao cai junto). Agendada
            // se desagenda antes: ha uma publicacao preparada para ela.
            $aprovadaSemValidade = $atual->status === 'approved' && self::validApproval($atual) === null;
            $podeDecidir = $decisao === 'approved'
                ? $atual->status === 'review' || $aprovadaSemValidade
                : in_array($atual->status, ['review', 'approved'], true);

            if (! $podeDecidir) {
                throw new ApprovalConflict($atual->status === 'scheduled'
                    ? 'A peça está agendada: desagende antes de decidir sobre ela.'
                    : "A peça está em '{$atual->status}': esta decisão não se aplica a ela.");
            }

            $de = $atual->status;

            // CP-04A: aprovar exige `pending_approval` — nao se aprova o que a IA
            // reprovou ou ainda nao revisou nesta versao.
            if ($decisao === 'approved' && ($estado = EditorialState::for($atual)) !== 'pending_approval') {
                throw new ApprovalConflict("A peça está em '{$estado}': só peça aguardando aprovação humana (revisada pela IA nesta versão) pode ser aprovada.");
            }

            if ((int) $atual->version !== $version) {
                throw new ApprovalConflict(
                    "A peça mudou (versão {$atual->version}) desde que você a abriu (versão {$version}). Confira a versão atual antes de decidir.",
                );
            }

            $snapshot = $decisao === 'approved' ? self::snapshot($atual) : null;

            $registro = ContentDecision::create([
                'workspace_id' => $atual->workspace_id,
                'project_id' => $atual->project_id,
                'content_id' => $atual->id,
                'version' => $version,
                'decision' => $decisao,
                'reason' => $motivo,
                'from_status' => $de,
                'to_status' => $para,
                'snapshot' => $snapshot,
                'snapshot_hash' => $snapshot === null ? null : self::hash($snapshot),
                'user_id' => $user->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $impressao,
            ]);

            // Mudar status nao e mudar conteudo: a versao fica (ver Content::booted()).
            $atual->update([
                'status' => $para,
                'approved_by' => $decisao === 'approved' ? $user->id : null,
                'approved_at' => $decisao === 'approved' ? now() : null,
            ]);

            ContentRevision::create([
                'content_id' => $atual->id,
                'user_id' => $user->id,
                'from_status' => $de,
                'to_status' => $para,
            ]);

            return $registro;
        });
    }

    /** Chaves em ordem: o mesmo conteudo da o mesmo hash, venha na ordem que vier. */
    private static function ordenado(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return array_map(fn ($v) => self::ordenado($v), $valor);
    }
}
