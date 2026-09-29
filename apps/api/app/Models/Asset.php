<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceMemberScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Uma imagem da biblioteca do projeto. O arquivo ja e o JPEG que o Instagram aceita
 * (ImageProcessor); `url` e o endereco publico que a Meta vai buscar.
 */
#[ScopedBy(WorkspaceMemberScope::class)]
class Asset extends Model
{
    protected $fillable = [
        'workspace_id', 'project_id', 'type', 'disk', 'path', 'original_name',
        'mime', 'size_bytes', 'width', 'height', 'duration_ms', 'checksum', 'created_by',
    ];

    protected $appends = ['url'];

    protected $hidden = ['disk', 'path', 'checksum'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'image_asset_id');
    }

    /** Pecas em que esta imagem e slide de carrossel. */
    public function slideContents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_slides');
    }

    /** Pecas (Reels) em que este e o video. */
    public function videoContents(): HasMany
    {
        return $this->hasMany(Content::class, 'video_asset_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
