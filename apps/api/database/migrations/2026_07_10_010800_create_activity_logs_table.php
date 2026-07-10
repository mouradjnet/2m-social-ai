<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // NOT NULL de proposito: nao existe ator "sistema" (ADR-11).
            $table->foreignId('user_id')->constrained();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 60);
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
