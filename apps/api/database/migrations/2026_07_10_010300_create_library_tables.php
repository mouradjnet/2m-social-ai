<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // project_id nulo = ativo compartilhado no workspace inteiro
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['image', 'video', 'document']);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum', 64);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'checksum']);
        });

        Schema::create('snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('kind', ['hashtag_set', 'cta', 'prompt', 'caption']);
            $table->string('title', 200);
            $table->text('body');
            $table->jsonb('tags')->default('[]');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestampsTz();

            $table->index(['workspace_id', 'kind']);
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['content', 'campaign', 'strategy']);
            $table->string('name', 200);
            $table->jsonb('payload');
            $table->timestampsTz();

            $table->index(['workspace_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
        Schema::dropIfExists('snippets');
        Schema::dropIfExists('assets');
    }
};
