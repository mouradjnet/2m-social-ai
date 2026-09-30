<?php

namespace App\Domain\Editorial;

use Illuminate\Http\JsonResponse;

/** CP-04C: a peca mudou desde que a pessoa a abriu (expected_version velha): 409. */
class VersionConflict extends ApprovalConflict
{
    public function __construct(string $message, public readonly int $currentVersion)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'current_version' => $this->currentVersion], 409);
    }
}
