<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historico append-only. So `created_at` (com useCurrent na migration), sem
 * `updated_at` — por isso timestamps desligado, senao o Eloquent tentaria escrever
 * updated_at e o insert falharia.
 *
 * Duas formas de linha: transicao de status (`from_status` -> `to_status`) ou
 * mudanca de campo (ambos nulos, e `changes` conta o que mudou). Remarcar uma peca
 * e a segunda.
 */
class ContentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'user_id', 'from_status', 'to_status', 'changes'];

    /**
     * CP-04C: a hora do PHP, como versoes e decisoes. O `useCurrent` do banco da a hora
     * do INICIO da transacao, e a linha do tempo (EditorialTimeline) misturaria relogios.
     */
    protected static function booted(): void
    {
        static::creating(fn (ContentRevision $r) => $r->created_at ??= now());
    }

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
