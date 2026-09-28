<?php

namespace App\Domain\Publishing;

use App\Models\Content;

/**
 * A legenda que vai ao ar: o texto, o CTA e as hashtags, na ordem em que se le no
 * Instagram. E o que a publicacao guarda no snapshot e o que o reviewer aprovou.
 */
class Caption
{
    /** Limites da Meta para a legenda de um post. */
    public const MAX_CHARS = 2200;

    public const MAX_HASHTAGS = 30;

    public static function compose(Content $content): string
    {
        $hashtags = collect($content->hashtags ?? [])
            ->map(fn ($tag) => '#'.ltrim(trim((string) $tag), '#'))
            ->filter(fn ($tag) => $tag !== '#')
            ->implode(' ');

        return collect([trim((string) $content->caption), trim((string) $content->cta), $hashtags])
            ->filter()
            ->implode("\n\n");
    }

    /** O motivo para a Meta recusar esta legenda, ou null. */
    public static function refusal(Content $content): ?string
    {
        $legenda = self::compose($content);

        if ($legenda === '') {
            return 'A peça não tem legenda.';
        }

        if (mb_strlen($legenda) > self::MAX_CHARS) {
            return sprintf('A legenda tem %d caracteres; o Instagram aceita até %d.', mb_strlen($legenda), self::MAX_CHARS);
        }

        if (count($content->hashtags ?? []) > self::MAX_HASHTAGS) {
            return sprintf('A peça tem %d hashtags; o Instagram aceita até %d.', count($content->hashtags), self::MAX_HASHTAGS);
        }

        return null;
    }
}
