<?php

use App\Http\Controllers\Api\V1\AiRunController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandProfileController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CopyController;
use App\Http\Controllers\Api\V1\DesignController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RewriteController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // O servico e publico: sem limite, o login aceita tentativas de senha sem fim.
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::post('workspaces', [WorkspaceController::class, 'store']);

        // Escopo de tenant explicito na rota (ADR-02).
        Route::prefix('workspaces/{workspace}')->group(function () {
            Route::get('projects', [ProjectController::class, 'index'])
                ->middleware('workspace:viewer');

            Route::post('projects', [ProjectController::class, 'store'])
                ->middleware('workspace:editor');

            // Convidar e gesto de administracao: quem edita conteudo nao decide quem
            // entra no workspace.
            Route::get('invitations', [InvitationController::class, 'index'])
                ->middleware('workspace:admin');
            Route::post('invitations', [InvitationController::class, 'store'])
                ->middleware('workspace:admin');
        });

        // FORA do grupo acima de proposito: quem aceita ainda NAO e membro, entao nao
        // passaria pelo `workspace:{papel}` — e nao ha `{workspace}` na rota porque o
        // token e que diz para onde o convite leva. Exige login: o email da conta tem
        // de bater com o do convite.
        Route::post('invitations/{token}:accept', [InvitationController::class, 'accept']);

        // Depois de criado, o projeto e acessado pelo proprio id: o
        // WorkspaceMemberScope resolve o tenant e devolve 404 se for de outro.
        // O papel e checado pela ProjectPolicy.
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        // Corrigir o fuso depois de criado (o social_media agenda nele).
        Route::patch('projects/{project}', [ProjectController::class, 'update']);

        Route::get('projects/{project}/brand-profile', [BrandProfileController::class, 'show']);
        Route::patch('projects/{project}/brand-profile', [BrandProfileController::class, 'update']);

        // Geracao nao bloqueia: 202 + polling em /ai-runs/{aiRun} (ADR-07).
        Route::get('projects/{project}/strategies', [StrategyController::class, 'index']);
        Route::post('projects/{project}/strategies:generate', [StrategyController::class, 'generate']);
        Route::post('projects/{project}/copy:generate', [CopyController::class, 'generate']);
        Route::post('projects/{project}/schedule:generate', [ScheduleController::class, 'generate']);
        Route::post('projects/{project}/review:generate', [ReviewController::class, 'generate']);
        Route::post('projects/{project}/design:generate', [DesignController::class, 'generate']);
        Route::post('projects/{project}/seo:generate', [SeoController::class, 'generate']);
        Route::get('projects/{project}/analytics', [AnalyticsController::class, 'show']);
        Route::post('projects/{project}/analytics:generate', [AnalyticsController::class, 'generate']);
        // A IA propoe; aplicar e do humano.
        Route::post('contents/{content}/seo:apply', [SeoController::class, 'apply']);
        // Conserta a peca reprovada, no lugar. Fecha o ciclo do revisor.
        Route::post('contents/{content}/rewrite:generate', [RewriteController::class, 'generate']);
        Route::get('projects/{project}/contents', [ContentController::class, 'index']);
        // A ENTREGA: sem API das redes, o zip e como o conteudo sai daqui.
        Route::get('projects/{project}/export', [ExportController::class, 'download']);
        Route::patch('contents/{content}', [ContentController::class, 'update']);
        // Rota propria, e nao um `status` no PATCH acima: quem decide o destino e o
        // servidor, lendo de onde a peca saiu. O cliente nao tem essa informacao.
        Route::post('contents/{content}/unarchive', [ContentController::class, 'unarchive']);
        Route::patch('strategies/{strategy}', [StrategyController::class, 'update']);

        Route::get('ai-runs/{aiRun}', [AiRunController::class, 'show']);
    });
});
