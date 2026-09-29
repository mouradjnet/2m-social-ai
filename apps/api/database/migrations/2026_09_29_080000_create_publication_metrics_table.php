<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metricas REAIS dos posts, lidas da Meta (Etapa 5). Separadas de proposito das
 * metricas editoriais (Domain\Analytics\Metrics, calculadas a partir do proprio
 * calendario): aqui so entra numero que a rede social devolveu.
 *
 * Uma linha por coleta (append-only): a curva de um post nos primeiros dias e
 * informacao, e o "resultado" dele e a coleta mais recente. `error` guarda a recusa
 * da Meta (metrica indisponivel para o tipo, permissao) — nunca vira numero zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->jsonb('metrics')->default('{}');
            $table->text('error')->nullable();
            $table->timestampTz('collected_at')->useCurrent();
            $table->index(['publication_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_metrics');
    }
};
