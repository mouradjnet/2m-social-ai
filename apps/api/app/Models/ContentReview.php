<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Veredito do reviewer sobre uma peca. Append-only (so `created_at`, com useCurrent
 * na migration) — por isso timestamps desligado, senao o Eloquent tentaria escrever
 * updated_at e o insert falharia.
 *
 * Uma review NAO muda o status da peca: a IA propoe, o humano promove.
 */
class ContentReview extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'ai_run_id', 'verdict', 'summary', 'violations'];

    protected function casts(): array
    {
        return ['violations' => 'array'];
    }
}
