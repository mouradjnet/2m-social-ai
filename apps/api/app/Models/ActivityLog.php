<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem fez o que, append-only. So para gestos humanos que nao deixam rastro em outro
 * lugar: aprovar ja vive em `contents.approved_by`, mover no fluxo em
 * `content_revisions`, falar com a Meta em `publication_attempts`. Sem ator
 * "sistema" (ADR-11): `user_id` e obrigatorio.
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['workspace_id', 'project_id', 'user_id', 'subject_type', 'subject_id', 'action', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(User $user, Project $project, string $action, Model $subject, array $meta = []): self
    {
        return self::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'meta' => $meta,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'name']);
    }
}
