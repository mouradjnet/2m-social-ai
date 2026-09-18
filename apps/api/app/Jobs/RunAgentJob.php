<?php

namespace App\Jobs;

use App\Ai\Agents\Agent;
use App\Ai\Agents\AgentContext;
use App\Ai\Agents\AgentRegistry;
use App\Ai\Exceptions\LlmRefusedException;
use App\Ai\Exceptions\OutputRejectedException;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\LlmRequest;
use App\Ai\Providers\LlmResponse;
use App\Models\AiRun;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAgentJob implements ShouldQueue
{
    use Queueable;

    /**
     * Toda chamada ao provedor, inclusive as rejeitadas pelo validate(): todas
     * foram pagas, e o Budget so enxerga o que for gravado em `cost_cents`.
     *
     * @var list<LlmResponse>
     */
    private array $respostas = [];

    public function __construct(private readonly int $aiRunId) {}

    public function handle(LlmProvider $provider, AgentRegistry $registry): void
    {
        // Jobs rodam sem usuario autenticado, entao o WorkspaceMemberScope nao
        // se aplica. O escopo aqui e responsabilidade deste codigo.
        $run = AiRun::withoutGlobalScopes()->findOrFail($this->aiRunId);
        $project = Project::withoutGlobalScopes()->findOrFail($run->project_id);
        $agent = $registry->get($run->agent);

        $run->update(['status' => 'running']);
        $startedAt = microtime(true);

        try {
            $output = $this->generateAndValidate($provider, $agent, $project, $run->input ?? []);
        } catch (Throwable $e) {
            $errorCode = $this->errorCodeFor($e);

            // O usuario recebe uma frase amigavel; o operador precisa da causa.
            // Sem isto, uma falha de TLS chega ao banco como "tente novamente" e
            // nada mais — sem classe, sem mensagem, sem stack.
            //
            // Contexto e so id e classificacao: o prompt carrega o perfil da
            // marca, e log nao e lugar de dado do cliente.
            Log::error('Falha na execucao de agente de IA.', [
                'ai_run_id' => $run->id,
                'agent' => $run->agent,
                'provider' => $run->provider,
                'error_code' => $errorCode,
                'exception' => $e,
            ]);

            $run->update([
                'status' => 'failed',
                'error' => $this->userFacingMessage($e),
                'error_code' => $errorCode,
                'latency_ms' => $this->elapsedMs($startedAt),
                ...$this->gasto(),
            ]);

            return;
        }

        DB::transaction(function () use ($agent, $project, $output, $run, $startedAt) {
            $agent->persist($project, $output['data'], $run);

            $run->update([
                'status' => 'succeeded',
                'output' => $output['data'],
                ...$this->gasto(),
                'latency_ms' => $this->elapsedMs($startedAt),
            ]);
        });
    }

    /**
     * Uma retentativa quando o modelo devolve JSON valido mas fora das regras de
     * dominio. Recusa por seguranca nao se repete: o mesmo prompt sera recusado
     * de novo.
     */
    private function generateAndValidate(LlmProvider $provider, Agent $agent, Project $project, array $input): array
    {
        $config = config("ai.agents.{$agent->name()}");
        $context = AgentContext::forProject($project, $input);

        $request = new LlmRequest(
            model: $config['model'],
            instructions: $agent->instructions(),
            userMessage: $agent->userMessage($context),
            schema: $agent->schema(),
            effort: $config['effort'],
            maxTokens: $config['max_tokens'],
        );

        foreach ([1, 2] as $attempt) {
            $response = $provider->generate($request);
            $this->respostas[] = $response;

            try {
                $agent->validate($response->output, $context);

                return ['data' => $response->output];
            } catch (OutputRejectedException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new OutputRejectedException('inalcancavel');
    }

    /**
     * O job morreu fora do handle(): timeout, ou o processo derrubado no meio
     * (deploy, container reciclado) e o job reapanhado depois do `retry_after`,
     * ja sem tentativas. O catch do handle() nunca rodou, entao a execucao ficaria
     * `running` para sempre — e o indice parcial devolveria 409 naquele agente.
     *
     * So mexe em execucao ainda ativa: nao reescreve uma que ja terminou. O custo
     * de uma chamada em voo se perde aqui; nao ha resposta para medi-lo.
     */
    public function failed(?Throwable $e): void
    {
        AiRun::withoutGlobalScopes()
            ->whereKey($this->aiRunId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => 'A geracao foi interrompida. Tente novamente.',
                'error_code' => 'provider_failed',
            ]);
    }

    /**
     * Soma de todas as chamadas deste job. Custo por chamada, arredondado para
     * cima em cada uma: e assim que cada uma e cobrada.
     */
    private function gasto(): array
    {
        $soma = fn (callable $campo) => array_sum(array_map($campo, $this->respostas));

        return [
            'input_tokens' => $soma(fn (LlmResponse $r) => $r->inputTokens),
            'output_tokens' => $soma(fn (LlmResponse $r) => $r->outputTokens),
            'cache_read_tokens' => $soma(fn (LlmResponse $r) => $r->cacheReadTokens),
            'cache_write_tokens' => $soma(fn (LlmResponse $r) => $r->cacheWriteTokens),
            'cost_cents' => $soma(fn (LlmResponse $r) => $r->costCents(config('ai.pricing'))),
        ];
    }

    /**
     * Irmao de userFacingMessage(). Fazem `match` na mesma coisa de proposito:
     * um responde a um humano, o outro a uma maquina. A mensagem vai mudar de
     * redacao; o codigo nunca pode mudar.
     */
    private function errorCodeFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof LlmRefusedException => 'refused',
            $e instanceof OutputRejectedException => 'rejected_output',
            default => 'provider_failed',
        };
    }

    private function userFacingMessage(Throwable $e): string
    {
        return match (true) {
            $e instanceof LlmRefusedException => $e->getMessage(),
            $e instanceof OutputRejectedException => 'O modelo devolveu um resultado fora das regras: '.$e->getMessage(),
            default => 'Falha ao gerar. Tente novamente em alguns instantes.',
        };
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
