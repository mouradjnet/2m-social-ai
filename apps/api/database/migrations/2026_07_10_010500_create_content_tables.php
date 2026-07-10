<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const STATUSES = [
        'idea', 'production', 'review', 'approved',
        'scheduled', 'published', 'archived',
    ];

    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->text('caption')->nullable();
            $table->string('cta', 280)->nullable();
            $table->jsonb('hashtags')->default('[]');
            $table->foreignId('objective_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('format', ['post', 'carousel', 'reel', 'story', 'video', 'article', 'thread']);
            $table->enum('channel', ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'blog']);
            $table->text('image_prompt')->nullable();
            $table->foreignId('image_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->enum('status', self::STATUSES)->default('idea');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('published_at')->nullable();

            // sustenta o chip "Gerado por IA" e da rastreabilidade de custo por peca
            $table->enum('source', ['manual', 'ai', 'research'])->default('manual');
            $table->foreignId('origin_ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['workspace_id', 'project_id', 'status']);
            // o calendario le por aqui
            $table->index(['project_id', 'scheduled_for']);
        });

        // Historico. Append-only: sem updated_at.
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->enum('from_status', self::STATUSES)->nullable();
            $table->enum('to_status', self::STATUSES)->nullable();
            $table->jsonb('changes')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['content_id', 'created_at']);
        });

        Schema::create('content_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['content_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_comments');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('contents');
    }
};
