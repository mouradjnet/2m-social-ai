<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy(WorkspaceMemberScope::class)]
class Content extends Model
{
    protected $fillable = [
        'workspace_id', 'project_id', 'campaign_id', 'title', 'summary',
        'caption', 'cta', 'hashtags', 'objective_id', 'format', 'channel', 'pillar',
        'image_prompt', 'status', 'assignee_id', 'scheduled_for',
        'source', 'origin_ai_run_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * As reviews sao append-only: uma peca pode ser revisada varias vezes. O board
     * mostra a ultima — e a carrega junto do index, para nao pedir uma chamada por
     * card.
     */
    public function latestReview(): HasOne
    {
        return $this->hasOne(ContentReview::class)->latestOfMany();
    }

    /**
     * A ultima vez que o TEXTO mudou (reescrita do rewriter, SEO aplicado).
     *
     * `updated_at` nao serve para isso: ele muda quando a peca ANDA no fluxo ou e
     * arquivada, e a tela usaria isso para decidir se o veredito do revisor ficou
     * velho — arquivar uma peca reprovada apagaria a violacao do card, que e
     * exatamente o oposto do que o usuario precisa ver.
     *
     * Transicao de status grava revisao com `changes` NULO; troca de texto grava o
     * de-para. E a diferenca entre "a peca mudou" e "o texto mudou".
     */
    public function latestTextRevision(): HasOne
    {
        // Nao basta `whereNotNull`: a transicao de status grava `changes` como `{}`
        // (jsonb vazio), nao NULL. Um `{}` passaria pelo filtro e arquivar uma peca
        // reprovada apagaria a violacao do card.
        return $this->hasOne(ContentRevision::class)
            ->whereRaw("changes IS NOT NULL AND changes <> '{}'::jsonb")
            ->latestOfMany();
    }

    /** A sugestao de SEO mais recente. `applied_at` diz se ela ja virou a peca. */
    public function latestSeo(): HasOne
    {
        return $this->hasOne(ContentSeo::class)->latestOfMany();
    }
}
