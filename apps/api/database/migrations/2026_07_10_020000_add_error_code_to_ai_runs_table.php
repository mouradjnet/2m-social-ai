<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classificacao da falha, para a UI decidir se insistir e util. `null` no
     * sucesso: o codigo so e escrito no `catch`, entao `null` significa "nao
     * falhou", nunca "falhou por motivo desconhecido".
     *
     * Sem `->after()`: e modificador exclusivo do MySQL e o Postgres o ignora.
     */
    public const CODES = ['refused', 'rejected_output', 'provider_failed'];

    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->enum('error_code', self::CODES)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('error_code');
        });
    }
};
