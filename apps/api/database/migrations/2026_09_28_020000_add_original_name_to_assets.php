<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela `assets` existe desde a primeira fatia e nunca foi usada. A biblioteca de
 * imagens a acorda com uma coluna a mais: o nome que o arquivo tinha no computador
 * de quem subiu. O `path` e um uuid (nao adivinhavel, porque a URL e publica — a
 * Meta precisa buscar a imagem), e sem o nome original ninguem reconhece a foto na
 * grade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('original_name', 255)->nullable()->after('path');
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropColumn('original_name');
        });
    }
};
