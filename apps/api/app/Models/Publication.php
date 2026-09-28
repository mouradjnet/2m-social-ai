<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O que o sistema fez em nome de quem aprovou (ADR-13): uma publicacao de uma peca,
 * num horario, numa conta. Guarda o snapshot do que foi aprovado.
 */
#[ScopedBy(WorkspaceMemberScope::class)]
class Publication extends Model
{
    /** Estados em que a publicacao ainda pode (ou pode ter) ido ao ar. */
    public const LIVE = ['pending', 'publishing', 'published', 'unknown'];

    /** Estados em que o worker ainda tem trabalho. */
    public const IN_FLIGHT = ['pending', 'publishing', 'unknown'];

    protected $fillable = [
        'workspace_id', 'project_id', 'content_id', 'instagram_account_id', 'asset_id',
        'caption', 'image_url', 'account_username', 'approved_by', 'approved_at', 'scheduled_for',
        'status', 'container_id', 'media_id', 'permalink', 'published_at',
        'attempts', 'next_attempt_at', 'error_kind', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'instagram_account_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->select(['id', 'name']);
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(PublicationAttempt::class)->orderBy('id');
    }
}
