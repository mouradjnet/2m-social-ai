<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * O modelo devolveu JSON valido, mas que viola uma regra que o JSON Schema da
 * API nao consegue expressar (limites numericos, tamanho de array, palavras
 * proibidas). O servidor e o validador; o modelo e so o gerador.
 */
class OutputRejectedException extends RuntimeException {}
