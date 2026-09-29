# CP-02 — Integração de Inteligência Artificial Real

**Data:** 29/09/2026 · **Situação:** integração **implementada e testada sem chamada real**. Nenhuma chamada paga foi feita; a validação com a API real depende de autorização (ver §10).

> Implementada ≠ validada. Tudo abaixo foi provado com transporte HTTP falso e com o `MockProvider`. O comportamento da API real (modelo disponível na conta, latência, custo efetivo) só uma chamada real confirma.

## 1. Estado encontrado no CP-01

| Verificação | Resultado |
|---|---|
| Branch / árvore | `main`, limpa, igual a `origin/main` |
| Commits do CP-01 | `083444f` (concorrentes), `675b47f` (Pular, validação, PT-BR), `3a035d0` (regressão + concorrente) |
| Relatório do CP-01 | Só na conversa; **não havia arquivo no repositório** (não existia `docs/checkpoints/`) |
| Testes de regressão do CP-01 | 40 PHPUnit + 32 vitest passando |
| Migrations pendentes | Nenhuma |

CP-01 considerado validado.

## 2. Diagnóstico da integração Anthropic anterior

A arquitetura já era a certa e foi **preservada**: um contrato `LlmProvider` com dois motores (`AnthropicProvider` e `MockProvider`), escolhidos por `AI_PROVIDER`; um único job (`RunAgentJob`) que executa os 11 agentes; custo e tokens gravados em `ai_runs`; teto mensal por workspace (`Budget`); a chave só no backend (`config/services.php`), o `.env` fora do Git, o mock como padrão. Não foi criado um segundo motor.

| Item | Encontrado | Risco |
|---|---|---|
| SDK | `anthropic-ai/sdk` **0.7.0** (PHP) | Antigo, mas suficiente; ver os dois defeitos abaixo |
| Modelo | `claude-opus-4-8` em todos os agentes | Vigente (na tabela oficial em 29/09/2026), não é o mais novo |
| Parâmetros | `thinking: adaptive`, `output_config.effort`, `output_config.format` json_schema, sem temperature e sem prefill | Corretos para o Opus 4.8 |
| **Timeout** | **Nenhum.** O SDK 0.7 aceita `timeout` mas não o aplica; o transporte descoberto (Guzzle sem opções) espera para sempre | Chamada presa até o worker matar o job (180 s), sem custo nem causa gravados |
| **Retries** | O `maxRetries` dado ao Client **é ignorado** pelo SDK 0.7 (o `parseRequest()` cria opções por requisição com o padrão 2, que sobrescrevem) — achado por teste neste CP | 3 chamadas por falha, sem controle |
| `retry-after` do 429 | Obedecido sem teto pelo SDK | Um `retry-after: 60` estoura o worker |
| Erros | 401, 429, 529, 5xx, timeout e queda de rede viravam a mesma "falha genérica", inclusive no log | Operador não distingue chave errada de sobrecarga |
| Validação de config | A falta da chave só aparecia na primeira geração | Erro descoberto pelo usuário, não no deploy |
| **Modelo sem preço** | `costCents()` devolve **0** para modelo fora de `ai.pricing` | Trocar `AI_MODEL_DEFAULT` desligaria o teto mensal em silêncio |
| Limites | Teto por workspace; saída limitada por `max_tokens` (16000) | Sem teto por marca, sem limite de entrada, sem alerta |
| Chamadas repetidas | Índice parcial único + 409 por agente/projeto; fila com `--tries=1`; 1 retentativa só para saída fora das regras | Adequado; mantido |
| Persistência / log | `ai_runs` com tokens, custo, latência, modelo, erro; log só com ids (sem dado da marca) | Adequado; ampliado com o tipo da falha |

## 3. Arquitetura implementada

```
controller ──Budget::refusal(projeto)──► 402 (workspace ou projeto no teto)
     │
     └─► RunAgentJob (fila, --tries=1, --timeout=180)
            ├─ app(LlmProvider)  ← monta aqui: config errada vira falha registrada
            │     └─ AnthropicProvider(Client, maxRetries por chamada)
            │           └─ CappedRetryAfterTransport → Guzzle(timeout 75 s, connect 10 s)
            ├─ trava de entrada estimada (ai.max_input_tokens) — antes de pagar
            ├─ generate → erros do SDK viram LlmFailedException{kind}
            ├─ grava tokens + custo estimado + latência em ai_runs
            └─ Budget::alertIfAbnormal → Log::warning (execução cara, 80% do teto)

AiConfig::problems()  ← usado pelo provedor, por `php artisan ai:check` e pelo docker/start.sh
```

- **Tipos de falha** (`LlmFailedException::kind`): `auth`, `config`, `rate_limited`, `overloaded` (529), `timeout`, `connection`, `server_error`, `bad_request`, `invalid_output`, `input_too_large`. A pessoa recebe uma frase por tipo ("A IA está sobrecarregada agora…", "A IA não está configurada corretamente no servidor…"); o log recebe `provider_error` + status e tipo HTTP, nunca o corpo inteiro nem a requisição.
- **`error_code` no banco não mudou** (`refused` / `rejected_output` / `provider_failed`): o detalhe vai para o log e para a mensagem. Evita migration.
- **Pior caso de tempo:** 75 s × 2 tentativas + 10 s de espera = 160 s < 180 s do worker. O `ai:check` recusa uma combinação que não caiba.

## 4. Arquivos modificados

**Novos:** `app/Ai/AiConfig.php`, `app/Ai/Providers/CappedRetryAfterTransport.php`, `app/Console/Commands/CheckAiConfig.php` (`ai:check`), `tests/Feature/AiIntegrationTest.php`, `tests/Unit/AgentSchemaCompatibilityTest.php`, `tests/Unit/CappedRetryAfterTransportTest.php`, este documento.

**Alterados:** `app/Ai/Providers/AnthropicProvider.php` (cadeia de erros, `maxRetries` por chamada, JSON não-objeto), `app/Ai/Exceptions/LlmFailedException.php` (`kind`), `app/Providers/AppServiceProvider.php` (transporte com timeout, validação), `app/Jobs/RunAgentJob.php` (provedor dentro do try, trava de entrada, mensagens por tipo, alerta), `app/Ai/Budget.php` (teto por projeto, `refusal()`, `alertIfAbnormal()`), os 12 controllers que enfileiram IA (o bloco de 402 virou `Budget::refusal($project)`), `config/ai.php`, `docker/start.sh` (`php artisan ai:check`), `apps/api/.env.example`, `deploy/vps/.env.example`, `tests/Unit/AnthropicProviderTest.php`.

Nada no frontend, no Instagram/Meta, na publicação ou no banco.

## 5. Agentes verificados (11)

Todos passam pelo mesmo motor (`RunAgentJob` → `LlmProvider`), recebem o `brand_profile` completo (12 campos: nome, descrição, produtos, serviços, público, persona, tom, diferenciais, concorrentes, palavras obrigatórias e proibidas, cores) via `AgentContext::toArray()`, devolvem JSON por schema (`output_config.format`) e têm `validate()` de domínio com 1 retentativa.

| Agente | Finalidade | Contexto além do perfil | Effort |
|---|---|---|---|
| strategist | Linha editorial e pilares | — | high |
| copywriter | Escreve as peças | estratégia ativa, pilar alvo | high |
| social_media | Distribui peças aprovadas no calendário | aprovadas + janela | medium |
| reviewer | Julga as peças contra o perfil | lote em revisão | high |
| designer | Escreve o `image_prompt` | lote em produção | medium |
| seo | Título, keywords, hashtags | lote em produção | medium |
| analytics | Lê os números do calendário | métricas editoriais | high |
| rewriter | Conserta UMA peça reprovada | peça + veredito | high |
| planner | Monta a semana (dia, hora, pilar, formato) | estratégia, pilar atrasado, janela | medium |
| repurposer | Adapta uma peça a outro formato | peças existentes | medium |
| results | Lê resultados reais da Meta | métricas da Meta | high |

**Compatibilidade com a API real:** os 11 schemas usam só o subconjunto de JSON Schema aceito pelas saídas estruturadas (sem `minLength`, `maximum`, `pattern`…, todo objeto com `additionalProperties: false`). O SDK PHP não remove o que a API recusa; um desses viraria 400 na primeira chamada real. Agora há teste (`AgentSchemaCompatibilityTest`). Nenhuma finalidade foi alterada; nenhum agente foi criado.

## 6. Variáveis de ambiente (sem segredos)

| Variável | Padrão | Para quê |
|---|---|---|
| `AI_PROVIDER` | `mock` | `anthropic` liga a IA real |
| `ANTHROPIC_API_KEY` | — | Só backend. Nunca no frontend, nunca no Git |
| `AI_MODEL_DEFAULT` (+ `AI_MODEL_<AGENTE>`) | `claude-opus-4-8` | Modelo por ambiente/agente; **precisa ter preço em `ai.pricing`** |
| `AI_TIMEOUT_SECONDS` | 75 | Timeout real do transporte |
| `AI_MAX_RETRIES` | 1 | Repetições do SDK (429/5xx/conexão) |
| `AI_MAX_INPUT_TOKENS` | 60000 | Trava de entrada estimada por chamada |
| `AI_WORKSPACE_MONTHLY_BUDGET_CENTS` | 5000 | Teto do workspace (centavos de dólar) |
| `AI_PROJECT_MONTHLY_BUDGET_CENTS` | vazio | Teto por marca; vazio = só o do workspace |
| `AI_ALERT_RUN_COST_CENTS` | 100 | Alerta de execução cara |

Conferência sem gastar: `php artisan ai:check` (mostra se a chave existe, nunca a chave).

## 7. Testes executados e resultados

| Suíte | Resultado |
|---|---|
| PHPUnit | **515 passando** (484 antes + 31 novos) |
| Vitest | 163 passando |
| Pint / lint (oxlint) / tsc + build | ok (2 avisos de lint anteriores ao CP) |

Cobertura dos 14 itens pedidos:

| # | Item | Teste |
|---|---|---|
| 1 | Modo mock | `AiIntegrationTest::test_mock_passa_no_ai_check…`, `StrategyGenerationTest::test_geracao_no_mock_nao_consome…` |
| 2 | Sem chave | `test_anthropic_sem_chave_falha_com_mensagem_de_configuracao…` |
| 3 | Resposta válida | `AnthropicProviderTest::test_ignora_o_bloco_de_thinking…`, `test_429_seguido_de_sucesso…` |
| 4 | Resposta inválida | `test_json_invalido…`, `test_json_que_nao_e_objeto…`, `test_resposta_truncada…`, `test_saida_fora_das_regras…` |
| 5 | Timeout | `test_timeout_do_transporte_vira_timeout`, `test_provedor_real_e_montado_com_timeout…` |
| 6 | Rate limit | `test_cada_erro_http…` (429, 529), `test_429_repete_so_ate_o_limite…`, `CappedRetryAfterTransportTest` |
| 7 | Autenticação | `test_cada_erro_http…` (401, 403), `test_401_nao_e_repetido` |
| 8 | Conexão | `test_queda_de_conexao_vira_connection` |
| 9 | Controle de tokens | `test_entrada_acima_do_limite_nao_chama_o_provedor`, `test_modelo_sem_preco…`, `test_timeout_que_nao_cabe…` |
| 10 | Persistência | `test_prompt_leva_so_o_perfil…` (status), `StrategyGenerationTest::test_retentativa_bem_sucedida_grava_o_custo…` |
| 11 | Contexto da marca | `test_prompt_leva_so_o_perfil_da_propria_marca` |
| 12 | Isolamento entre projetos | idem + `test_teto_por_projeto_barra_so_a_marca…`, testes de outro workspace existentes |
| 13 | Credenciais fora do log | `test_falha_de_autenticacao_nao_vaza_a_chave_no_log` (log real formatado, com stack), `test_ai_check_nao_imprime_a_chave` |
| 14 | Sem publicação automática | `test_prompt_leva_so_o_perfil…` (0 publicações), `ApprovalTest::test_porta_recusa_peca_sem_aprovacao` |

Nenhum teste chama a rede: o `AnthropicProvider` real roda com transporte falso.

## 8. Controle de consumo

- **Por operação:** saída limitada por `max_tokens` do agente (16000); entrada barrada antes da chamada acima de `AI_MAX_INPUT_TOKENS` (estimativa conservadora: caracteres ÷ 3).
- **Por mês:** teto do workspace (já existia) **+ teto por projeto/marca** (novo), conferidos antes de enfileirar (402).
- **Registro:** tokens de entrada, saída e cache, custo, latência e modelo em `ai_runs`; tela **Consumo** por agente e projeto (já existia).
- **Custo é estimativa:** calculado pela tabela `ai.pricing` (preços públicos conferidos em 29/09/2026). **O valor faturado está no console da Anthropic.** Modelo sem preço impede a subida.
- **Chamadas repetidas:** 409 por agente/projeto em andamento; fila sem repetição (`--tries=1`); SDK com no máximo `AI_MAX_RETRIES` repetições; saída fora das regras com 1 retentativa. Recusa de segurança não se repete.
- **Rate limit:** 429 repetido uma vez com espera limitada a 10 s; depois, mensagem "tente em alguns minutos".
- **Alertas:** `Log::warning` para execução acima de `AI_ALERT_RUN_COST_CENTS` e quando workspace ou projeto cruza 80% do teto.

## 9. Riscos residuais

- **Nada foi validado contra a API real** (modelo disponível na conta, schemas aceitos pela API, latência do Opus 4.8 com effort `high` e 16000 de saída cabendo em 75 s).
- **Latência:** gerações longas sem streaming podem passar de 75 s. Se acontecer, o registro mostra `provider_error: timeout`; subir `AI_TIMEOUT_SECONDS` exige também subir o `--timeout` do worker (o `ai:check` confere).
- **O teto é conferido antes de enfileirar**, não durante: a última execução do mês pode passar um pouco do limite (no máximo o custo de uma chamada).
- **Alertas só no log:** não há e-mail nem painel; alguém precisa olhar o log.
- **SDK 0.7:** dois defeitos contornados aqui (timeout e maxRetries). Atualizar o SDK é outro trabalho, com seu próprio risco.
- **Modelo:** `claude-opus-4-8` é vigente, mas o atual é `claude-opus-5-5` (mais barato, US$ 4/20 por milhão contra 5/25). A migração é decisão de produto e exige validação real; o preço dele já está na tabela.
- **Teste instável anterior** (`ResultsTest`, desempate de `published_at`) segue fora do escopo.
- **O `docker/start.sh` agora roda `ai:check`:** com `AI_PROVIDER=anthropic` e config errada, o container não sobe (é o objetivo). Com o mock, passa sempre.

## 10. Pendências para validação real

1. Autorização explícita para uma chamada paga de validação (estimativa: centavos de dólar).
2. Chave de API real no `.env` do servidor escolhido (nunca no Git), `AI_PROVIDER=anthropic`, `php artisan ai:check` verde.
3. Uma geração de **estratégia** para a 2M Saúde Feminina com o perfil completo, conferindo: status `succeeded`, tokens e custo gravados, custo batendo com o console da Anthropic, latência abaixo do timeout.
4. Decidir o modelo (manter Opus 4.8 ou migrar para Opus 5.5) antes de ligar em produção.
5. Definir `AI_PROJECT_MONTHLY_BUDGET_CENTS` e o teto do workspace para o piloto.

## 11. Estado final do Git

Commit local na `main` (sem push, sem deploy). Hash registrado no relatório entregue ao responsável.
