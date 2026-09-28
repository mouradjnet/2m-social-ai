<?php

namespace App\Domain\Publishing;

use App\Models\Content;
use App\Models\ContentRevision;

/**
 * A ultima porta antes da rede (ADR-13): "a IA propoe, o humano aprova e o sistema
 * publica". O sistema so atravessa o que um humano aprovou — e aprovou ESTE texto.
 *
 * Nao basta o status. Uma peca `scheduled` pode ter tido o texto trocado depois da
 * aprovacao (SEO aplicado, reescrita enquanto arquivada): o status continua valido e
 * a aprovacao ja nao cobre o que vai ao ar. Por isso a aprovacao precisa ser mais
 * nova do que a ultima mudanca de texto.
 *
 * Devolve o motivo da recusa, em portugues, ou null quando pode publicar. O motivo
 * vai para o historico da publicacao — e o que o humano le quando nao saiu.
 */
class PublishGate
{
    public static function refusal(Content $content): ?string
    {
        if ($content->status !== 'scheduled') {
            return "A peça está em '{$content->status}', não agendada.";
        }

        if ($content->scheduled_for === null) {
            return 'A peça não tem data de publicação.';
        }

        if ($content->approved_by === null || $content->approved_at === null) {
            return 'A peça não tem aprovação humana registrada.';
        }

        // A ordem vem do id da revisao, nao do relogio: o historico e append-only, e
        // duas gravacoes no mesmo segundo nao empatam.
        $ultimaAprovacao = ContentRevision::where('content_id', $content->id)
            ->where('from_status', 'review')
            ->where('to_status', 'approved')
            ->max('id');

        // Remarcar grava `changes` so com `scheduled_for`: muda a data, nao o que vai ao
        // ar, e nao pede nova aprovacao. Qualquer outra chave (texto, imagem) pede.
        $ultimaMudancaDeTexto = ContentRevision::where('content_id', $content->id)
            ->whereRaw("(changes - 'scheduled_for') <> '{}'::jsonb")
            ->max('id');

        if ($ultimaAprovacao === null) {
            return 'A peça não tem aprovação humana registrada.';
        }

        if ($ultimaMudancaDeTexto !== null && $ultimaMudancaDeTexto > $ultimaAprovacao) {
            return 'O texto mudou depois da aprovação. Aprove de novo.';
        }

        return null;
    }
}
