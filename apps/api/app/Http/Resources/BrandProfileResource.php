<?php

namespace App\Http\Resources;

use App\Domain\BrandProfile\Completion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BrandProfile */
class BrandProfileResource extends JsonResource
{
    /** O envelope ja e montado aqui embaixo; sem isso viria `data.data`. */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'data' => [
                'id' => $this->id,
                'project_id' => $this->project_id,
                'brand_name' => $this->brand_name,
                'description' => $this->description,
                'products' => $this->products,
                'services' => $this->services,
                'audience' => $this->audience,
                'persona' => $this->persona,
                'tone_of_voice' => $this->tone_of_voice,
                'differentiators' => $this->differentiators,
                'competitors' => $this->competitors,
                'website' => $this->website,
                'instagram' => $this->instagram,
                'facebook' => $this->facebook,
                'linkedin' => $this->linkedin,
                'tiktok' => $this->tiktok,
                'youtube' => $this->youtube,
                'required_words' => $this->required_words,
                'forbidden_words' => $this->forbidden_words,
                'colors' => $this->colors,
                'logo_path' => $this->logo_path,
            ],
            'completion' => Completion::for($this->resource),
        ];
    }
}
