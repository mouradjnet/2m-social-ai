<?php

namespace App\Ai\Agents;

use App\Models\AiRun;
use App\Models\Project;

interface Agent
{
    public function name(): string;

    /** JSON Schema aceito pela API. Sem minLength, maximum, minItems ou recursao. */
    public function schema(): array;

    /** Instrucoes de sistema. Congeladas: nada de timestamp, uuid ou id aqui. */
    public function instructions(): string;

    /** Dados do projeto, delimitados, para o turno do usuario. */
    public function userMessage(AgentContext $context): string;

    /**
     * Regras que o JSON Schema da API nao expressa. O servidor e o validador.
     *
     * @throws \App\Ai\Exceptions\OutputRejectedException
     */
    public function validate(array $output): void;

    /** Grava o resultado nas tabelas de dominio. */
    public function persist(Project $project, array $output, AiRun $run): void;
}
