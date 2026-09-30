<?php

namespace App\Domain\Publishing;

use App\Domain\Editorial\Approval;
use App\Jobs\PublishJob;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\PublicationAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Roda a cada minuto (`publications:dispatch`). Tres trabalhos:
 *
 * 1. Toda peca agendada para o Instagram cujo horario chegou vira uma publicacao —
 *    `pending`, ou ja `failed` com o motivo, se alguma porta estiver fechada.
 * 2. Publicacao `pending`/`unknown` cuja hora de tentar chegou vai para a fila.
 * 3. Publicacao presa em `publishing` (worker morreu no meio) vira `unknown`: nao se
 *    sabe se o media_publish saiu, entao a proxima rodada pergunta a Meta.
 *
 * Nao chama a Meta. Quem conversa com ela e o Publisher, dentro do job.
 */
class Dispatcher
{
    /** O formato da peca -> o `media_type` da Meta. */
    private const TIPOS = ['post' => 'IMAGE', 'carousel' => 'CAROUSEL', 'reel' => 'REELS'];

    /** Quanto tempo uma publicacao enfileirada fica reservada antes de ser reenfileirada. */
    private const LEASE_MINUTES = 3;

    public function run(): array
    {
        return [
            'criadas' => $this->createDue()->count(),
            'enfileiradas' => $this->enqueueReady(),
            'destravadas' => $this->recoverStuck(),
        ];
    }

    /** @return Collection<int, Publication> */
    public function createDue(): Collection
    {
        $vencidas = Content::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->where('channel', 'instagram')
            ->where('scheduled_for', '<=', now())
            // Um horario, uma publicacao: se ja existe uma para ESTE horario (mesmo
            // falha), o agendador nao tenta de novo. Remarcar abre um horario novo.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('publications')
                ->whereColumn('publications.content_id', 'contents.id')
                ->whereColumn('publications.scheduled_for', 'contents.scheduled_for'))
            ->with(['image', 'slides', 'video'])
            ->get();

        return $vencidas->map(fn (Content $c) => $this->prepare($c))->filter()->values();
    }

    /**
     * A publicacao nasce com o snapshot do que foi aprovado. Se alguma porta estiver
     * fechada, nasce `failed` com o motivo: o humano ve no historico por que nao saiu,
     * em vez de um post que simplesmente nao aconteceu.
     */
    public function prepare(Content $content): ?Publication
    {
        $conta = InstagramAccount::withoutGlobalScopes()
            ->where('project_id', $content->project_id)
            ->where('status', '<>', 'disconnected')
            ->first();

        $recusa = PublishGate::refusal($content)
            ?? Caption::refusal($content)
            ?? $this->semMidia($content)
            ?? $this->semConta($conta)
            ?? $this->atrasada($content);

        try {
            // Transacao propria: dentro de outra (teste, ou um chamador futuro), a
            // violacao de unicidade vira savepoint desfeito, nao transacao abortada.
            $publicacao = DB::transaction(fn () => Publication::create([
                'workspace_id' => $content->workspace_id,
                'project_id' => $content->project_id,
                'content_id' => $content->id,
                'instagram_account_id' => $conta?->id,
                'asset_id' => $content->format === 'reel' ? $content->video_asset_id : $content->image_asset_id,
                'media_type' => self::TIPOS[$content->format] ?? 'IMAGE',
                'media' => $this->midia($content),
                'caption' => Caption::compose($content),
                // Post: a imagem. Reel: a capa. Carrossel: o primeiro slide (o que abre o post).
                'image_url' => $content->format === 'carousel' ? $content->slides->first()?->url : $content->image?->url,
                'account_username' => $conta?->username,
                'approved_by' => $content->approved_by,
                'approved_at' => $content->approved_at,
                // CP-04: presa a versao e a aprovacao exatas; o Publisher confere de novo.
                'content_version' => $content->version,
                'decision_id' => Approval::validApproval($content)?->id,
                'scheduled_for' => $content->scheduled_for,
                'status' => $recusa === null ? 'pending' : 'failed',
                'error_kind' => $recusa === null ? null : 'refused',
                'last_error' => $recusa,
                'next_attempt_at' => $recusa === null ? now()->addMinutes(self::LEASE_MINUTES) : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Outro agendador criou esta publicacao no mesmo instante. A dele vale.
            return null;
        }

        if ($recusa !== null) {
            PublicationAttempt::create([
                'publication_id' => $publicacao->id, 'number' => 0,
                'step' => 'gate', 'outcome' => 'refused', 'message' => $recusa,
            ]);

            return $publicacao;
        }

        PublishJob::dispatch($publicacao->id);

        return $publicacao;
    }

    /** Reenfileira o que chegou a hora de tentar (espera de container, backoff, reconciliacao). */
    public function enqueueReady(): int
    {
        $prontas = Publication::withoutGlobalScopes()
            ->whereIn('status', ['pending', 'unknown'])
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->pluck('id');

        foreach ($prontas as $id) {
            // A reserva impede que o proximo minuto enfileire de novo o que ainda nao
            // saiu da fila. O job reivindica pelo status, entao duplicata nao publica
            // duas vezes — so evita trabalho a toa.
            Publication::withoutGlobalScopes()->whereKey($id)
                ->update(['next_attempt_at' => now()->addMinutes(self::LEASE_MINUTES)]);

            PublishJob::dispatch($id);
        }

        return $prontas->count();
    }

    public function recoverStuck(): int
    {
        $presas = Publication::withoutGlobalScopes()
            ->where('status', 'publishing')
            ->where('updated_at', '<', now()->subMinutes(config('publishing.stuck_after_minutes')))
            ->get();

        foreach ($presas as $publicacao) {
            $publicacao->update([
                'status' => 'unknown',
                'next_attempt_at' => now(),
                'error_kind' => 'unknown',
                'last_error' => 'O worker parou no meio da publicação. Conferindo com a Meta antes de qualquer nova tentativa.',
            ]);

            PublicationAttempt::create([
                'publication_id' => $publicacao->id, 'number' => $publicacao->attempts,
                'step' => 'recover', 'outcome' => 'unknown', 'message' => $publicacao->last_error,
            ]);
        }

        return $presas->count();
    }

    /**
     * O que cada formato precisa para ir ao ar (limites da Meta, 29/09/2026). Formato
     * sem tipo aqui nao e publicado pelo sistema — antes um `story` saia como post de
     * imagem, em silencio.
     */
    private function semMidia(Content $content): ?string
    {
        $slides = $content->slides->count();
        [$min, $max] = [config('media.carousel.min_items'), config('media.carousel.max_items')];

        return match ($content->format) {
            'post' => $content->image === null ? 'A peça não tem imagem. O Instagram não publica post sem imagem.' : null,
            'carousel' => $slides < $min || $slides > $max
                ? "O carrossel tem {$slides} imagens; o Instagram exige de {$min} a {$max}."
                : null,
            'reel' => $content->video === null ? 'O Reel não tem vídeo.' : null,
            default => "O formato '{$content->format}' não é publicado automaticamente no Instagram (só post, carrossel e Reel). Publique à mão pelo zip.",
        };
    }

    /** O snapshot das URLs aprovadas: e isto que o Publisher manda para a Meta. */
    private function midia(Content $content): ?array
    {
        return match ($content->format) {
            'carousel' => ['images' => $content->slides->pluck('url')->all()],
            'reel' => ['video_url' => $content->video?->url, 'cover_url' => $content->image?->url],
            default => null,
        };
    }

    private function semConta(?InstagramAccount $conta): ?string
    {
        if ($conta === null) {
            return 'O projeto não tem conta do Instagram conectada.';
        }

        return $conta->isUsable() ? null : "A conexão com @{$conta->username} venceu. Reconecte a conta.";
    }

    private function atrasada(Content $content): ?string
    {
        $limite = config('publishing.max_delay_minutes');

        return $content->scheduled_for->lt(now()->subMinutes($limite))
            ? sprintf('O horário passou há mais de %d horas sem publicar. Remarque a peça.', intdiv($limite, 60))
            : null;
    }
}
