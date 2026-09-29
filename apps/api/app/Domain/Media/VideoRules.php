<?php

namespace App\Domain\Media;

/**
 * As regras da Meta para o video do Reel, sobre o que o Mp4Inspector leu. Devolve o
 * motivo da recusa em portugues (com o que fazer), ou null quando serve.
 */
class VideoRules
{
    public static function refusal(array $info, int $bytes): ?string
    {
        $c = config('media.video');

        if ($bytes > $c['max_bytes']) {
            return sprintf('O vídeo tem %d MB; o Instagram aceita até %d MB.', intdiv($bytes, 1048576), intdiv($c['max_bytes'], 1048576));
        }

        if ($info['video_codec'] === null) {
            return 'O arquivo não tem trilha de vídeo.';
        }

        if (! in_array($info['video_codec'], $c['video_codecs'], true)) {
            return "O vídeo está em {$info['video_codec']}; o Instagram aceita H.264 ou HEVC. Exporte de novo em H.264.";
        }

        if ($info['audio_codec'] !== null && ! in_array($info['audio_codec'], $c['audio_codecs'], true)) {
            return "O áudio está em {$info['audio_codec']}; o Instagram aceita AAC.";
        }

        if ($info['duration_ms'] === null || $info['duration_ms'] < $c['min_ms'] || $info['duration_ms'] > $c['max_ms']) {
            return 'O Reel precisa ter entre 3 segundos e 15 minutos.';
        }

        if ($info['width'] !== null && $info['width'] > $c['max_width']) {
            return "O vídeo tem {$info['width']} px de largura; o Instagram aceita até {$c['max_width']}. Exporte em 1080×1920.";
        }

        if (! $info['moov_before_mdat']) {
            return 'O vídeo não está otimizado para streaming (o índice está no fim do arquivo). Exporte com a opção "otimizar para web" / "fast start".';
        }

        if ($info['edit_list']) {
            return 'O vídeo tem uma lista de edição (edit list), que o Instagram recusa. Exporte o vídeo de novo, sem cortes pendentes.';
        }

        return null;
    }
}
