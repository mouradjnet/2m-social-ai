<?php

namespace App\Jobs;

use App\Ai\Agents\Agent;
use App\Ai\Agents\AgentContext;
use App\Ai\Agents\AgentRegistry;
use App\Ai\Exceptions\LlmRefusedException;
use App\Ai\Exceptions\OutputRejectedException;
use App\Ai\Providers\LlmProvider;
use App\Ai\Providers\LlmRequest;
use App\Models\AiRun;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunAgentJob implements ShouldQueue
{
    use Queueable;

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
            $output = $this->generateAndValidate($provider, $agent, $project);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => $this->userFacingMessage($e),
                'error_code' => $this->errorCodeFor($e),
                'latency_ms' => $this->elapsedMs($startedAt),
            ]);

            return;
        }

        DB::transaction(function () use ($agent, $project, $output, $run, $startedAt) {
            $agent->persist($project, $output['data'], $run);

            $run->update([
                'status' => 'succeeded',
                'output' => $output['data'],
                'input_tokens' => $output['response']->inputTokens,
                'output_tokens' => $output['response']->outputTokens,
                'cache_read_tokens' => $output['response']->cacheReadTokens,
                'cache_write_tokens' => $output['response']->cacheWriteTokens,
                'cost_cents' => $output['response']->costCents(config('ai.pricing')),
                'latency_ms' => $this->elapsedMs($startedAt),
            ]);
        });
    }

    /**
     * Uma retentativa quando o modelo devolve JSON valido mas fora das regras de
     * dominio. Recusa por seguranca nao se repete: o mesmo prompt sera recusado
     * de novo.
     */
    private function generateAndValidate(LlmProvider $provider, Agent $agent, Project $project): array
    {
        $config = config("ai.agents.{$agent->name()}");
        $context = AgentContext::forProject($project);

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

            try {
                $agent->validate($response->output);

                return ['data' => $response->output, 'response' => $response];
            } catch (OutputRejectedException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new OutputRejectedException('inalcancavel');
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
