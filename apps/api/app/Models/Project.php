<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy(WorkspaceMemberScope::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'name', 'company', 'segment', 'timezone', 'description',
        'owner_user_id', 'status', 'image_path', 'color',
    ];

    /**
     * O mesmo default da coluna, tambem no model: o default do banco so aparece
     * depois de um refresh, e um projeto recem-criado usado na mesma requisicao
     * chegaria ao SocialMediaAgent com `timezone` nulo.
     */
    protected $attributes = [
        'timezone' => 'America/Sao_Paulo',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }
}
