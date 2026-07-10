<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(WorkspaceMemberScope::class)]
class AiRun extends Model
{
    protected $fillable = [
        'workspace_id', 'project_id', 'agent', 'provider', 'model', 'status',
        'input', 'output', 'input_tokens', 'output_tokens', 'cache_read_tokens',
        'cache_write_tokens', 'cost_cents', 'latency_ms', 'error', 'error_code',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
