<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historico append-only de mudancas de status. So `created_at` (com useCurrent
 * na migration), sem `updated_at` — por isso timestamps desligado, senao o
 * Eloquent tentaria escrever updated_at e o insert falharia.
 */
class ContentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'user_id', 'from_status', 'to_status'];
}
