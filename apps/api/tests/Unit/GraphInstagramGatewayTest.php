<?php

namespace Tests\Unit;

use App\Instagram\GraphInstagramGateway;
use App\Instagram\InstagramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A classificacao das falhas da Meta. E ela que decide entre tentar de novo, desistir,
 * pedir reconexao — ou parar e perguntar antes de publicar de novo.
 */
class GraphInstagramGatewayTest extends TestCase
{
    private const PUBLISH = 'https://graph.instagram.com/v23.0/1784/media_publish';

    private const CONTAINER = 'https://graph.instagram.com/v23.0/1784/media';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function gateway(): GraphInstagramGateway
    {
        return new GraphInstagramGateway('app', 'segredo', 'https://x.test/cb', 'v23.0', 30);
    }

    private function falha(callable $chamada): InstagramException
    {
        try {
            $chamada();
        } catch (InstagramException $e) {
            return $e;
        }

        $this->fail('Esperava InstagramException.');
    }

    public function test_cria_container_e_publica(): void
    {
        Http::fake([
            self::CONTAINER => Http::response(['id' => 'container-1']),
            self::PUBLISH => Http::response(['id' => 'midia-1']),
        ]);

        $g = $this->gateway();
        $this->assertSame('container-1', $g->createImageContainer('1784', 'tk', 'https://img.test/a.jpg', 'Legenda'));
        $this->assertSame('midia-1', $g->publishContainer('1784', 'tk', 'container-1'));

        Http::assertSent(fn ($r) => $r->url() === self::CONTAINER
            && $r['image_url'] === 'https://img.test/a.jpg'
            && $r['caption'] === 'Legenda');
        Http::assertSent(fn ($r) => $r->url() === self::PUBLISH && $r['creation_id'] === 'container-1');
    }

    /** @return array<string, array{int, array<string, mixed>, string}> */
    public static function falhasDaMeta(): array
    {
        return [
            'token invalido' => [400, ['code' => 190, 'message' => 'Invalid OAuth access token'], 'auth'],
            'limite de taxa' => [400, ['code' => 4, 'message' => 'Application request limit reached'], 'transient'],
            'limite de publicacoes em 24h' => [400, ['code' => 9, 'error_subcode' => 2207042, 'message' => 'limit'], 'transient'],
            'meta diz que e transitorio' => [400, ['code' => 100, 'is_transient' => true, 'message' => 'x'], 'transient'],
            'imagem recusada' => [400, ['code' => 36003, 'error_subcode' => 2207009, 'message' => 'aspect ratio'], 'permanent'],
            'erro de servidor antes de publicar' => [500, ['code' => 1, 'message' => 'unknown'], 'transient'],
        ];
    }

    #[DataProvider('falhasDaMeta')]
    public function test_classifica_as_falhas_do_container(int $status, array $erro, string $esperado): void
    {
        Http::fake([self::CONTAINER => Http::response(['error' => $erro], $status)]);

        $e = $this->falha(fn () => $this->gateway()->createImageContainer('1784', 'tk', 'u', 'c'));

        $this->assertSame($esperado, $e->kind);
        $this->assertSame($status, $e->httpStatus);
    }

    /**
     * O coracao da idempotencia: 5xx ou timeout NO media_publish nao e "falhou" — a
     * Meta pode ter publicado. Fica `unknown`, e ninguem repete sem perguntar antes.
     */
    public function test_erro_de_servidor_ao_publicar_e_desconhecido(): void
    {
        Http::fake([self::PUBLISH => Http::response(['error' => ['code' => 2, 'message' => 'x']], 502)]);

        $this->assertSame('unknown', $this->falha(fn () => $this->gateway()->publishContainer('1784', 'tk', 'c'))->kind);
    }

    public function test_timeout_ao_publicar_e_desconhecido_e_nao_vaza_o_token(): void
    {
        Http::fake([self::PUBLISH => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://graph.instagram.com/v23.0/1784/media_publish?access_token=SEGREDO123&creation_id=c'
        )]);

        $e = $this->falha(fn () => $this->gateway()->publishContainer('1784', 'SEGREDO123', 'c'));

        $this->assertSame('unknown', $e->kind);
        $this->assertStringNotContainsString('SEGREDO123', $e->getMessage());
        $this->assertStringContainsString('access_token=***', $e->getMessage());
    }

    public function test_timeout_fora_do_publish_e_transitorio(): void
    {
        Http::fake([self::CONTAINER => fn () => throw new ConnectionException('timeout')]);

        $this->assertSame('transient', $this->falha(fn () => $this->gateway()->createImageContainer('1784', 'tk', 'u', 'c'))->kind);
    }

    public function test_le_a_cota_de_publicacao(): void
    {
        Http::fake(['https://graph.instagram.com/v23.0/1784/content_publishing_limit*' => Http::response([
            'data' => [['quota_usage' => 7, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]],
        ])]);

        $this->assertSame(['usage' => 7, 'total' => 100], $this->gateway()->publishingQuota('1784', 'tk'));
    }

    public function test_mensagem_amigavel_da_meta_tem_preferencia(): void
    {
        Http::fake([self::CONTAINER => Http::response(['error' => [
            'code' => 36003, 'message' => 'Invalid aspect ratio', 'error_user_msg' => 'A proporção da imagem não é suportada.',
        ]], 400)]);

        $this->assertSame(
            'A proporção da imagem não é suportada.',
            $this->falha(fn () => $this->gateway()->createImageContainer('1784', 'tk', 'u', 'c'))->getMessage(),
        );
    }
}
