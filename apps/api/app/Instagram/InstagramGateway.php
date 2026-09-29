<?php

namespace App\Instagram;

/**
 * A fronteira com a Meta ("Instagram API with Instagram Login", ADR-14). Dois
 * adaptadores: `GraphInstagramGateway` (a API de verdade) e `FakeInstagramGateway`
 * (desenvolvimento e piloto sem publicar nada), como LlmProvider/MockProvider.
 *
 * Toda falha sai como InstagramException ja classificada.
 */
interface InstagramGateway
{
    /** A URL do consentimento. `state` volta no callback e amarra o retorno a quem pediu. */
    public function authorizeUrl(string $state): string;

    /**
     * Troca o `code` do callback por um token CURTO (1 hora).
     *
     * @return array{access_token: string, user_id: string, permissions: list<string>}
     */
    public function exchangeCode(string $code): array;

    /**
     * Token curto -> token longo (60 dias).
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function longLivedToken(string $shortToken): array;

    /**
     * Renova um token longo que tenha pelo menos 24 h e ainda nao venceu.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function refreshToken(string $token): array;

    /** @return array{ig_user_id: string, username: string, account_type: string} */
    public function profile(string $token): array;

    /** Cria o container de uma imagem. Devolve o id do container (ainda nao publicado). */
    public function createImageContainer(string $igUserId, string $token, string $imageUrl, string $caption): string;

    /** Um item de carrossel (`is_carousel_item`). Nao tem legenda: a legenda e do carrossel. */
    public function createCarouselItemContainer(string $igUserId, string $token, string $imageUrl): string;

    /**
     * O container do carrossel (`media_type=CAROUSEL`), com os itens na ordem.
     *
     * @param  list<string>  $children
     */
    public function createCarouselContainer(string $igUserId, string $token, array $children, string $caption): string;

    /** O container do Reel (`media_type=REELS`). A Meta processa o video: fica IN_PROGRESS por um tempo. */
    public function createReelContainer(string $igUserId, string $token, string $videoUrl, string $caption, ?string $coverUrl): string;

    /** `FINISHED`, `IN_PROGRESS`, `ERROR`, `EXPIRED` ou `PUBLISHED`. */
    public function containerStatus(string $containerId, string $token): string;

    /** Publica o container. Devolve o id da midia no Instagram. */
    public function publishContainer(string $igUserId, string $token, string $containerId): string;

    /** @return array{id: string, permalink: ?string, timestamp: ?string} */
    public function media(string $mediaId, string $token): array;

    /**
     * As ultimas midias da conta — para achar o post quando o resultado da
     * publicacao ficou desconhecido.
     *
     * @return list<array{id: string, caption: ?string, timestamp: ?string}>
     */
    public function recentMedia(string $igUserId, string $token, int $limit = 10): array;

    /**
     * As metricas de UMA midia publicada (`GET /{media-id}/insights`). Exige o escopo
     * `instagram_business_manage_insights`.
     *
     * @param  list<string>  $metrics
     * @return array<string, int|float> nome => valor
     */
    public function mediaInsights(string $mediaId, string $token, array $metrics): array;

    /** @return array{usage: int, total: int} publicacoes nas ultimas 24 h e o teto */
    public function publishingQuota(string $igUserId, string $token): array;
}
