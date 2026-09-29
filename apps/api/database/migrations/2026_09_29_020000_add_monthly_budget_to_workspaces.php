<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O teto mensal de IA por workspace. Nulo = o padrao de `config/ai.php`
 * (AI_WORKSPACE_MONTHLY_BUDGET_CENTS), que era o unico teto ate aqui. So o operador
 * muda (`php artisan workspace:budget`); nenhuma rota escreve nesta coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('monthly_budget_cents')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('monthly_budget_cents');
        });
    }
};
