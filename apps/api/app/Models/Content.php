<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * As reviews sao append-only: uma peca pode ser revisada varias vezes. O board
     * mostra a ultima — e a carrega junto do index, para nao pedir uma chamada por
     * card.
     */
    public function latestReview(): HasOne
    {
        return $this->hasOne(ContentReview::class)->latestOfMany();
    }
}
