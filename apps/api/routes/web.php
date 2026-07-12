<?php

use Illuminate\Support\Facades\Route;

/*
 | O Laravel serve a propria SPA: o build do Vite e copiado para `public/` na
 | imagem (ver Dockerfile). Um dominio so — sem CORS, sem segunda origem, e o
 | `fetch('/api/v1/...')` do frontend continua relativo, sem mudar uma linha.
 |
 | Este fallback existe para os deep links: `/projects/4/content` nao e arquivo
 | nem rota de API, entao chega aqui e recebe o index.html — quem roteia dali em
 | diante e o React Router.
 |
 | Em dev o index.html nao existe (o Vite serve o front em :5173): a rota devolve
 | a welcome do Laravel, como antes.
 */
Route::get('/{any?}', function () {
    $spa = public_path('index.html');

    return file_exists($spa) ? response()->file($spa) : view('welcome');
})
    // O `/up` (healthcheck que o Render mede) NAO precisa de excecao aqui: o
    // framework o registra antes das rotas web, e a primeira rota casada vence.
    // SpaFallbackTest prova isso — se a ordem mudar numa versao futura, ele pega.
    ->where('any', '^(?!api).*$');
