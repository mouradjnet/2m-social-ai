<?php

namespace App\Console\Commands;

use App\Ai\Budget;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Mostra ou fixa o teto mensal de IA de um workspace. E gesto do OPERADOR (quem paga
 * a chave), por isso vive aqui e nao numa rota: o dono de um workspace cliente nao
 * sobe o proprio teto.
 *
 *   php artisan workspace:budget 2m-negocios          # mostra
 *   php artisan workspace:budget 2m-negocios 3000     # US$ 30/mes
 *   php artisan workspace:budget 2m-negocios --default
 */
#[Signature('workspace:budget {workspace : id ou slug} {cents? : novo teto em centavos de dolar} {--default : volta ao padrao do config}')]
#[Description('Mostra ou fixa o teto mensal de IA de um workspace')]
class SetWorkspaceBudget extends Command
{
    public function handle(): int
    {
        $arg = (string) $this->argument('workspace');
        $workspace = Workspace::query()
            ->where(fn ($q) => ctype_digit($arg) ? $q->whereKey((int) $arg) : $q->where('slug', $arg))
            ->first();

        if ($workspace === null) {
            $this->error("Workspace '{$arg}' não encontrado.");

            return self::FAILURE;
        }

        $cents = $this->argument('cents');

        if ($this->option('default')) {
            $workspace->forceFill(['monthly_budget_cents' => null])->save();
        } elseif ($cents !== null) {
            if (! ctype_digit((string) $cents)) {
                $this->error('O teto é um número inteiro de centavos de dólar (ex.: 3000 = US$ 30).');

                return self::FAILURE;
            }

            $workspace->forceFill(['monthly_budget_cents' => (int) $cents])->save();
        }

        $usage = Budget::usage($workspace);

        $this->line(sprintf(
            '%s: gasto %d¢ de %d¢ em %s (teto %s).',
            $workspace->name,
            $usage['spent_cents'],
            $usage['limit_cents'],
            $usage['month'],
            $usage['limit_source'] === 'workspace' ? 'do workspace' : 'padrão',
        ));

        return self::SUCCESS;
    }
}
