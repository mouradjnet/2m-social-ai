<?php

namespace Tests\Support;

use App\Domain\Editorial\Approval;
use App\Models\Content;
use App\Models\User;

/**
 * CP-04: uma peca "aprovada" so e aprovada com decisao humana presa a versao (snapshot
 * + hash). Testes que precisam de uma peca aprovada passam por aqui — pelo MESMO
 * servico da Central de Aprovacao —, e nao por `status => approved` no banco (que o
 * sistema, fail-closed, trata como aprovacao inexistente).
 */
final class Aprovar
{
    public static function peca(Content $content, ?User $quem = null): Content
    {
        if ($content->status !== 'review') {
            $content->update(['status' => 'review']);
        }

        Approval::approve($content, (int) $content->fresh()->version, $quem ?? User::factory()->create());

        return $content->fresh();
    }
}
