<?php

namespace App\Domain\Editorial;

use Illuminate\Support\Str;

/**
 * Regras da marca que NAO dependem da IA (CP-03). A IA recebe as mesmas regras no
 * prompt; aqui elas sao conferidas no codigo, porque um modelo pode esquecer uma
 * palavra proibida e o revisor (tambem IA) pode deixar passar.
 */
class BrandRules
{
    /** Itens que o perfil usa para dizer "ainda nao existe": nao sao oferta. */
    private const MARCADORES = ['a confirmar', 'nenhum', 'nenhuma', 'nao ha', 'n/a', '-'];

    /**
     * Ha produto ou servico de verdade cadastrado? Sem isso, conteudo comercial
     * seria sobre algo que a marca nao vende (a IA inventaria a oferta).
     */
    public static function hasRealOffer(array $brandProfile): bool
    {
        $itens = [...($brandProfile['products'] ?? []), ...($brandProfile['services'] ?? [])];

        foreach ($itens as $item) {
            $normal = self::normalizar((string) $item);

            if ($normal === '') {
                continue;
            }

            $ehMarcador = false;
            foreach (self::MARCADORES as $marcador) {
                if ($normal === $marcador || str_starts_with($normal, "{$marcador} ")) {
                    $ehMarcador = true;
                    break;
                }
            }

            if (! $ehMarcador) {
                return true;
            }
        }

        return false;
    }

    /**
     * As expressoes proibidas que aparecem no texto. Sem diferenca de maiusculas e
     * acentos ("Cura garantida" = "cura garantída"), e so palavra inteira:
     * "garantido" na lista nao acusa "garantidos".
     *
     * @param  list<string>  $proibidas
     * @return list<string> as expressoes encontradas, como estao no perfil
     */
    public static function forbiddenIn(string $texto, array $proibidas): array
    {
        $alvo = ' '.self::normalizar($texto).' ';
        $achadas = [];

        foreach ($proibidas as $proibida) {
            $termo = self::normalizar((string) $proibida);

            if ($termo === '') {
                continue;
            }

            if (preg_match('/(?<![a-z0-9])'.preg_quote($termo, '/').'(?![a-z0-9])/u', $alvo)) {
                $achadas[] = (string) $proibida;
            }
        }

        return $achadas;
    }

    /** Todo o texto de uma peca, estrutura inclusa: a proibida nao pode se esconder num slide. */
    public static function pieceText(array $piece): string
    {
        return implode("\n", array_filter([
            $piece['title'] ?? '',
            $piece['caption'] ?? '',
            $piece['cta'] ?? '',
            implode(' ', $piece['hashtags'] ?? []),
            json_encode($piece['structure'] ?? [], JSON_UNESCAPED_UNICODE),
        ]));
    }

    private static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($texto))));
    }
}
