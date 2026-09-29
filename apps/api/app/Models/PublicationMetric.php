<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma coleta das metricas reais de um post (ver a migration). Append-only. */
class PublicationMetric extends Model
{
    public $timestamps = false;

    protected $fillable = ['publication_id', 'metrics', 'error', 'collected_at'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'collected_at' => 'datetime'];
    }
}
