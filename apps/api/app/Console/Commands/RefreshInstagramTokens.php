<?php

namespace App\Console\Commands;

use App\Instagram\AccountConnector;
use App\Models\InstagramAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Roda uma vez por dia (routes/console.php). O token longo vale 60 dias e so renova
 * com mais de 24 h de vida; renovar quando faltam 10 dias da folga para uma semana
 * de falhas da Meta sem que nenhuma publicacao pare.
 *
 * O que ja venceu nao renova — a Meta recusa. Vira `expired` e a tela pede para
 * reconectar.
 */
#[Signature('instagram:refresh-tokens')]
#[Description('Renova os tokens do Instagram perto de vencer e marca os vencidos')]
class RefreshInstagramTokens extends Command
{
    public function handle(AccountConnector $connector): int
    {
        $vencidas = InstagramAccount::where('status', 'active')
            ->where('token_expires_at', '<=', now())
            ->update(['status' => 'expired', 'last_error' => 'O token venceu. Reconecte a conta.']);

        $renovar = InstagramAccount::where('status', 'active')
            ->where('token_expires_at', '<=', now()->addDays(config('instagram.refresh_when_days_left')))
            ->where('token_refreshed_at', '<=', now()->subDay())
            ->get();

        foreach ($renovar as $conta) {
            $connector->refresh($conta);
        }

        $this->info("Vencidas: {$vencidas}. Renovacoes tentadas: {$renovar->count()}.");

        return self::SUCCESS;
    }
}
