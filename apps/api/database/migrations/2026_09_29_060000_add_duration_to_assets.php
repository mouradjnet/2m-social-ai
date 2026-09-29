<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video na biblioteca (Etapa 3, Reels). `assets.type` ja aceitava `video` desde a
 * fundacao; falta a duracao, que a tela mostra e a regra dos 3 s a 15 min usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });
    }
};
