<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Escopo de tenant explicito na rota (ADR-02).
        Route::prefix('workspaces/{workspace}')->group(function () {
            Route::get('projects', [ProjectController::class, 'index'])
                ->middleware('workspace:viewer');

            Route::post('projects', [ProjectController::class, 'store'])
                ->middleware('workspace:editor');
        });

        // Depois de criado, o projeto e acessado pelo proprio id: o
        // WorkspaceMemberScope resolve o tenant e devolve 404 se for de outro.
        Route::get('projects/{project}', [ProjectController::class, 'show']);
    });
});
