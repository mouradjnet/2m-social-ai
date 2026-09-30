<?php

namespace App\Domain\Editorial;

use RuntimeException;

/** A mesma request_key foi usada para um pedido diferente: 422, nada e gravado. */
class IdempotencyConflict extends RuntimeException {}
