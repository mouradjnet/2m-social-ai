<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O plano de uma semana, proposto pelo `planner`. `distribution` guarda
 * `{summary, slots: [{date, time, pillar, format, channel, theme, rationale}]}`,
 * com data e hora LOCAIS (fuso do projeto).
 *
 * Sem `workspace_id` proprio: pertence a uma estrategia, que pertence ao projeto.
 * Quem le confere o projeto (WeekPlanController); o job roda sem usuario.
 */
class ContentPlan extends Model
{
    protected $fillable = [
        'strategy_id', 'period_start', 'period_end', 'posts_count',
        'distribution', 'best_days', 'best_times', 'format_mix', 'ai_run_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'distribution' => 'array',
            'best_days' => 'array',
            'best_times' => 'array',
            'format_mix' => 'array',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /** @return list<array{date: string, time: string, pillar: string, format: string, channel: string, theme: string, rationale: string}> */
    public function slots(): array
    {
        return $this->distribution['slots'] ?? [];
    }
}
