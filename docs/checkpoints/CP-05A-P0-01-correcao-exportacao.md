# CP-05A / P0-01 — Correção da exportação ZIP

Corrige só o achado P0-01 da auditoria operacional de 01/10/2026 (`CP-05A-auditoria-operacional.md`). Sem deploy, sem migration, sem alteração de banco e sem push.

## Causa raiz

`ContentZip::pecas()` escolhia as peças só pelo status (`approved`, `scheduled`). O status não prova a aprovação: a regra de verdade, desde o CP-04, é `Approval::validApproval`, que exige três coisas:

- a última decisão humana é `approved`;
- a decisão é da versão atual da peça;
- o sha256 do snapshot aprovado bate com o da peça agora.

O `PublishGate` já usava essa regra antes de publicar no Instagram; a exportação, não.

Por isso, uma peça podia continuar `approved`/`scheduled` sem aprovação válida e sair no zip rotulada como "Aprovado". Os casos:

- peças aprovadas antes do CP-04 (sem snapshot);
- conteúdo alterado por fora do fluxo;
- versão avançada sem nova decisão.

## Correção

`apps/api/app/Domain/Export/ContentZip.php`:

- O status passa a ser só o pré-filtro. Cada peça passa por `Approval::validApproval($peca)`, o mesmo mecanismo do `PublishGate`, sem regra paralela. Sem decisão válida, a peça não entra no zip nem no CSV.
- A decisão vigente fica na relação `aprovacaoVigente` da peça. O `calendario.csv` ganha duas colunas no fim: **Versao aprovada** e **Hash aprovado** (sha256 do snapshot do conteúdo, o mesmo gravado em `content_decisions.snapshot_hash`). Nenhum outro dado é exposto: nem quem aprovou, nem e-mail, nem id interno da decisão.
- O controller não mudou. Com zero peças válidas, a resposta continua sendo o 422 já existente ("Não há peça aprovada ou agendada para exportar."), e não um zip vazio.

**Isolamento e autorização:** sem mudança e cobertos por testes.

- A rota segue `Gate::authorize('view', $project)`.
- O projeto de outro workspace some pelo `WorkspaceMemberScope` (404), e sem token a resposta é 401.
- A seleção parte de `$project->contents()`, ou seja, só as peças do projeto pedido.

**Contratos e banco:**

- Nenhuma migration e nenhum endpoint novo; códigos HTTP inalterados.
- O formato do CSV mudou por pedido explícito (item 5): duas colunas acrescentadas no fim, as anteriores na mesma posição.

## Arquivos alterados

| Arquivo | Mudança |
|---|---|
| `apps/api/app/Domain/Export/ContentZip.php` | filtro por `Approval::validApproval`; 2 colunas no CSV |
| `apps/api/tests/Feature/ExportTest.php` | helper `peca()` aprova pelo serviço real (`Tests\Support\Aprovar`) em vez de gravar `status => approved`; helper `legada()`; 7 testes novos; cabeçalho do CSV atualizado |
| `docs/checkpoints/CP-05A-P0-01-correcao-exportacao.md` | este documento |

## Testes

| Pedido | Teste |
|---|---|
| Conteúdo com aprovação válida (versão e hash no CSV iguais aos da decisão) | `test_peca_com_aprovacao_valida_sai_com_versao_e_hash` |
| Status `approved` sem decisão válida | `test_status_approved_sem_decisao_valida_fica_de_fora` |
| Hash divergente (conteúdo mudado por SQL, sem subir a versão) | `test_hash_divergente_fica_de_fora` |
| Aprovação de versão antiga | `test_aprovacao_de_versao_antiga_fica_de_fora` |
| Conteúdo alterado após aprovação (caminho normal: volta para revisão) | `test_conteudo_alterado_apos_aprovacao_fica_de_fora` |
| Agendado com aprovação vencida | `test_agendada_com_aprovacao_vencida_fica_de_fora` |
| Acesso a outro workspace | `test_projeto_de_outro_workspace_nao_existe` (404, agora com peça aprovada de verdade no alvo); `test_sem_autenticacao_nao_passa` (401) |
| Exportação vazia | `test_so_aprovacoes_invalidas_e_exportacao_vazia` (422); `test_sem_peca_pronta_nao_ha_o_que_exportar` |
| Regressão de conteúdo corretamente aprovado | os 5 testes que já existiam (markdown e calendário, rascunho fora, data e canal, fuso do projeto, viewer exporta), agora com aprovação real |

**Antes da correção:** os 5 testes antigos falharam assim que passaram a exigir aprovação real, o que prova que dependiam só do status.

**Falsificação:** com o `ContentZip` original e os testes novos, falham os 6 casos de bloqueio e o das colunas do CSV. O de "alterado após aprovação" passa nas duas versões: no caminho normal o model já devolve a peça para `review`. Ele fica como teste de regressão.

**Gate:**

- `ExportTest`: 15/15.
- `pint --test`: ok.
- `php artisan test`: 622/622.
- `pnpm lint`: 0 erros, os 2 avisos antigos.
- `pnpm exec tsc -b`: ok.
- `pnpm test`: 188/188.
- `pnpm build`: ok.

## Riscos residuais

- **Mensagem do 422:** "Não há peça aprovada ou agendada para exportar." aparece também quando há peças `approved` com aprovação vencida. A pessoa pode ver peças "aprovadas" na tela e o zip recusar. A mensagem não foi alterada para não mudar o contrato; uma versão mais clara ("aprovação vencida, aprove de novo") pode vir num próximo passo.
- **Peças pré-CP-04 em produção:** elas continuam `approved` no banco, apenas deixam de sair no zip. Precisam ser aprovadas de novo. No Neon a quantidade não foi verificada (achado P1 da auditoria).
- **Custo:** `validApproval` faz uma consulta da decisão e monta o snapshot (com as mídias) por peça. O custo é linear no número de peças aprovadas do projeto, aceitável no volume atual.
- **Fora deste checkpoint:** os outros achados da auditoria (P1 a P3) não foram tocados.

## Git

Alterações locais, **sem commit e sem push**, aguardando revisão. O relatório `CP-05A-auditoria-operacional.md` também segue não versionado.
