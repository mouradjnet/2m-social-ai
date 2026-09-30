<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * CP-04: uma decisao HUMANA sobre uma versao de uma peca (aprovar, rejeitar, pedir
 * ajustes). Append-only: e o registro de auditoria da governanca, e a aprovacao
 * guarda o snapshot + sha256 do que foi aprovado. Nada aqui e editado nem apagado
 * pelo codigo — so o cascade do banco, se a peca inteira for apagada.
 */
class ContentDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'project_id', 'content_id', 'version', 'decision', 'reason',
        'from_status', 'to_status', 'snapshot', 'snapshot_hash', 'user_id',
        'request_key', 'request_fingerprint',
    ];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Decisão de aprovação é imutável.'));
        static::deleting(fn () => throw new LogicException('Decisão de aprovação não se apaga.'));
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'name']);
    }
}
