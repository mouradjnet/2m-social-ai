<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um por projeto. Preenchido em 4 passos pelo wizard, entao todo campo e
 * nullable e o PATCH aceita payload parcial. Ver App\Domain\BrandProfile\Completion.
 */
class BrandProfile extends Model
{
    protected $fillable = [
        'brand_name', 'description', 'products', 'services', 'audience',
        'persona', 'tone_of_voice', 'differentiators', 'competitors',
        'website', 'instagram', 'facebook', 'linkedin', 'tiktok', 'youtube',
        'required_words', 'forbidden_words', 'colors', 'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'services' => 'array',
            'competitors' => 'array',
            'required_words' => 'array',
            'forbidden_words' => 'array',
            'colors' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
