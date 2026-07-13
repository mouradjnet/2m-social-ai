<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'company' => $this->company,
            'segment' => $this->segment,
            'description' => $this->description,
            'owner_user_id' => $this->owner_user_id,
            'status' => $this->status,
            // O fuso existia na coluna e no prompt do social_media, mas NUNCA saiu na
            // API: a tela nao tinha como mostrar em que fuso a marca publica, nem
            // conferir depois de corrigir.
            'timezone' => $this->timezone,
            'image_path' => $this->image_path,
            'color' => $this->color,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
