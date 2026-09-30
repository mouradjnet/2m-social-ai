# CP-04B — Rejeitar e Solicitar Ajustes

**Data:** 30/09/2026 · **Situação:** implementado e testado (sem rede, sem publicação real, sem deploy, sem migration).

## 1. Diagnóstico inicial

- `main` limpa, igual a `origin/main` (`429c899`); relatório CP-04A presente; 48 testes de aprovação/estado verdes.
- Rejeitar e pedir ajustes já existiam desde o CP-04 (motivo, versão, transação, permissão), mas **sem idempotência**, sem aceitar `expected_version`, e só em peça em revisão.
- **Brecha encontrada:** desarquivar devolvia a peça ao status de onde saiu — uma rejeitada a partir de `approved` voltaria direto para `approved`.
- **Brecha encontrada:** o "pass" antigo da IA continuava valendo depois de uma pessoa rejeitar/pedir ajustes; a peça podia voltar a "aguardando aprovação" sem nova revisão.
- O reescritor só aceitava peça reprovada pela IA: pedido de ajuste humano não abria nova versão por regeneração.

## 2. Arquivos

Backend: `Domain/Editorial/{Approval,EditorialState}.php`, `Http/Controllers/Api/V1/{ContentDecisionController,ContentController,RewriteController}.php`, `Ai/Agents/{AgentContext,RewriterAgent}.php`; testes `RejectAndChangesTest.php` (novo, 14), `ApprovalFlowTest.php` (chave nas chamadas).
Frontend: `pages/ApprovalsPage.tsx`, `components/content/ContentCard.tsx` + testes.

## 3. Endpoints

| Endpoint | Corpo |
|---|---|
| `POST /contents/{id}/request-changes` | `expected_version` (ou `version`), `reason` (≥ 3 caracteres, não só espaços), `request_key` (UUID) |
| `POST /contents/{id}/reject` | idem |
| `POST /contents/{id}/rewrite:generate` | agora também para peça com ajuste pedido por pessoa |
| `POST /contents/{id}/unarchive` | rejeitada volta para `production` |
| `GET /contents/{id}/history` | decisões agora trazem `request_key` |

Respostas das decisões: `data` (peça atual), `decision` (id, tipo, versão, motivo, quem, quando), `replayed`.

## 4. Estados e transições

| Ação | De | Para | Estado editorial |
|---|---|---|---|
| Pedir ajustes | `review` ou `approved` | `production` | NEEDS_REVISION (`needs_revision`); aprovação anterior cai |
| Rejeitar | `review` ou `approved` | `archived` | REJECTED (`rejected`) |
| Qualquer decisão em `scheduled` | — | — | 409 "desagende antes" |
| Desarquivar rejeitada | `archived` | `production` | `draft` (novo ciclo), nunca `approved` |
| Voltar a revisão após decisão humana | `production` | `review` | `in_review` até a IA revisar de novo (o "pass" anterior não vale) |

## 5. Autorização

A mesma permissão `approve` do CP-04A (revisor, admin, dono), deny-by-default. Ordem: 401 → 404 (marca alheia/inexistente) → 403 → 422 → 409. Quem decide vem da sessão; a marca, da rota + escopo.

## 6. Versionamento

- Pedir ajustes **não altera o conteúdo** (versão igual); a nova versão nasce da edição manual (`PATCH /draft`) ou da regeneração pela IA (reescritor recebe o motivo em `rewrite_target.human_request`). Ambas sobem a versão, gravam o de/para em `content_revisions` (o conteúdo anterior fica no histórico) e **não herdam aprovação**.
- Mesma peça (mesmo id): o vínculo com a original é a própria linha, com todas as versões e decisões no histórico.

## 7. Auditoria

Cada decisão grava atomicamente em `content_decisions` (peça, marca, versão, usuário, tipo, justificativa, estado anterior e posterior, data, `request_key`) e o movimento em `content_revisions`. Falha na auditoria desfaz tudo (teste). Nada de credencial ou dado sensível. Decisões imutáveis no código.

## 8. Testes

| Suíte | Resultado |
|---|---|
| PHPUnit | **587 passando** (573 + 14) |
| Vitest | **185 passando** (183 + 2) |
| Pint / oxlint / tsc + build | ok (2 avisos de lint anteriores) |

| Pedido | `RejectAndChangesTest` |
|---|---|
| 1 Ajuste válido | `test_pedir_ajustes_valido_registra_tudo_e_preserva_o_conteudo`, `…_em_peca_aprovada_derruba_a_aprovacao` |
| 2 Rejeição válida | `test_rejeitar_valido_arquiva_e_desarquivar_nao_volta_para_aprovada` |
| 3 Justificativa ausente | `test_justificativa_ausente_ou_em_branco_e_recusada` (nula, vazia, espaços, curta) |
| 4 / 5 Não autorizado, outra marca | `test_nao_autorizado_e_outra_marca` |
| 6 / 8 Versão velha, edição concorrente | `test_versao_desatualizada_e_edicao_concorrente` |
| 7 Duplicada | `test_requisicao_duplicada_devolve_o_original_e_chave_com_outro_pedido_e_recusada` |
| 9 Falha de auditoria | `test_falha_na_auditoria_desfaz_a_decisao` |
| 10 Nova versão | `test_nova_versao_apos_ajustes_por_edicao…`, `…_por_regeneracao_da_ia_leva_o_pedido_humano`, `test_decisao_humana_depois_do_veredito_exige_nova_revisao_da_ia` (**falsificado**: sem a regra nova, falha) |
| 11 / 12 / 14 Publicar rejeitada / com ajustes; sem rede | `test_rejeitada_e_com_ajustes_pendentes_nao_agendam_nem_publicam` |
| 13 Histórico | `test_historico_preserva_decisoes_motivos_e_chaves_e_e_imutavel` |
| Agendada | `test_peca_agendada_se_desagenda_antes_de_decidir` |

Tela: rejeitar mostra aviso ("não volta a ser aprovada…") e exige motivo antes de "Confirmar rejeição"; as duas ações mandam `expected_version` + `request_key` (uma por intenção) e só dão o resultado depois da resposta do servidor (mensagem com quem e versão); erro do servidor aparece sem sucesso falso; card do quadro mostra "Ajuste pedido: …" e oferece reescrever.

## 9. Riscos residuais

- Peça agendada precisa ser desagendada antes de rejeitar/pedir ajustes (proposital: há publicação preparada).
- A regeneração por pedido humano usa a IA (mock hoje); com IA real, custa uma chamada.
- A lista de campos do histórico de uma reescrita mostra `de`/`para` (formato anterior do registro).
- O `test_nova_versao_apos_ajustes_por_edicao…` avança o relógio porque o helper de teste data o veredito 1 s à frente.

## 10. Git

Commit local na `main`, sem push e sem deploy. Hash no relatório entregue ao responsável.
