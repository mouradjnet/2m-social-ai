<?php

namespace App\Domain\Media;

/**
 * Le a estrutura de um MP4/MOV (as "caixas" ISO BMFF) sem decodificar o video e sem
 * carregar o arquivo na memoria: so os cabecalhos. E o bastante para conferir, no
 * UPLOAD, as regras que a Meta impoe ao Reel e que ela so reclamaria na hora de
 * publicar — de madrugada, sem ninguem olhando.
 *
 * Nao depende de ffmpeg/ffprobe (a imagem Docker nao os tem). O que nao se ve pela
 * estrutura (GOP fechado, bitrate real, 4:2:0) fica com a Meta: o container volta
 * ERROR e o historico da publicacao diz.
 */
class Mp4Inspector
{
    /** Caixas que contem outras caixas e onde as informacoes moram. */
    private const CONTEINERES = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts'];

    /**
     * @return array{brand: ?string, duration_ms: ?int, width: ?int, height: ?int, video_codec: ?string, audio_codec: ?string, moov_before_mdat: bool, edit_list: bool}
     *
     * @throws InvalidImageException quando nem a estrutura basica existe
     */
    public static function inspect(string $path): array
    {
        $f = @fopen($path, 'rb');

        if ($f === false) {
            throw new InvalidImageException('Não foi possível ler o vídeo.');
        }

        $info = [
            'brand' => null, 'duration_ms' => null, 'width' => null, 'height' => null,
            'video_codec' => null, 'audio_codec' => null, 'moov_before_mdat' => false, 'edit_list' => false,
        ];

        try {
            $tamanho = fstat($f)['size'];
            $viuMoov = false;
            $viuMdat = false;

            self::percorrer($f, 0, $tamanho, $info, null, function (string $tipo) use (&$viuMoov, &$viuMdat, &$info) {
                if ($tipo === 'moov' && ! $viuMdat) {
                    $info['moov_before_mdat'] = true;
                }
                $viuMoov = $viuMoov || $tipo === 'moov';
                $viuMdat = $viuMdat || $tipo === 'mdat';
            });

            if ($info['brand'] === null || ! $viuMoov) {
                throw new InvalidImageException('O arquivo não é um vídeo MP4 ou MOV válido.');
            }
        } finally {
            fclose($f);
        }

        return $info;
    }

    /**
     * @param  ?string  $trilha  `vide`/`soun` quando dentro de uma trak ja identificada
     * @param  ?callable  $topo  chamado com o tipo de cada caixa do nivel de cima
     */
    private static function percorrer($f, int $inicio, int $fim, array &$info, ?string $trilha, ?callable $topo = null): void
    {
        $pos = $inicio;

        while ($pos + 8 <= $fim) {
            fseek($f, $pos);
            $cab = fread($f, 8);

            if (strlen($cab) < 8) {
                return;
            }

            $tam = unpack('N', substr($cab, 0, 4))[1];
            $tipo = substr($cab, 4, 4);
            $cabecalho = 8;

            if ($tam === 1) {
                $grande = fread($f, 8);
                $tam = (unpack('N', substr($grande, 0, 4))[1] << 32) | unpack('N', substr($grande, 4, 4))[1];
                $cabecalho = 16;
            } elseif ($tam === 0) {
                $tam = $fim - $pos;
            }

            if ($tam < $cabecalho || $pos + $tam > $fim) {
                return;
            }

            if ($topo !== null) {
                $topo($tipo);
            }

            $corpo = $pos + $cabecalho;
            $fimCaixa = $pos + $tam;

            match (true) {
                $tipo === 'ftyp' => $info['brand'] = rtrim(self::ler($f, $corpo, 4)),
                $tipo === 'mvhd' => $info['duration_ms'] = self::duracao($f, $corpo),
                $tipo === 'elst' => $info['edit_list'] = true,
                $tipo === 'trak' => self::percorrer($f, $corpo, $fimCaixa, $info, self::handler($f, $corpo, $fimCaixa)),
                $tipo === 'tkhd' && $trilha === 'vide' => self::dimensoes($f, $corpo, $fimCaixa, $info),
                $tipo === 'stsd' => self::codec($f, $corpo, $info, $trilha),
                in_array($tipo, self::CONTEINERES, true) => self::percorrer($f, $corpo, $fimCaixa, $info, $trilha),
                default => null,
            };

            $pos = $fimCaixa;
        }
    }

    private static function ler($f, int $pos, int $n): string
    {
        fseek($f, $pos);

        return (string) fread($f, $n);
    }

    /** mvhd: v0 = timescale(4) duration(4) apos 12 bytes; v1 = apos 20 bytes, duration(8). */
    private static function duracao($f, int $corpo): ?int
    {
        $versao = ord(self::ler($f, $corpo, 1));

        if ($versao === 1) {
            $escala = unpack('N', self::ler($f, $corpo + 20, 4))[1];
            $d = unpack('N2', self::ler($f, $corpo + 24, 8));
            $duracao = ($d[1] << 32) | $d[2];
        } else {
            $escala = unpack('N', self::ler($f, $corpo + 12, 4))[1];
            $duracao = unpack('N', self::ler($f, $corpo + 16, 4))[1];
        }

        return $escala > 0 ? (int) round($duracao * 1000 / $escala) : null;
    }

    /** O tipo da trilha mora em mdia/hdlr: `vide` ou `soun`. Busca so dentro desta trak. */
    private static function handler($f, int $inicio, int $fim): ?string
    {
        $pos = $inicio;

        while ($pos + 8 <= $fim) {
            $cab = self::ler($f, $pos, 8);
            $tam = unpack('N', substr($cab, 0, 4))[1];
            $tipo = substr($cab, 4, 4);

            if ($tam < 8) {
                return null;
            }

            if ($tipo === 'mdia') {
                return self::handler($f, $pos + 8, $pos + $tam);
            }

            if ($tipo === 'hdlr') {
                // version/flags(4) + pre_defined(4) + handler_type(4)
                return self::ler($f, $pos + 16, 4);
            }

            $pos += $tam;
        }

        return null;
    }

    /** tkhd: largura e altura sao os ultimos 8 bytes, em ponto fixo 16.16. */
    private static function dimensoes($f, int $corpo, int $fim, array &$info): void
    {
        $wh = unpack('N2', self::ler($f, $fim - 8, 8));
        $info['width'] = $wh[1] >> 16;
        $info['height'] = $wh[2] >> 16;
    }

    /** stsd: version/flags(4) + entry_count(4) + a 1a entrada: size(4) + formato(4). */
    private static function codec($f, int $corpo, array &$info, ?string $trilha): void
    {
        $formato = self::ler($f, $corpo + 12, 4);

        if ($trilha === 'vide') {
            $info['video_codec'] = $formato;
        } elseif ($trilha === 'soun') {
            $info['audio_codec'] = $formato;
        }
    }
}
