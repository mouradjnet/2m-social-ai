<?php

namespace Tests\Support;

/**
 * Monta um MP4 sintetico, caixa por caixa, so com a estrutura que o Mp4Inspector le.
 * Nao toca: e o esqueleto de um video. Serve para testar cada regra da Meta sem
 * guardar videos binarios no repositorio.
 */
class FakeMp4
{
    public static function bytes(
        int $durationMs = 15_000,
        int $width = 1080,
        int $height = 1920,
        string $videoCodec = 'avc1',
        ?string $audioCodec = 'mp4a',
        bool $fastStart = true,
        bool $editList = false,
        string $brand = 'isom',
    ): string {
        $ftyp = self::box('ftyp', $brand.pack('N', 512).'isomiso2avc1mp41');

        // mvhd v0: version/flags, creation, modification, timescale (1000), duration.
        $mvhd = self::box('mvhd', pack('N5', 0, 0, 0, 1000, $durationMs).str_repeat("\0", 80));

        $video = self::trak('vide', $videoCodec, $width, $height, $editList);
        $audio = $audioCodec === null ? '' : self::trak('soun', $audioCodec, 0, 0, false);

        $moov = self::box('moov', $mvhd.$video.$audio);
        $mdat = self::box('mdat', str_repeat("\x00", 64));

        return $fastStart ? $ftyp.$moov.$mdat : $ftyp.$mdat.$moov;
    }

    private static function trak(string $handler, string $codec, int $w, int $h, bool $editList): string
    {
        // tkhd v0: 84 bytes de corpo; largura e altura em 16.16 no fim.
        $tkhd = self::box('tkhd', str_repeat("\0", 76).pack('N2', $w << 16, $h << 16));
        $edts = $editList ? self::box('edts', self::box('elst', str_repeat("\0", 16))) : '';
        $hdlr = self::box('hdlr', pack('N2', 0, 0).$handler.str_repeat("\0", 12)."\0");
        $stsd = self::box('stsd', pack('N2', 0, 1).pack('N', 16).$codec.str_repeat("\0", 8));
        $stbl = self::box('stbl', $stsd);
        $minf = self::box('minf', $stbl);
        $mdia = self::box('mdia', $hdlr.$minf);

        return self::box('trak', $tkhd.$edts.$mdia);
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }
}
