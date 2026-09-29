<?php

namespace App\Instagram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * O Instagram de mentira: conecta e "publica" sem sair da maquina.
 *
 * Existe para o desenvolvimento e o piloto rodarem o fluxo inteiro — conectar,
 * agendar, o worker publicar, o historico mostrar o id — sem uma credencial real e
 * sem nenhum post de verdade. `INSTAGRAM_DRIVER=graph` e a unica porta para a Meta.
 *
 * O estado dos containers vive no cache, para que "criar, consultar, publicar" se
 * comporte como a API: um container publicado duas vezes nao vira dois posts.
 */
class FakeInstagramGateway implements InstagramGateway
{
    public const USERNAME = 'conta_de_teste';

    public function authorizeUrl(string $state): string
    {
        // Pula o consentimento: volta direto para o callback, como se o usuario
        // tivesse autorizado.
        return config('instagram.redirect_uri').'?'.http_build_query(['code' => 'fake-code', 'state' => $state]);
    }

    public function exchangeCode(string $code): array
    {
        return [
            'access_token' => 'fake-short-'.Str::random(16),
            'user_id' => '17841400000000000',
            'permissions' => config('instagram.scopes'),
        ];
    }

    public function longLivedToken(string $shortToken): array
    {
        return ['access_token' => 'fake-long-'.Str::random(24), 'expires_in' => 60 * 24 * 3600];
    }

    public function refreshToken(string $token): array
    {
        return ['access_token' => 'fake-long-'.Str::random(24), 'expires_in' => 60 * 24 * 3600];
    }

    public function profile(string $token): array
    {
        return ['ig_user_id' => '17841400000000000', 'username' => self::USERNAME, 'account_type' => 'BUSINESS'];
    }

    public function createImageContainer(string $igUserId, string $token, string $imageUrl, string $caption): string
    {
        $id = 'fake-container-'.Str::random(12);
        Cache::put("instagram-fake:{$id}", ['status' => 'FINISHED', 'caption' => $caption], now()->addDay());

        return $id;
    }

    public function createCarouselItemContainer(string $igUserId, string $token, string $imageUrl): string
    {
        return $this->container(['status' => 'FINISHED', 'item' => $imageUrl]);
    }

    public function createCarouselContainer(string $igUserId, string $token, array $children, string $caption): string
    {
        return $this->container(['status' => 'FINISHED', 'caption' => $caption, 'children' => $children]);
    }

    public function createReelContainer(string $igUserId, string $token, string $videoUrl, string $caption, ?string $coverUrl): string
    {
        return $this->container(['status' => 'FINISHED', 'caption' => $caption, 'video_url' => $videoUrl]);
    }

    private function container(array $dados): string
    {
        $id = 'fake-container-'.Str::random(12);
        Cache::put("instagram-fake:{$id}", $dados, now()->addDay());

        return $id;
    }

    public function containerStatus(string $containerId, string $token): string
    {
        return Cache::get("instagram-fake:{$containerId}")['status'] ?? 'EXPIRED';
    }

    public function publishContainer(string $igUserId, string $token, string $containerId): string
    {
        $container = Cache::get("instagram-fake:{$containerId}");

        if ($container === null || $container['status'] !== 'FINISHED') {
            throw new InstagramException('Container não está pronto para publicar.', 'permanent', 400, 9007);
        }

        $mediaId = 'fake-media-'.Str::random(12);
        Cache::put("instagram-fake:{$containerId}", [...$container, 'status' => 'PUBLISHED', 'media_id' => $mediaId], now()->addDay());

        return $mediaId;
    }

    public function media(string $mediaId, string $token): array
    {
        return [
            'id' => $mediaId,
            'permalink' => 'https://www.instagram.com/p/'.Str::after($mediaId, 'fake-media-').'/',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function recentMedia(string $igUserId, string $token, int $limit = 10): array
    {
        return [];
    }

    public function publishingQuota(string $igUserId, string $token): array
    {
        return ['usage' => 0, 'total' => 100];
    }
}
