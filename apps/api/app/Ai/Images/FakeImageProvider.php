<?php

namespace App\Ai\Images;

/**
 * Nao sai da maquina e nao custa nada: uma imagem lisa, com a cor tirada do
 * prompt (o mesmo prompt da a mesma cor). Serve para dev, CI e demonstracao.
 */
class FakeImageProvider implements ImageProvider
{
    public function generate(string $prompt): GeneratedImage
    {
        $h = crc32($prompt);
        $imagem = imagecreatetruecolor(1080, 1080);
        imagefill($imagem, 0, 0, imagecolorallocate($imagem, $h & 0xFF, ($h >> 8) & 0xFF, ($h >> 16) & 0xFF));

        ob_start();
        imagepng($imagem);

        return new GeneratedImage((string) ob_get_clean(), 0);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake';
    }
}
