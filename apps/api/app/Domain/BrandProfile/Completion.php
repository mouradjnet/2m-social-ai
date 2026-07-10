<?php

namespace App\Domain\BrandProfile;

use App\Models\BrandProfile;

/**
 * O servidor decide quais passos existem, quais estao completos e quais sao
 * obrigatorios; o Stepper do frontend so desenha. Se a regra vivesse no cliente,
 * duas telas discordariam sobre o mesmo perfil.
 *
 * Mede PRESENCA dos campos que o AgentContext envia ao modelo, nao profundidade:
 * `differentiators = "ok"` conta. Peso por campo seria opiniao disfarcada de
 * metrica. A honestidade e outra: 100% = o agente recebeu todos os campos que le.
 *
 * `percent` conta so os passos obrigatorios. Os opcionais aparecem e informam se
 * estao preenchidos, mas nunca movem o numero.
 */
class Completion
{
    /**
     * @return array{
     *   steps: list<array{id: string, complete: bool, required: bool}>,
     *   percent: int
     * }
     */
    public static function for(BrandProfile $profile): array
    {
        $steps = [
            self::step('identity', true, filled($profile->brand_name) && filled($profile->description)),
            self::step('audience', true, filled($profile->audience) && filled($profile->persona)),
            self::step('positioning', true, filled($profile->tone_of_voice) && filled($profile->differentiators)),
            self::step('offer', true, filled($profile->products) && filled($profile->services)),
            self::step('vocabulary', false, filled($profile->competitors)
                || filled($profile->required_words)
                || filled($profile->forbidden_words)),
            self::step('social', false, filled($profile->website)
                || filled($profile->instagram)
                || filled($profile->facebook)
                || filled($profile->linkedin)
                || filled($profile->tiktok)
                || filled($profile->youtube)),
        ];

        $required = array_filter($steps, fn ($s) => $s['required']);
        $done = array_filter($required, fn ($s) => $s['complete']);

        return [
            'steps' => $steps,
            'percent' => (int) round(count($done) / count($required) * 100),
        ];
    }

    /** @return array{id: string, complete: bool, required: bool} */
    private static function step(string $id, bool $required, bool $complete): array
    {
        return ['id' => $id, 'complete' => $complete, 'required' => $required];
    }
}
