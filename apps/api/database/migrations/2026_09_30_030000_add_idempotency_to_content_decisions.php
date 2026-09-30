<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-04A (idempotencia da acao Aprovar). So acrescimo:
 *
 * - request_key: a chave que o cliente gera por INTENCAO de decidir (UUID). Repetir a
 *   requisicao com a mesma chave devolve a decisao original, sem gravar outra.
 * - request_fingerprint: sha256 do que foi pedido (peca, acao, versao, motivo). A
 *   mesma chave com outro pedido e recusada.
 * - unico (user_id, request_key): duas requisicoes simultaneas com a mesma chave
 *   gravam uma so. Nulas nas decisoes antigas (o Postgres nao compara NULL no unico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_decisions', function (Blueprint $table) {
            $table->uuid('request_key')->nullable()->after('user_id');
            $table->char('request_fingerprint', 64)->nullable()->after('request_key');
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::table('content_decisions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'request_key']);
            $table->dropColumn(['request_key', 'request_fingerprint']);
        });
    }
};
