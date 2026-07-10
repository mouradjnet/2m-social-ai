<?php

namespace App\Domain\BrandProfile;

use App\Models\BrandProfile;

/**
 * O wizard tem 4 passos. O servidor decide quais estao completos; o Stepper do
 * frontend so desenha. Se a regra vivesse no cliente, duas telas discordariam
 * sobre o mesmo perfil.
 */
class Completion
{
    /** @return array{identity: bool, audience: bool, positioning: bool, social: bool, percent: int} */
    public static function for(BrandProfile $profile): array
    {
        $steps = [
            'identity' => filled($profile->brand_name),
            'audience' => filled($profile->audience),
            'positioning' => filled($profile->tone_of_voice),
            'social' => filled($profile->website)
                || filled($profile->instagram)
                || filled($profile->facebook)
                || filled($profile->linkedin)
                || filled($profile->tiktok)
                || filled($profile->youtube),
        ];

        $done = count(array_filter($steps));

        return [...$steps, 'percent' => (int) round($done / count($steps) * 100)];
    }
}
