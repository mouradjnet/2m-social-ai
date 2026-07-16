<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O convite e um TOKEN, nao um email: este projeto nao tem mailer (nenhum MAIL_* nos
 * blueprints, nenhum Mailable no codigo), e adicionar um provedor SMTP para a
 * primeira fatia de convite seria uma dependencia inteira por um link. Quem convida
 * copia o link e manda por onde quiser.
 *
 * O `email` nao e enfeite: ele e conferido no aceite. O link vai por WhatsApp, e sem
 * essa checagem encaminhar a mensagem bastaria para um estranho entrar num workspace
 * que tem a chave da Anthropic atras.
 */
class WorkspaceInvitation extends Model
{
    protected $fillable = ['workspace_id', 'email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Convite que ainda vale: nao aceito e dentro do prazo. */
    public function scopePendente(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
