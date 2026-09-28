<?php

namespace App\Instagram;

use RuntimeException;

/**
 * Uma falha da Meta, ja classificada. A classificacao e o que decide o que fazer:
 *
 * - `transient`: tente de novo mais tarde (limite de taxa, 5xx antes de publicar).
 * - `permanent`: nao adianta insistir (imagem recusada, legenda invalida).
 * - `auth`: o token nao vale mais — a conta precisa ser reconectada.
 * - `unknown`: a requisicao pode ter chegado e surtido efeito (timeout no
 *   media_publish). NUNCA repetir as cegas: primeiro perguntar a Meta.
 *
 * A mensagem ja vem sem token: a URL de uma falha de conexao carrega o
 * `access_token` na query, e esta mensagem vai para o banco e para a tela.
 */
class InstagramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
    ) {
        parent::__construct(self::semToken($message));
    }

    public static function semToken(string $texto): string
    {
        return (string) preg_replace('/(access_token|client_secret)=[^&\s"\']+/', '$1=***', $texto);
    }
}
