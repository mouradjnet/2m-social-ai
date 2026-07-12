<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only, como content_revisions: uma peca pode ser revisada varias
        // vezes e o historico fica. Sem workspace_id — as tabelas filhas de
        // `contents` nao carregam tenant; quem o resolve e a peca.
        Schema::create('content_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            // Toda revisao veio de uma execucao: o custo e rastreavel por ela.
            $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $table->enum('verdict', ['pass', 'fail']);
            $table->text('summary');
            // [{rule, excerpt, suggestion}]. Vazio quando `pass`.
            $table->jsonb('violations')->default('[]');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['content_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reviews');
    }
};
