<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Relatorio do agente analytics. Append-only (so `created_at`) — por isso timestamps
 * desligado, senao o Eloquent tentaria escrever updated_at e o insert falharia.
 *
 * `metrics` e o snapshot dos numeros que geraram a leitura: o relatorio continua
 * explicavel mesmo depois de o calendario mudar.
 */
class AnalyticsReport extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_id', 'ai_run_id', 'score', 'summary', 'insights', 'metrics'];

    protected function casts(): array
    {
        return ['insights' => 'array', 'metrics' => 'array'];
    }
}
