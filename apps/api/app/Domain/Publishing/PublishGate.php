<?php

namespace App\Domain\Publishing;

use App\Domain\Editorial\Approval;
use App\Models\Content;
use App\Models\Publication;

/**
 * A ultima porta antes da rede (ADR-13, CP-04): "a IA propoe, o humano aprova e o
 * sistema publica". O sistema so atravessa o que um humano aprovou — ESTA versao,
 * com ESTE conteudo.
 *
 * Fail-closed: a aprovacao so vale se a ultima decisao humana e `approved`, da
 * versao atual, e o sha256 do snapshot aprovado bate com o da peca agora (texto,
 * hashtags, CTA, roteiro, midia e a legenda exatamente como vai para a Meta).
 * Aprovacao antiga, sem snapshot (antes do CP-04), nao vale: aprove de novo.
 *
 * Roda no Dispatcher (ao preparar), no agendamento e no Publisher (antes de cada
 * envio). Devolve o motivo da recusa, em portugues, ou null quando pode seguir.
 */
class PublishGate
{
    /** A aprovacao da peca como ela esta agora. Nao olha status: serve para agendar. */
    public static function approvalRefusal(Content $content): ?string
    {
        if ($content->approved_by === null || $content->approved_at === null) {
            return 'A peça não tem aprovação humana registrada.';
        }

        if (Approval::validApproval($content) === null) {
            return 'A aprovação não vale para esta versão da peça (o conteúdo mudou, ou foi aprovada antes do controle de versões). Aprove de novo.';
        }

        return null;
    }

    public static function refusal(Content $content): ?string
    {
        if ($content->status !== 'scheduled') {
            return "A peça está em '{$content->status}', não agendada.";
        }

        if ($content->scheduled_for === null) {
            return 'A peça não tem data de publicação.';
        }

        return self::approvalRefusal($content);
    }

    /**
     * Antes de mandar para a Meta: a publicacao preparada ainda e o que foi aprovado?
     * (Uma retentativa pode rodar horas depois de preparada.)
     */
    public static function publicationRefusal(Publication $publicacao, ?Content $content): ?string
    {
        if ($content === null) {
            return 'A peça não existe mais.';
        }

        if ((int) $publicacao->project_id !== (int) $content->project_id) {
            return 'A publicação não pertence ao projeto da peça.';
        }

        if ($recusa = self::refusal($content)) {
            return $recusa;
        }

        $aprovacao = Approval::validApproval($content);

        if ($publicacao->decision_id === null || (int) $publicacao->decision_id !== (int) $aprovacao?->id) {
            return 'A publicação não está presa à aprovação vigente. Aprove de novo.';
        }

        if ((int) $publicacao->content_version !== (int) $content->version) {
            return 'A publicação foi preparada para outra versão da peça.';
        }

        if ($publicacao->caption !== Caption::compose($content)
            || $publicacao->caption !== ($aprovacao->snapshot['published_caption'] ?? null)) {
            return 'A legenda da publicação não é a aprovada.';
        }

        return null;
    }
}
