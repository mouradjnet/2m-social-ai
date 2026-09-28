<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma conversa com a Meta. Append-only, como `content_revisions`. */
class PublicationAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'publication_id', 'number', 'step', 'outcome',
        'http_status', 'meta_code', 'meta_subcode', 'message',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
