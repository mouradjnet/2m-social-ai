<?php

namespace App\Domain\Publishing;

use App\Instagram\InstagramException;
use App\Instagram\InstagramGateway;
use App\Models\ContentRevision;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\PublicationAttempt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uma rodada de uma publicacao: reivindica, conversa com a Meta ate onde der, e
 * devolve a publicacao a um estado estavel (`pending` com hora de voltar,
 * `published`, `failed` ou `unknown`).
 *
 * A regra que sustenta a idempotencia: o media_publish so e chamado depois de
 * perguntar a Meta o estado do container. Um container `PUBLISHED` nao e publicado
 * de novo — e a Meta nao publica o mesmo container duas vezes. Entao, mesmo que a
 * resposta do media_publish se perca, a proxima rodada descobre o que houve em vez
 * de repetir as cegas.
 */
class Publisher
{
    private Publication $publicacao;

    private int $numero;

    /** Verdadeiro entre enviar o media_publish e ter a resposta. */
    private bool $publicandoAgora = false;

    public function __construct(private readonly InstagramGateway $gateway) {}

    public function run(int $publicationId): void
    {
        $publicacao = Publication::withoutGlobalScopes()->find($publicationId);

        if ($publicacao === null || ! in_array($publicacao->status, ['pending', 'unknown'], true)) {
            return;
        }

        $anterior = $publicacao->status;

        // Reivindicacao atomica: de dois workers com o mesmo job, so um passa daqui.
        $reivindicou = Publication::withoutGlobalScopes()
            ->whereKey($publicacao->id)
            ->where('status', $anterior)
            ->update(['status' => 'publishing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($reivindicou === 0) {
            return;
        }

        $this->publicacao = $publicacao->refresh();
        $this->numero = $this->publicacao->attempts;

        try {
            $this->avancar($anterior === 'unknown');
        } catch (InstagramException $e) {
            $this->falhou($e);
        } catch (Throwable $e) {
            // Um bug nosso no meio do caminho. Se foi durante o media_publish, nao se
            // sabe se saiu: `unknown`. Antes disso, e so tentar de novo.
            $this->falhou(new InstagramException(
                'Erro interno: '.$e->getMessage(),
                $this->publicandoAgora ? 'unknown' : 'transient',
            ));

            report($e);
        }
    }

    private function avancar(bool $reconciliar): void
    {
        $content = $this->publicacao->content()->withoutGlobalScopes()->first();
        $conta = InstagramAccount::withoutGlobalScopes()->find($this->publicacao->instagram_account_id);

        // Desagendada ou remarcada enquanto esperava — e nada foi enviado ainda.
        if (! $reconciliar && $this->publicacao->container_id === null
            && ($content === null || $content->status !== 'scheduled'
                || ! $content->scheduled_for?->equalTo($this->publicacao->scheduled_for))) {
            $this->encerrar('cancelled', 'A peça foi desagendada ou remarcada antes de publicar.', 'gate', 'refused');

            return;
        }

        if ($conta === null || ! $conta->isUsable()) {
            throw new InstagramException('A conexão com o Instagram não está ativa. Reconecte a conta.', 'auth');
        }

        $token = (string) $conta->access_token;

        if ($reconciliar) {
            $this->reconciliar($conta, $token);

            return;
        }

        if ($this->publicacao->container_id === null) {
            $cota = $this->gateway->publishingQuota($conta->ig_user_id, $token);

            if ($cota['usage'] >= $cota['total']) {
                throw new InstagramException(
                    "Limite de {$cota['total']} publicações em 24 h atingido. Tentando mais tarde.",
                    'transient',
                );
            }

            $container = $this->criarContainer($conta->ig_user_id, $token);

            $this->publicacao->update(['container_id' => $container]);
            $this->registrar('container', 'success', "Container {$container} criado.");
        }

        $this->seguirContainer($conta, $token);
    }

    /**
     * O unico passo que muda por formato. Daqui em diante (estado, media_publish,
     * reconciliacao) um container e um container.
     *
     * Carrossel: um container por imagem (`is_carousel_item`), depois o do carrossel
     * com os filhos na ordem. Se o processo morrer entre os dois, os filhos orfaos
     * expiram sozinhos na Meta em 24 h; nada foi publicado.
     */
    private function criarContainer(string $igUserId, string $token): string
    {
        $p = $this->publicacao;

        return match ($p->media_type) {
            'CAROUSEL' => $this->gateway->createCarouselContainer(
                $igUserId,
                $token,
                array_map(
                    fn (string $url) => $this->gateway->createCarouselItemContainer($igUserId, $token, $url),
                    $p->media['images'] ?? [],
                ),
                $p->caption,
            ),
            'REELS' => $this->gateway->createReelContainer(
                $igUserId, $token, (string) ($p->media['video_url'] ?? ''), $p->caption, $p->media['cover_url'] ?? null,
            ),
            default => $this->gateway->createImageContainer($igUserId, $token, (string) $p->image_url, $p->caption),
        };
    }

    /** Pergunta o estado do container e age conforme a resposta. */
    private function seguirContainer(InstagramAccount $conta, string $token): void
    {
        $estado = $this->gateway->containerStatus((string) $this->publicacao->container_id, $token);

        match ($estado) {
            'FINISHED' => $this->publicar($conta, $token),
            'PUBLISHED' => $this->jaPublicado($conta, $token),
            'IN_PROGRESS' => $this->esperarContainer(),
            'EXPIRED' => $this->recomecar('O container expirou antes de publicar. Um novo será criado.'),
            default => throw new InstagramException("A Meta recusou a mídia (container {$estado}).", 'permanent'),
        };
    }

    private function publicar(InstagramAccount $conta, string $token): void
    {
        $this->publicandoAgora = true;
        $mediaId = $this->gateway->publishContainer($conta->ig_user_id, $token, (string) $this->publicacao->container_id);
        $this->publicandoAgora = false;

        $this->registrar('publish', 'success', "Publicado: mídia {$mediaId}.");
        $this->concluir($mediaId, $token);
    }

    /**
     * Depois de um `unknown`: o media_publish pode ter saido. O estado do container
     * diz: PUBLISHED = saiu; FINISHED = nao saiu, e e seguro publicar; EXPIRED so
     * acontece com container nao publicado.
     */
    private function reconciliar(InstagramAccount $conta, string $token): void
    {
        if ($this->publicacao->container_id === null) {
            // Nem o container chegou a existir: nada foi publicado.
            $this->recomecar('Nenhum container registrado; recomeçando com segurança.');

            return;
        }

        $this->registrar('reconcile', 'waiting', 'Conferindo com a Meta o resultado da tentativa anterior.');
        $this->seguirContainer($conta, $token);
    }

    /** O container ja aparece publicado: acha a midia pela legenda e conclui. */
    private function jaPublicado(InstagramAccount $conta, string $token): void
    {
        $midia = collect($this->gateway->recentMedia($conta->ig_user_id, $token))
            ->first(fn (array $m) => trim((string) $m['caption']) === trim($this->publicacao->caption));

        $this->registrar('reconcile', 'success', $midia === null
            ? 'A Meta confirma a publicação, mas o post não foi localizado entre os recentes.'
            : "A Meta confirma a publicação: mídia {$midia['id']}.");

        $this->concluir($midia['id'] ?? null, $token);
    }

    private function esperarContainer(): void
    {
        $esperas = PublicationAttempt::where('publication_id', $this->publicacao->id)
            ->where('step', 'status')->where('outcome', 'waiting')->count();

        // Video leva minutos para processar; imagem, segundos.
        $limite = $this->publicacao->media_type === 'REELS'
            ? config('publishing.max_container_polls_video')
            : config('publishing.max_container_polls');

        if ($esperas >= $limite) {
            throw new InstagramException('A Meta não terminou de processar a mídia a tempo.', 'permanent');
        }

        $this->registrar('status', 'waiting', 'A Meta ainda processa a mídia.');
        $this->publicacao->update([
            'status' => 'pending',
            'next_attempt_at' => now()->addMinutes(config('publishing.container_poll_minutes')),
        ]);
    }

    private function recomecar(string $motivo): void
    {
        $this->registrar('status', 'waiting', $motivo);
        $this->publicacao->update(['status' => 'pending', 'container_id' => null, 'next_attempt_at' => now()]);
    }

    /**
     * Publicado. A peca anda para `published` em nome de quem aprovou (ADR-11 e 13:
     * nao existe ator "sistema" no historico); o fato de ter sido automatico esta
     * na publicacao.
     */
    private function concluir(?string $mediaId, string $token): void
    {
        $permalink = null;

        if ($mediaId !== null) {
            try {
                $permalink = $this->gateway->media($mediaId, $token)['permalink'];
            } catch (InstagramException) {
                // O link e conforto, nao prova. O post saiu; sem permalink, tudo bem.
            }
        }

        DB::transaction(function () use ($mediaId, $permalink) {
            $this->publicacao->update([
                'status' => 'published',
                'media_id' => $mediaId,
                'permalink' => $permalink,
                'published_at' => now(),
                'next_attempt_at' => null,
                'error_kind' => null,
                'last_error' => null,
            ]);

            $content = $this->publicacao->content()->withoutGlobalScopes()->first();

            if ($content !== null && $content->status === 'scheduled') {
                $content->update(['status' => 'published', 'published_at' => now()]);
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $this->publicacao->approved_by,
                    'from_status' => 'scheduled',
                    'to_status' => 'published',
                ]);
            }
        });
    }

    private function falhou(InstagramException $e): void
    {
        $this->registrar($this->publicandoAgora ? 'publish' : 'meta', $e->kind, $e->getMessage(), $e);

        if ($e->kind === 'unknown') {
            $this->publicacao->update([
                'status' => 'unknown',
                'error_kind' => 'unknown',
                'last_error' => 'A Meta não confirmou a publicação. Conferindo antes de qualquer nova tentativa.',
                'next_attempt_at' => now()->addMinutes(config('publishing.reconcile_after_minutes')),
            ]);

            return;
        }

        if ($e->kind === 'auth') {
            InstagramAccount::withoutGlobalScopes()
                ->whereKey($this->publicacao->instagram_account_id)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'last_error' => $e->getMessage()]);
        }

        $backoff = config('publishing.backoff_minutes');
        $transitorias = PublicationAttempt::where('publication_id', $this->publicacao->id)
            ->where('outcome', 'transient')->count();

        // Uma vez incerta, sempre incerta ate alguem ter certeza. Se a publicacao ja
        // teve um media_publish sem resposta, ela nunca vira `failed` sozinha: `failed`
        // oferece "tentar de novo", e tentar de novo o que pode estar no ar e postar em
        // dobro. Quando a conferencia nao conclui, um humano olha o perfil e decide.
        $incerta = PublicationAttempt::where('publication_id', $this->publicacao->id)
            ->where('outcome', 'unknown')->exists();

        if ($incerta) {
            $insiste = $e->kind === 'transient' && $transitorias <= count($backoff);

            $this->publicacao->update([
                'status' => 'unknown',
                'error_kind' => 'unknown',
                'last_error' => $insiste
                    ? 'Conferindo com a Meta se o post saiu: '.$e->getMessage()
                    : 'Não foi possível confirmar com a Meta se o post saiu. Confira o perfil e decida.',
                'next_attempt_at' => $insiste ? now()->addMinutes($backoff[$transitorias - 1]) : null,
            ]);

            return;
        }

        if ($e->kind === 'transient' && $transitorias <= count($backoff)) {
            $this->publicacao->update([
                'status' => 'pending',
                'error_kind' => 'transient',
                'last_error' => $e->getMessage(),
                'next_attempt_at' => now()->addMinutes($backoff[$transitorias - 1]),
            ]);

            return;
        }

        $this->encerrar('failed', $e->getMessage(), null, null, $e->kind);
    }

    private function encerrar(string $status, string $motivo, ?string $passo, ?string $resultado, ?string $tipo = 'refused'): void
    {
        if ($passo !== null) {
            $this->registrar($passo, (string) $resultado, $motivo);
        }

        $this->publicacao->update([
            'status' => $status,
            'error_kind' => $tipo,
            'last_error' => $motivo,
            'next_attempt_at' => null,
        ]);
    }

    private function registrar(string $passo, string $resultado, string $mensagem, ?InstagramException $e = null): void
    {
        PublicationAttempt::create([
            'publication_id' => $this->publicacao->id,
            'number' => $this->numero,
            'step' => $passo,
            'outcome' => $resultado,
            'http_status' => $e?->httpStatus,
            'meta_code' => $e?->metaCode,
            'meta_subcode' => $e?->metaSubcode,
            'message' => $mensagem,
        ]);
    }
}
