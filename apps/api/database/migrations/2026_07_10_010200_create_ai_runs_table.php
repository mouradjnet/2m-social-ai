<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const AGENTS = [
        'strategist', 'copywriter', 'social_media',
        'designer', 'seo', 'analytics', 'reviewer',
    ];

    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('agent', self::AGENTS);
            $table->string('provider', 30);
            $table->string('model', 60);
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
            $table->jsonb('input');
            $table->jsonb('output')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('cache_read_tokens')->nullable();
            $table->integer('cache_write_tokens')->nullable();
            $table->integer('cost_cents')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            // orcamento mensal por workspace
            $table->index(['workspace_id', 'created_at']);
            // worker puxando a fila
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
