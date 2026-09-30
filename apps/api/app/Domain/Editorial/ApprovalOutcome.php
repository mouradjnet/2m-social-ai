<?php

namespace App\Domain\Editorial;

use App\Models\ContentDecision;

/** O resultado de uma aprovacao: a decisao e se ela ja existia (replay da mesma chave). */
final readonly class ApprovalOutcome
{
    public function __construct(
        public ContentDecision $decision,
        public bool $replayed,
    ) {}
}
