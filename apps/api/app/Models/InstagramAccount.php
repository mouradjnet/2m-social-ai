<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conta profissional do Instagram de um projeto. O token nunca sai daqui: o cast
 * o criptografa no banco e `$hidden` o tira de qualquer JSON.
 */
#[ScopedBy(WorkspaceMemberScope::class)]
class InstagramAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'project_id', 'ig_user_id', 'username', 'account_type',
        'access_token', 'token_expires_at', 'token_refreshed_at', 'scopes',
        'status', 'last_error', 'connected_by', 'connected_at', 'disconnected_at',
    ];

    protected $hidden = ['access_token'];

    protected $appends = ['expires_in_days', 'insights_enabled'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by')->select(['id', 'name']);
    }

    public function getInsightsEnabledAttribute(): bool
    {
        return $this->hasInsights();
    }

    /** Dias inteiros ate o token vencer. Negativo = ja venceu. A tela avisa abaixo de 7. */
    public function getExpiresInDaysAttribute(): ?int
    {
        return $this->token_expires_at === null
            ? null
            : (int) floor(now()->diffInDays($this->token_expires_at, false));
    }

    /** A marca autorizou ler as metricas dos posts? (opcional na conexao) */
    public function hasInsights(): bool
    {
        return in_array(config('instagram.insights_scope'), $this->scopes ?? [], true);
    }

    /** Pode publicar agora? Status ativo, token presente e ainda valido. */
    public function isUsable(): bool
    {
        return $this->status === 'active'
            && $this->access_token !== null
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }
}
