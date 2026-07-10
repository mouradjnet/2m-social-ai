<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('researches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['trend', 'market', 'competitor', 'faq', 'keyword', 'news', 'hashtag']);
            $table->text('query');
            $table->enum('status', ['queued', 'running', 'done', 'failed'])->default('queued');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['project_id', 'type']);
        });

        Schema::create('research_insights', function (Blueprint $table) {
            $table->id();
            // 'research' e invariavel no pluralizador do Laravel: precisa ser explicito
            $table->foreignId('research_id')->constrained('researches')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->smallInteger('score')->nullable();
            // nao-nulo impede converter o mesmo insight duas vezes (409 na API)
            $table->foreignId('converted_content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_insights');
        Schema::dropIfExists('researches');
    }
};
