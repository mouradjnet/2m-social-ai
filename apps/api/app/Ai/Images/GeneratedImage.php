<?php

namespace App\Ai\Images;

final readonly class GeneratedImage
{
    public function __construct(
        public string $bytes,
        public int $costCents,
    ) {}
}
