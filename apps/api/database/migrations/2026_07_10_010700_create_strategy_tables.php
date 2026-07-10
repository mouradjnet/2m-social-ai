<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Duradoura: a linha editorial do projeto.
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->text('editorial_line')->nullable();
            $table->jsonb('pillars')->default('[]');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['project_id', 'status']);
        });

        // Periodica: quantos posts, quando, de que tipo.
        // best_days/best_times sao SUGESTAO do modelo, nao metrica de desempenho.
        Schema::create('content_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->smallInteger('posts_count')->default(0);
            $table->jsonb('distribution')->default('{}');
            $table->jsonb('best_days')->default('[]');
            $table->jsonb('best_times')->default('[]');
            $table->jsonb('format_mix')->default('{}');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['strategy_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plans');
        Schema::dropIfExists('strategies');
    }
};
