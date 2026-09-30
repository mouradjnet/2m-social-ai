<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * CP-04C: uma versao de uma peca, com o snapshot do que ia ao ar naquela versao (o
 * mesmo formato e o mesmo sha256 da aprovacao — Approval::snapshot/hash) e os
 * metadados das midias. Imutavel: o codigo recusa update/delete e o banco recusa
 * UPDATE (trigger). Quem grava e so Versioning::record.
 */
class ContentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'project_id', 'content_id', 'version', 'snapshot', 'snapshot_hash',
        'media', 'origin', 'restored_from_version', 'invalidated_approval', 'user_id', 'ai_run_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'media' => 'array',
            'invalidated_approval' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Versão de peça é imutável.'));
        static::deleting(fn () => throw new LogicException('Versão de peça não se apaga.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'name']);
    }
}
