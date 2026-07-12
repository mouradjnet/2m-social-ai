<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sugestao, nao verdade: `title` e `hashtags` da peca continuam sendo os do
        // copywriter ate alguem aplicar. `applied_at` diz qual sugestao virou a peca.
        Schema::create('content_seo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->jsonb('keywords')->default('[]');
            $table->jsonb('hashtags')->default('[]');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['content_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_seo');
    }
};
