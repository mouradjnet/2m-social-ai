<?php

use App\Http\Controllers\Api\V1\AiRunController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandProfileController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CopyController;
use App\Http\Controllers\Api\V1\DesignController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RewriteController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

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
        });

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
        Route::patch('contents/{content}', [ContentController::class, 'update']);
        Route::patch('strategies/{strategy}', [StrategyController::class, 'update']);

        Route::get('ai-runs/{aiRun}', [AiRunController::class, 'show']);
    });
});
