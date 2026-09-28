<?php

namespace App\Domain\Media;

use GdImage;

/**
 * Transforma o arquivo que o usuario subiu na imagem que o Instagram aceita.
 *
 * A API de publicacao so aceita JPEG, e recusa proporcoes fora de 4:5 a 1.91:1. A
 * recusa chegaria na hora de publicar — de madrugada, sem ninguem olhando. Aqui ela
 * chega no upload, com o motivo, para quem pode trocar a foto.
 *
 * Reencodar tem um efeito colateral bom: o EXIF (localizacao do celular, modelo da
 * camera) nao sobrevive. A imagem vai para uma URL publica.
 */
class ImageProcessor
{
    /**
     * @return array{bytes: string, width: int, height: int}
     *
     * @throws InvalidImageException
     */
    public static function toInstagramJpeg(string $path): array
    {
        $info = @getimagesize($path);

        if ($info === false) {
            throw new InvalidImageException('O arquivo não é uma imagem legível.');
        }

        $imagem = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $imagem instanceof GdImage) {
            throw new InvalidImageException('Formato não suportado. Envie JPEG, PNG ou WebP.');
        }

        [$largura, $altura] = [imagesx($imagem), imagesy($imagem)];
        $proporcao = $largura / $altura;

        if ($largura < config('media.min_width')) {
            throw new InvalidImageException(sprintf(
                'A imagem tem %d px de largura; o Instagram exige pelo menos %d.',
                $largura, config('media.min_width'),
            ));
        }

        if ($proporcao < config('media.min_ratio') || $proporcao > config('media.max_ratio')) {
            throw new InvalidImageException(sprintf(
                'A proporção %d×%d não é aceita pelo Instagram. Use entre 4:5 (retrato) e 1.91:1 (paisagem).',
                $largura, $altura,
            ));
        }

        if ($largura > config('media.max_width')) {
            $novaAltura = (int) round($altura * config('media.max_width') / $largura);
            $imagem = imagescale($imagem, config('media.max_width'), $novaAltura, IMG_BICUBIC);
            [$largura, $altura] = [imagesx($imagem), imagesy($imagem)];
        }

        // PNG e WebP podem ter transparencia; JPEG nao. Sem um fundo, o transparente
        // vira preto — e o logo da marca sai numa caixa escura.
        $fundo = imagecreatetruecolor($largura, $altura);
        imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
        imagecopy($fundo, $imagem, 0, 0, 0, 0, $largura, $altura);

        ob_start();
        imagejpeg($fundo, null, config('media.jpeg_quality'));
        $bytes = (string) ob_get_clean();

        return ['bytes' => $bytes, 'width' => $largura, 'height' => $altura];
    }
}
