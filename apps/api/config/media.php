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

];
