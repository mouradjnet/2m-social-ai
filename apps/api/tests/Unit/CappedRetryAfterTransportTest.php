<?php

namespace Tests\Unit;

use App\Ai\Providers\CappedRetryAfterTransport;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CappedRetryAfterTransportTest extends TestCase
{
    private function retryAfterDepois(?string $valor): string
    {
        $inner = new class($valor) implements ClientInterface
        {
            public function __construct(private readonly ?string $valor) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(429, $this->valor === null ? [] : ['retry-after' => $this->valor]);
            }
        };

        return (new CappedRetryAfterTransport($inner, 10))
            ->sendRequest(new Request('POST', 'https://api.anthropic.com/v1/messages'))
            ->getHeaderLine('retry-after');
    }

    /** O SDK 0.7 dormiria 60 s dentro do job, e o worker (180 s) o mataria. */
    public function test_retry_after_acima_do_teto_e_limitado(): void
    {
        $this->assertSame('10', $this->retryAfterDepois('60'));
    }

    public function test_retry_after_dentro_do_teto_fica_como_veio(): void
    {
        $this->assertSame('3', $this->retryAfterDepois('3'));
    }

    /** Formato de data: o SDK calcula a espera errado; o teto vale do mesmo jeito. */
    public function test_retry_after_em_formato_de_data_vira_o_teto(): void
    {
        $this->assertSame('10', $this->retryAfterDepois('Wed, 21 Oct 2026 07:28:00 GMT'));
    }

    public function test_sem_retry_after_nao_inventa_um(): void
    {
        $this->assertSame('', $this->retryAfterDepois(null));
    }
}
