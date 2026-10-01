# CP-04D — Versão esperada no SEO e na reescrita

Fecha o risco residual do CP-04C: `seo:apply` e `rewrite:generate` não pediam `expected_version`, então uma reescrita da IA (ou um SEO aplicado) podia sobrescrever uma edição humana feita ao mesmo tempo.

## O que mudou

| Rota | Antes | Agora |
|---|---|---|
| `POST /contents/{id}/seo:apply` | aplicava sobre qualquer versão | exige `expected_version` (422 sem); versão velha → **409** `{message, current_version}`, nada aplicado, `applied_at` continua nulo |
| `POST /contents/{id}/rewrite:generate` | criava o run sem versão | exige `expected_version` (422 sem); versão velha → **409** antes de criar o run (não paga a IA); a versão vai no `input` do run |
| `RewriterAgent::persist` | gravava por cima | trava a linha e confere a versão do `input`; mudou → nada gravado |

Quando a peça muda **durante** a execução, o `RunAgentJob` marca o run `failed` com o código novo **`content_changed`**: a transação é desfeita (nenhuma versão `ai_rewrite`), o texto proposto fica no `output` para consulta, e o custo pago fica em `cost_cents`. Não é retentável automaticamente (a tela só oferece "tentar de novo" para `provider_failed`): a pessoa precisa ver a versão atual antes de pedir de novo.

Runs enfileirados antes do deploy não têm `expected_version` no input e caem no mesmo caminho (fail-closed: na dúvida, não sobrescreve).

**Migration:** `2026_10_01_010000` troca o CHECK de `ai_runs.error_code` para aceitar `content_changed`. Up → down → up conferido no banco local; o down converte `content_changed` em `provider_failed`.

**Web:** o quadro de conteúdo (Aplicar SEO, Reescrever) e a tela de Aprovações (regenerar) mandam a versão da peça que estão mostrando.

## Testes

- `SeoGenerationTest`: `test_aplicar_sem_expected_version_e_422`, `test_aplicar_com_versao_velha_e_409_e_nao_apaga_a_edicao` (**falsificado**: sem a checagem, 200 e o título editado some).
- `RewriteGenerationTest`: `test_sem_expected_version_e_422_e_nao_cria_execucao`, `test_versao_velha_no_pedido_e_409_e_nao_cria_execucao`, `test_edicao_durante_a_reescrita_nao_e_sobrescrita` (**falsificado**: sem a checagem no persist, o run termina `succeeded` e apaga a edição).
- `ContentPage.test.tsx`: os POSTs de SEO e reescrita levam `{expected_version}`.

Gate: pint ok, `php artisan test` 614/614, oxlint (só os 2 avisos antigos), `tsc -b`, vitest 188/188.

## Riscos residuais

- Web e API precisam subir juntos: aba aberta antes do deploy recebe 422 ao aplicar SEO ou pedir reescrita até recarregar.
- O DELETE em `content_versions` continua possível pelo cascade da peça (risco do CP-04C, não tratado aqui).
