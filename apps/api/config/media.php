<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Onde as imagens moram
    |--------------------------------------------------------------------------
    |
    | A Meta BUSCA a imagem por URL publica na hora de publicar — nao se envia o
    | arquivo. Entao o disco precisa (1) sobreviver a um deploy e (2) servir por
    | HTTPS. Na VPS, `public` num volume Docker persistente cumpre os dois (o Nginx
    | serve /storage). Para R2/S3, use um disco `s3` com URL publica e instale
    | `league/flysystem-aws-s3-v3` — o codigo nao muda, so esta variavel.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    // O limite da Meta para imagem e 8 MB. Aceitar mais seria aceitar o que nao sai.
    'max_bytes' => (int) env('MEDIA_MAX_BYTES', 8 * 1024 * 1024),

    // Instagram: largura entre 320 e 1440 px; proporcao entre 4:5 e 1.91:1.
    'min_width' => 320,
    'max_width' => 1440,
    'min_ratio' => 0.8,
    'max_ratio' => 1.91,

    'jpeg_quality' => 90,

    /*
     | Video do Reel (Etapa 3), conforme a documentacao da Meta (IG User Media,
     | consultada em 29/09/2026): MP4/MOV com o moov no inicio e sem edit list,
     | H.264 ou HEVC, audio AAC, de 3 s a 15 min, ate 1920 px de largura, ate
     | 300 MB. O arquivo NAO e reencodado (nao ha ffmpeg): ou cumpre, ou e recusado.
     */
    'video' => [
        'max_bytes' => (int) env('MEDIA_VIDEO_MAX_BYTES', 300 * 1024 * 1024),
        'min_ms' => 3_000,
        'max_ms' => 15 * 60 * 1000,
        'max_width' => 1920,
        'video_codecs' => ['avc1', 'avc3', 'hvc1', 'hev1'],
        'audio_codecs' => ['mp4a'],
    ],

];
