<?php

namespace App\Domain\Media;

use RuntimeException;

/** A imagem nao serve para o Instagram. A mensagem vai direto para quem subiu. */
class InvalidImageException extends RuntimeException {}
