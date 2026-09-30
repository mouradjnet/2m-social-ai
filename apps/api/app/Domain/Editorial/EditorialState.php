<?php

namespace App\Domain\Editorial;

use App\Models\Content;

/**
 * O estado editorial EXPLICITO (CP-03/CP-04), derivado do que ja existe — status da
 * peca, ultimo veredito do revisor de IA, ultima decisao humana e ultima publicacao —
 * sem coluna nova:
 *
 *   draft             idea ou production
 *   needs_revision    o revisor de IA reprovou, ou uma pessoa pediu ajustes
 *   in_review         em `review` sem veredito valido da IA
 *   pending_approval  aguardando decisao HUMANA: IA aprovou, ou a aprovacao antiga caiu
 *   approved          `approved` com aprovacao valida para a versao atual
 *   rejected          uma pessoa rejeitou (a peca foi arquivada)
 *   scheduled         agendada, publicacao pendente
 *   publishing        o Publisher esta falando com a Meta
 *   published         publicada
 *   failed            a publicacao falhou (ou ficou sem resposta da Meta)
 *   cancelled         a publicacao foi cancelada (desagendada, remarcada, porta fechou)
 *   archived          arquivada sem rejeicao
 *
 * O estado e LIDO pelo frontend, nunca escrito por ele: quem muda o status e o
 * servidor (ContentController, Approval, Publisher). Nenhum estado aqui aprova.
 */
class EditorialState
{
    public const STATES = [
        'draft', 'needs_revision', 'in_review', 'pending_approval', 'approved', 'rejected',
        'scheduled', 'publishing', 'published', 'failed', 'cancelled', 'archived',
    ];

    public static function for(Content $content): string
    {
        $decisao = $content->latestDecision;

        return match ($content->status) {
            'idea', 'production' => $decisao?->decision === 'changes_requested' ? 'needs_revision' : 'draft',
            'review' => self::review($content),
            'approved' => Approval::validApproval($content) !== null ? 'approved' : 'pending_approval',
            'scheduled' => self::publicacao($content),
            'archived' => $decisao?->decision === 'rejected' ? 'rejected' : 'archived',
            default => $content->status,
        };
    }

    private static function review(Content $content): string
    {
        $veredito = $content->latestReview;

        if ($veredito === null) {
            return 'in_review';
        }

        // O reescritor (ou uma edicao) mudou o texto depois do veredito: ele fala de
        // um texto que nao existe mais.
        $texto = $content->latestTextRevision;
        if ($texto !== null && $texto->created_at > $veredito->created_at) {
            return 'in_review';
        }

        return $veredito->verdict === 'fail' ? 'needs_revision' : 'pending_approval';
    }

    private static function publicacao(Content $content): string
    {
        return match ($content->latestPublication?->status) {
            'publishing' => 'publishing',
            'published' => 'published',
            'failed', 'unknown' => 'failed',
            'cancelled' => 'cancelled',
            default => 'scheduled',
        };
    }
}
