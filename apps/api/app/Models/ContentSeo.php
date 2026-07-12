<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sugestao de SEO para uma peca. Append-only (so `created_at`) — por isso timestamps
 * desligado, senao o Eloquent tentaria escrever updated_at e o insert falharia.
 *
 * `applied_at` e a unica coluna que muda depois: marca a sugestao que o humano
 * aplicou. Ate la, o titulo da peca continua sendo o do copywriter.
 */
class ContentSeo extends Model
{
    protected $table = 'content_seo';

    public $timestamps = false;

    protected $fillable = ['content_id', 'ai_run_id', 'title', 'keywords', 'hashtags', 'applied_at'];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'hashtags' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
