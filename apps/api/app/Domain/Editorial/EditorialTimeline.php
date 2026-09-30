<?php

namespace App\Domain\Editorial;

use App\Models\Content;
use App\Models\ContentDecision;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * CP-04C: a linha do tempo editorial de uma peca, montada dos tres registros
 * append-only que ja existem — sem tabela nova de eventos:
 *
 * - content_versions: criacao, edicao manual, IA, SEO, restauracao, e a queda da
 *   aprovacao quando a mudanca a derrubou;
 * - content_decisions: aprovar, rejeitar, pedir ajustes (com motivo);
 * - content_revisions: movimentos de status sem decisao (enviar para revisao,
 *   agendar, desagendar = cancelamento, arquivar, desarquivar, publicar).
 *
 * A decisao tambem grava um movimento em content_revisions; ele e pareado com a
 * decisao e nao aparece duas vezes.
 */
class EditorialTimeline
{
    /** @return list<array<string, mixed>> */
    public static function for(Content $content): array
    {
        $usuarios = [];
        $quem = function (?int $id) use (&$usuarios) {
            if ($id === null) {
                return null;
            }

            return $usuarios[$id] ??= User::select(['id', 'name'])->find($id);
        };

        $eventos = [];

        foreach (ContentVersion::where('content_id', $content->id)->orderBy('version')->get() as $v) {
            $eventos[] = [
                'type' => match (true) {
                    $v->origin === 'restore' => 'restored',
                    $v->origin === 'backfill' => 'version_recorded',
                    $v->version === 1 => 'created',
                    $v->origin === 'manual_edit' => 'manual_edit',
                    $v->origin === 'seo' => 'seo_applied',
                    in_array($v->origin, ['ai_generation', 'ai_rewrite', 'ai_image'], true) => 'ai_regeneration',
                    default => 'changed',
                },
                'origin' => $v->origin,
                'version' => $v->version,
                'restored_from_version' => $v->restored_from_version,
                'user' => $quem($v->user_id),
                'at' => $v->created_at,
            ];

            if ($v->invalidated_approval) {
                $eventos[] = [
                    'type' => 'approval_invalidated',
                    'version' => $v->version,
                    'user' => $quem($v->user_id),
                    'at' => $v->created_at,
                ];
            }
        }

        $decisoes = ContentDecision::where('content_id', $content->id)->orderBy('id')->get();
        $revisoes = ContentRevision::where('content_id', $content->id)->whereNotNull('to_status')->orderBy('id')->get();
        $usadas = [];

        foreach ($decisoes as $d) {
            $eventos[] = [
                'type' => $d->decision,
                'version' => $d->version,
                'reason' => $d->reason,
                'from_status' => $d->from_status,
                'to_status' => $d->to_status,
                'user' => $quem($d->user_id),
                'at' => $d->created_at,
            ];

            // O movimento que a propria decisao gravou (mesma transacao, mesmo autor).
            $par = $revisoes->first(fn (ContentRevision $r) => ! isset($usadas[$r->id])
                && $r->from_status === $d->from_status && $r->to_status === $d->to_status
                && (int) $r->user_id === (int) $d->user_id
                && abs(Carbon::parse($r->created_at)->getTimestamp() - $d->created_at->getTimestamp()) <= 5);

            if ($par !== null) {
                $usadas[$par->id] = true;
            }
        }

        foreach ($revisoes as $r) {
            if (isset($usadas[$r->id])) {
                continue;
            }

            $eventos[] = [
                'type' => match (true) {
                    $r->to_status === 'review' && $r->from_status !== 'approved' => 'sent_to_review',
                    $r->to_status === 'scheduled' => 'scheduled',
                    $r->from_status === 'scheduled' && $r->to_status !== 'published' => 'cancelled',
                    $r->to_status === 'archived' => 'archived',
                    $r->from_status === 'archived' => 'unarchived',
                    $r->to_status === 'published' => 'published',
                    default => 'status_changed',
                },
                'from_status' => $r->from_status,
                'to_status' => $r->to_status,
                'user' => $quem($r->user_id),
                'at' => Carbon::parse($r->created_at),
            ];
        }

        usort($eventos, fn ($a, $b) => strcmp((string) self::quando($a['at']), (string) self::quando($b['at'])));

        return $eventos;
    }

    private static function quando(mixed $at): string
    {
        return Carbon::parse($at)->utc()->format('Y-m-d H:i:s.u');
    }
}
