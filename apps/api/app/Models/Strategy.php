<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(WorkspaceMemberScope::class)]
class Strategy extends Model
{
    /** `strategies` — o pluralizador do Laravel acerta aqui, mas explicito nao dói. */
    protected $table = 'strategies';

    protected $fillable = [
        'workspace_id', 'project_id', 'title', 'summary',
        'editorial_line', 'pillars', 'status', 'ai_run_id',
    ];

    protected function casts(): array
    {
        return ['pillars' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
