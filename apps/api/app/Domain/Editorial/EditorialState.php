<?php

namespace App\Domain\Editorial;

use App\Models\Content;

/**
 * O estado editorial EXPLICITO (CP-03), derivado do que ja existe — sem coluna nova:
 *
 *   draft              idea ou production (ainda sendo escrita)
 *   in_review          em `review` sem veredito valido (nenhum, ou o texto mudou depois)
 *   needs_revision     em `review` e o ultimo veredito, sobre este texto, foi `fail`
 *   ready_for_approval em `review` e o ultimo veredito, sobre este texto, foi `pass`
 *   approved / scheduled / published / archived: o proprio status
 *
 * Ready_for_approval NAO aprova: aprovar continua gesto humano (ADR-13), e nenhum
 * estado aqui publica nada.
 */
class EditorialState
{
    public const STATES = [
        'draft', 'in_review', 'needs_revision', 'ready_for_approval',
        'approved', 'scheduled', 'published', 'archived',
    ];

    public static function for(Content $content): string
    {
        return match ($content->status) {
            'idea', 'production' => 'draft',
            'review' => self::review($content),
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

        return $veredito->verdict === 'fail' ? 'needs_revision' : 'ready_for_approval';
    }
}
