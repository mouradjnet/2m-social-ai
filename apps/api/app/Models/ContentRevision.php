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

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
