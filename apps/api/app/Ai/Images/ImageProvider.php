<?php

namespace App\Ai\Images;

/**
 * Quem desenha a imagem a partir do prompt do diretor de arte (DesignerAgent).
 * Devolve bytes; validar proporcao, reencodar e guardar e da biblioteca.
 */
interface ImageProvider
{
    /** @throws ImageGenerationException */
    public function generate(string $prompt): GeneratedImage;

    public function name(): string;

    public function model(): string;
}
