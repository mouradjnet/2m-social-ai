<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(WorkspaceMemberScope::class)]
class Content extends Model
{
    protected $fillable = [
        'workspace_id', 'project_id', 'campaign_id', 'title', 'summary',
        'caption', 'cta', 'hashtags', 'objective_id', 'format', 'channel',
        'image_prompt', 'status', 'assignee_id', 'scheduled_for',
        'source', 'origin_ai_run_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
