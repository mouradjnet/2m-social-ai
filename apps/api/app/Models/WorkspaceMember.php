<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMember extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceMemberFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'user_id', 'role', 'joined_at'];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'joined_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
