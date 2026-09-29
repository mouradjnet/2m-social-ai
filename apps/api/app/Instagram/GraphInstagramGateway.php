<?php

namespace App\Instagram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A API oficial: "Instagram API with Instagram Login" em graph.instagram.com.
 *
 * Sem Pagina do Facebook no meio (ADR-14). O fluxo de publicacao e o de dois
 * passos da Meta: cria o container com a URL publica da imagem, espera ele ficar
 * FINISHED, publica.
 */
class GraphInstagramGateway implements InstagramGateway
{
    private const AUTHORIZE = 'https://www.instagram.com/oauth/authorize';

    private const TOKEN = 'https://api.instagram.com/oauth/access_token';

    private const GRAPH = 'https://graph.instagram.com';

    /**
     * Codigos da Meta que sao "espere e tente de novo": limites de taxa e
     * indisponibilidade temporaria. 190 e token invalido; o resto de 4xx e recusa.
     */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 341, 368, 613, 80002];

    /** "Voce atingiu o limite de publicacoes em 24 h": transitorio, volta amanha. */
    private const TRANSIENT_SUBCODES = [2207042, 2207001];

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $redirectUri,
        private readonly string $version,
        private readonly int $timeout,
    ) {}

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', config('instagram.scopes')),
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $json = $this->send(fn () => $this->http()->asForm()->post(self::TOKEN, [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            // A Meta anexa `#_` ao code no redirect; ele nao faz parte do code.
            'code' => preg_replace('/#_$/', '', $code),
        ]));

        // A resposta ja veio nos dois formatos: plano, ou embrulhado em `data[0]`.
        $grant = $json['data'][0] ?? $json;
        $permissoes = $grant['permissions'] ?? [];

        return [
            'access_token' => (string) $grant['access_token'],
            'user_id' => (string) $grant['user_id'],
            'permissions' => is_array($permissoes) ? $permissoes : explode(',', (string) $permissoes),
        ];
    }

    public function longLivedToken(string $shortToken): array
    {
        $json = $this->send(fn () => $this->http()->get(self::GRAPH.'/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->appSecret,
            'access_token' => $shortToken,
        ]));

        return ['access_token' => (string) $json['access_token'], 'expires_in' => (int) $json['expires_in']];
    }

    public function refreshToken(string $token): array
    {
        $json = $this->send(fn () => $this->http()->get(self::GRAPH.'/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]));

        return ['access_token' => (string) $json['access_token'], 'expires_in' => (int) $json['expires_in']];
    }

    public function profile(string $token): array
    {
        $json = $this->send(fn () => $this->http()->get($this->graph('me'), [
            'fields' => 'user_id,username,account_type',
            'access_token' => $token,
        ]));

        return [
            'ig_user_id' => (string) ($json['user_id'] ?? $json['id']),
            'username' => (string) $json['username'],
            'account_type' => (string) ($json['account_type'] ?? ''),
        ];
    }

    public function createImageContainer(string $igUserId, string $token, string $imageUrl, string $caption): string
    {
        $json = $this->send(fn () => $this->http()->asForm()->post($this->graph("{$igUserId}/media"), [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $token,
        ]));

        return (string) $json['id'];
    }

    public function createCarouselItemContainer(string $igUserId, string $token, string $imageUrl): string
    {
        $json = $this->send(fn () => $this->http()->asForm()->post($this->graph("{$igUserId}/media"), [
            'image_url' => $imageUrl,
            'is_carousel_item' => 'true',
            'access_token' => $token,
        ]));

        return (string) $json['id'];
    }

    public function createCarouselContainer(string $igUserId, string $token, array $children, string $caption): string
    {
        $json = $this->send(fn () => $this->http()->asForm()->post($this->graph("{$igUserId}/media"), [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $caption,
            'access_token' => $token,
        ]));

        return (string) $json['id'];
    }

    public function createReelContainer(string $igUserId, string $token, string $videoUrl, string $caption, ?string $coverUrl): string
    {
        $json = $this->send(fn () => $this->http()->asForm()->post($this->graph("{$igUserId}/media"), array_filter([
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => $caption,
            'cover_url' => $coverUrl,
            // Aparece no feed alem da aba Reels: e o que se espera de um post agendado.
            'share_to_feed' => 'true',
            'access_token' => $token,
        ], fn ($v) => $v !== null)));

        return (string) $json['id'];
    }

    public function mediaInsights(string $mediaId, string $token, array $metrics): array
    {
        $json = $this->send(fn () => $this->http()->get($this->graph("{$mediaId}/insights"), [
            'metric' => implode(',', $metrics),
            'access_token' => $token,
        ]));

        $valores = [];

        foreach ($json['data'] ?? [] as $linha) {
            // Metricas de ciclo de vida vem em `values[0].value`; as agregadas, em `total_value`.
            $valor = $linha['values'][0]['value'] ?? $linha['total_value']['value'] ?? null;

            if (isset($linha['name']) && is_numeric($valor)) {
                $valores[$linha['name']] = $valor + 0;
            }
        }

        return $valores;
    }

    public function containerStatus(string $containerId, string $token): string
    {
        $json = $this->send(fn () => $this->http()->get($this->graph($containerId), [
            'fields' => 'status_code',
            'access_token' => $token,
        ]));

        return (string) ($json['status_code'] ?? 'IN_PROGRESS');
    }

    public function publishContainer(string $igUserId, string $token, string $containerId): string
    {
        // O unico passo em que "nao sei" e diferente de "falhou": a Meta pode ter
        // publicado e a resposta se perdido. Timeout e 5xx aqui viram `unknown`.
        $json = $this->send(fn () => $this->http()->asForm()->post($this->graph("{$igUserId}/media_publish"), [
            'creation_id' => $containerId,
            'access_token' => $token,
        ]), incerto: true);

        return (string) $json['id'];
    }

    public function media(string $mediaId, string $token): array
    {
        $json = $this->send(fn () => $this->http()->get($this->graph($mediaId), [
            'fields' => 'id,permalink,timestamp',
            'access_token' => $token,
        ]));

        return [
            'id' => (string) $json['id'],
            'permalink' => $json['permalink'] ?? null,
            'timestamp' => $json['timestamp'] ?? null,
        ];
    }

    public function recentMedia(string $igUserId, string $token, int $limit = 10): array
    {
        $json = $this->send(fn () => $this->http()->get($this->graph("{$igUserId}/media"), [
            'fields' => 'id,caption,timestamp',
            'limit' => $limit,
            'access_token' => $token,
        ]));

        return array_map(fn (array $m) => [
            'id' => (string) $m['id'],
            'caption' => $m['caption'] ?? null,
            'timestamp' => $m['timestamp'] ?? null,
        ], $json['data'] ?? []);
    }

    public function publishingQuota(string $igUserId, string $token): array
    {
        $json = $this->send(fn () => $this->http()->get($this->graph("{$igUserId}/content_publishing_limit"), [
            'fields' => 'quota_usage,config',
            'access_token' => $token,
        ]));

        $linha = $json['data'][0] ?? [];

        return [
            'usage' => (int) ($linha['quota_usage'] ?? 0),
            'total' => (int) ($linha['config']['quota_total'] ?? 100),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout($this->timeout)->connectTimeout(10);
    }

    private function graph(string $path): string
    {
        return self::GRAPH."/{$this->version}/{$path}";
    }

    /**
     * Executa e classifica. `incerto` marca a chamada cujo efeito pode ter acontecido
     * mesmo sem resposta — so o media_publish.
     *
     * @param  callable(): Response  $chamada
     * @return array<string, mixed>
     */
    private function send(callable $chamada, bool $incerto = false): array
    {
        try {
            $response = $chamada();
        } catch (ConnectionException $e) {
            throw new InstagramException(
                'Sem resposta da Meta: '.$e->getMessage(),
                $incerto ? 'unknown' : 'transient',
            );
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $erro = (array) ($response->json('error') ?? []);
        $code = isset($erro['code']) ? (int) $erro['code'] : null;
        $subcode = isset($erro['error_subcode']) ? (int) $erro['error_subcode'] : null;
        $mensagem = (string) ($erro['error_user_msg'] ?? $erro['message'] ?? "HTTP {$response->status()}");

        throw new InstagramException(
            $mensagem,
            $this->classify($response->status(), $code, $subcode, (bool) ($erro['is_transient'] ?? false), $incerto),
            $response->status(),
            $code,
            $subcode,
        );
    }

    private function classify(int $status, ?int $code, ?int $subcode, bool $isTransient, bool $incerto): string
    {
        if ($code === 190 || $status === 401) {
            return 'auth';
        }

        if ($status >= 500) {
            return $incerto ? 'unknown' : 'transient';
        }

        if ($isTransient || $status === 429
            || in_array($code, self::TRANSIENT_CODES, true)
            || in_array($subcode, self::TRANSIENT_SUBCODES, true)) {
            return 'transient';
        }

        return 'permanent';
    }
}
