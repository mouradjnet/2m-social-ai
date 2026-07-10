<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('company', 160)->nullable();
            $table->string('segment', 80)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'paused', 'archived'])->default('active');
            $table->string('image_path')->nullable();
            $table->char('color', 7)->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('target_metric', 120)->nullable();
            $table->date('due_date')->nullable();
            $table->smallInteger('position')->default(0);
            $table->timestampsTz();

            $table->index(['project_id', 'position']);
        });

        // Um por projeto. O wizard de 4 passos preenche em etapas, entao tudo e nullable.
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('brand_name', 160)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('products')->default('[]');
            $table->jsonb('services')->default('[]');
            $table->text('audience')->nullable();
            $table->text('persona')->nullable();
            $table->text('tone_of_voice')->nullable();
            $table->text('differentiators')->nullable();
            $table->jsonb('competitors')->default('[]');
            $table->string('website')->nullable();
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('youtube')->nullable();
            $table->jsonb('required_words')->default('[]');
            $table->jsonb('forbidden_words')->default('[]');
            $table->jsonb('colors')->default('[]');
            $table->string('logo_path')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
        Schema::dropIfExists('objectives');
        Schema::dropIfExists('projects');
    }
};
