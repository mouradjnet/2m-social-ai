<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A conta do Instagram para onde o projeto publica. Uma conta ativa por projeto: o
 * projeto "2M Saude Feminina" publica no @2msaudefeminina, e so nele.
 *
 * A linha nao e apagada ao desconectar — as publicacoes apontam para ela, e o
 * historico precisa dizer para ONDE cada post foi. Desconectar apaga o token e
 * marca `disconnected`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // O id da conta profissional (o `user_id` do /me), que as rotas de publicacao usam.
            $table->string('ig_user_id', 64);
            $table->string('username', 100);
            $table->string('account_type', 30);

            // Criptografado pelo cast `encrypted` (APP_KEY). Nulo depois de desconectar.
            $table->text('access_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('token_refreshed_at')->nullable();
            $table->jsonb('scopes')->default('[]');

            $table->enum('status', ['active', 'expired', 'disconnected']);
            $table->text('last_error')->nullable();

            $table->foreignId('connected_by')->constrained('users');
            $table->timestampTz('connected_at');
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'status']);
        });

        // Uma conta viva por projeto: o banco garante, nao a memoria de quem programa.
        DB::statement("CREATE UNIQUE INDEX instagram_accounts_one_live_per_project ON instagram_accounts (project_id) WHERE status <> 'disconnected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
