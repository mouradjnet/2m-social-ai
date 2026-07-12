<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O CopywriterAgent JA pedia `pillar` no schema e o prompt ja mandava
        // distribuir as pecas pelos pesos da estrategia — mas o persist() descartava
        // o campo, porque nao havia coluna. A pergunta "pedi 40% de Educacao;
        // entreguei quanto?" nascia a cada geracao e morria ali.
        //
        // Nullable: as pecas que ja existem nao tem pilar, e as metricas contam isso
        // em vez de fingir que a amostra e completa.
        Schema::table('contents', function (Blueprint $table) {
            $table->string('pillar', 80)->nullable()->after('channel');
        });

        // Append-only, como as outras filhas de conteudo.
        Schema::create('analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score');
            $table->text('summary');
            // [{title, detail, action}]
            $table->jsonb('insights')->default('[]');
            // O snapshot dos numeros que geraram esta leitura: o relatorio continua
            // explicavel mesmo depois de o calendario mudar.
            $table->jsonb('metrics')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_reports');

        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('pillar');
        });
    }
};
