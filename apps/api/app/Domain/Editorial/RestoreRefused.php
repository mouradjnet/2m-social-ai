<?php

namespace App\Domain\Editorial;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** CP-04C: a restauracao pedida nao faz sentido (versao inexistente, igual, midia apagada): 422. */
class RestoreRefused extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
