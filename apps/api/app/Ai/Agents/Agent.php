<?php

namespace App\Ai\Agents;

use App\Ai\Exceptions\OutputRejectedException;
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
     * O contexto e opcional porque so quem valida contra a entrada precisa dele: o
     * social_media confere os ids e as datas contra o lote e a janela que recebeu.
     * A alternativa — o agente guardar o contexto de userMessage() num campo — faria
     * validate() depender, em silencio, de ter sido chamado depois.
     *
     * @throws OutputRejectedException
     */
    public function validate(array $output, ?AgentContext $context = null): void;

    /** Grava o resultado nas tabelas de dominio. */
    public function persist(Project $project, array $output, AiRun $run): void;
}
