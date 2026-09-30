# CP-04 — Aprovação Humana e Governança Editorial

**Data:** 30/09/2026 · **Situação:** implementado e testado com a Meta simulada. Sem deploy, sem publicação real, sem mudança no banco de produção.

**Regra:** nenhuma peça é agendada ou publicada sem aprovação humana explícita, válida e presa à versão exata do conteúdo.

## 1. Estado dos checkpoints anteriores

| Item | Resultado |
|---|---|
| Branch / árvore | `main`, limpa; CP-03 (`733e313`) só local, não pushado |
| Relatórios | CP-02 e CP-03 em `docs/checkpoints/`; **CP-01 só na conversa** |
| Testes antes | 543 PHPUnit verdes |
| Migrations pendentes | Nenhuma |
| CP-02 | Implementado, **não validado com chamada real** (tudo aqui usa mock) |

Nada bloqueante.

## 2. Diagnóstico do fluxo existente

Já havia: aprovação só por revisor+ (`approved_by/approved_at`), edição de texto/imagem/slides/vídeo só antes da aprovação, a porta `PublishGate` (recusava texto mudado depois da aprovação, pela ordem das revisões), publicações com snapshot da legenda, fila `publishing` com 1 tentativa por job, índice único peça+horário e histórico em `content_revisions`.

Brechas encontradas:

| # | Brecha | Risco |
|---|---|---|
| B1 | Aprovar era um `PATCH status` **sem versão** | Aprovar um texto que o revisor nunca viu (edição concorrente) |
| B2 | O `Publisher` (worker) **não revalidava** a aprovação | Retentativa/reconciliação horas depois publica sem conferir |
| B3 | Sem snapshot imutável do aprovado; validade inferida pela ordem das revisões | Mudança por caminho não rastreado passaria |
| B4 | Sem rejeição / pedido de ajustes humanos com motivo | Decisão sem registro |
| B5 | O agente de social media agendava qualquer `approved` | Agenda aprovação vencida |
| B6 | Nenhuma defesa no model | A regra dependia de cada endpoint |

## 3. Arquitetura implementada

- **Migration** `2026_09_30_020000_add_content_versions_and_decisions` (autorizada, só acréscimo; `down()` testado): `contents.version` (default 1), tabela **`content_decisions`** append-only (versão, decisão, motivo, estado anterior/posterior, usuário, data, **snapshot jsonb + sha256**), `publications.content_version` e `publications.decision_id`.
- **`Domain\Editorial\Approval`**: `snapshot()` do que vai ao ar (título, legenda, CTA, hashtags, roteiro, formato, canal, imagem, vídeo, slides, legenda final composta); `hash()` canônico; `validApproval()` fail-closed (última decisão = aprovada, mesma versão, mesmo hash); `approve / reject / requestChanges` em transação com `lockForUpdate` e checagem de versão (**409** se mudou).
- **`ContentDecision`** imutável no código (update/delete lançam exceção).
- **`Content` (model)**: mudança em qualquer campo que vai ao ar sobe a versão; se a peça estava aprovada/agendada, a aprovação **cai** (volta a revisão, sem aprovador e sem data) e a publicação pendente não enviada é cancelada — em qualquer caminho de código (endpoint, agente, job). Troca de slides chama `markContentChanged()`.
- **`PublishGate`**: `approvalRefusal()` (aprovação válida para a versão), `refusal()` (+ agendada com data) e **`publicationRefusal()`** (publicação presa à aprovação vigente, mesma versão, mesma legenda aprovada, mesmo projeto).
- **Onde a porta roda:** agendar (`schedule`), remarcar, `Dispatcher::prepare`, **`Publisher` antes de criar o container e imediatamente antes do `media_publish`** (inclusive após reconciliação), e o agente de social media (contexto e `persist`).
- **Sem atalho:** `PATCH status → approved` é recusado (422); o cliente não define versão; o agente revisor recomenda, nunca aprova.

## 4. Estados e transições

Estados (derivados no servidor, `editorial_state` em toda peça; o frontend só lê):

| Estado | Como é obtido |
|---|---|
| DRAFT `draft` | `idea` / `production` |
| NEEDS_REVISION `needs_revision` | IA reprovou, ou humano pediu ajustes |
| IN_REVIEW `in_review` | `review` sem veredito válido da IA |
| PENDING_APPROVAL `pending_approval` | `review` com IA aprovando, ou `approved` com aprovação vencida |
| APPROVED `approved` | `approved` + aprovação válida para a versão |
| REJECTED `rejected` | arquivada pela decisão humana de rejeitar |
| SCHEDULED `scheduled` | `scheduled`, publicação pendente |
| PUBLISHING `publishing` | publicação em andamento |
| PUBLISHED `published` | publicada |
| FAILED `failed` | publicação falhou / sem resposta da Meta |
| CANCELLED `cancelled` | publicação cancelada (desagendada, remarcada, porta fechou) |

Transições humanas (revisor+): `review → approved` (aprovar, com versão), `review → archived` (rejeitar, com motivo), `review → production` (pedir ajustes, com motivo); as mesmas a partir de `approved` com aprovação vencida. Agendar exige aprovação válida. Mudança de conteúdo em `approved/scheduled` → `review` automaticamente. Desagendar/mover a peça cancela a publicação pendente ainda não enviada (atômico; se o worker já pegou, 409).

## 5. Endpoints

| Endpoint | Tipo |
|---|---|
| `POST /contents/{id}/approve` `{version}` | novo — revisor+, 409 em versão velha |
| `POST /contents/{id}/reject` `{version, reason}` | novo — motivo obrigatório |
| `POST /contents/{id}/request-changes` `{version, reason}` | novo — motivo obrigatório |
| `GET /contents/{id}/history` | novo — decisões + movimentos + mudanças, sem dado sensível |
| `PATCH /contents/{id}` | alterado — não aprova mais; remarcar exige aprovação válida; mover cancela publicação pendente não enviada |
| `POST /contents/{id}/schedule` | alterado — exige aprovação válida para a versão |
| `PUT /contents/{id}/slides` | alterado — troca de slides sobe a versão |
| `GET /projects/{id}/contents` | alterado — traz `version`, `latest_decision`, `editorial_state` |

## 6. Componentes reutilizados

Frontend: `InstagramPreview`, `StructurePreview`, `Card`, `Button`, `Textarea`, `Shell`, o editor `PublicationEditor` (a Central abre o existente via `/content?editar=<id>`, sem duplicar), a mesma query `['contents', projectId]` do quadro. Backend: `Caption::compose`, `Dispatcher`, `Publisher`, `ContentRevision`, `WorkspaceMemberScope` e a Policy do projeto.

**Nova tela:** `/projects/:id/aprovacoes` — Central de Aprovação (marca, formato, título, legenda, hashtags, mídia/prévia, data sugerida, estado, versão, revisão da IA; ações Aprovar versão N, Solicitar ajustes, Rejeitar, Editar, Nova geração, Histórico). Responsiva: uma coluna no celular. O botão "Avançar" do quadro aprova pelo mesmo endpoint, com a versão do card.

## 7. Arquivos

Novos: `app/Domain/Editorial/{Approval,ApprovalConflict}.php`, `app/Models/ContentDecision.php`, `app/Http/Controllers/Api/V1/ContentDecisionController.php`, a migration, `tests/Feature/ApprovalFlowTest.php`, `tests/Support/Aprovar.php`, `apps/web/src/pages/ApprovalsPage{,.test}.tsx`, este documento.

Alterados: `Content`, `Publication`, `EditorialState`, `PublishGate`, `Dispatcher`, `Publisher`, `ContentController`, `ContentMediaController`, `SocialMediaAgent`, `AgentContext`, `routes/api.php`; web: `types.ts`, `ContentCard`, `ContentPage`, `main.tsx`. Testes existentes ajustados para aprovar pelo endpoint novo (o que cada um verifica não mudou; três "desagendar" mantidos no `PATCH`).

## 8. Testes

| Suíte | Resultado |
|---|---|
| PHPUnit | **560 passando** (543 antes + 17 novos) |
| Vitest | **178 passando** (171 antes + 7 novos) |
| Pint / oxlint / tsc + build | ok (2 avisos de lint anteriores) |

| Item pedido | Teste (`ApprovalFlowTest` salvo indicação) |
|---|---|
| 1 Aprovação válida | `test_aprovacao_valida_grava_versao_snapshot_e_hash` |
| 2 Não autorizado | `test_so_quem_revisa_decide…` (editor/viewer 403), `test_sem_token_nao_decide` (401) |
| 3 Rejeição | `test_rejeitar_exige_motivo_arquiva…` |
| 4 Solicitação de revisão | `test_pedir_ajustes_devolve_para_producao…` |
| 5 Alteração posterior | `test_mudanca_depois_da_aprovacao_derruba…` |
| 6 Regeneração de aprovada | `test_regeneracao_de_peca_aprovada_volta_para_revisao…` |
| 7 Versão desatualizada / 8 Concorrência | `test_aprovar_versao_velha_e_409_e_a_edicao_concorrente_vence` |
| 9 Agendar sem aprovação / 11 Requisição direta | `test_nao_ha_atalho_para_aprovar_nem_para_agendar…` |
| 10 Publicar sem aprovação | `test_publicacao_sem_aprovacao_valida_nao_chega_na_meta` |
| 12 Worker | `test_o_worker_confere_de_novo…`, `test_publicacao_sem_vinculo…` — **falsificados**: sem a porta no Publisher, ambos publicariam |
| 13 Duplicidade | `test_rodar_de_novo_nao_publica_duas_vezes` + testes existentes de job duplicado |
| 14 Isolamento | `test_so_quem_revisa_decide…` (outro tenant 404, inclusive histórico) |
| 15 Auditoria | `test_historico_registra_quem_o_que_versao_e_motivo_e_decisao_e_imutavel` |
| 16 Recuperação | `test_depois_de_derrubada_a_aprovacao_reaprovar_a_nova_versao_publica` |
| 17 Cancelamento | `test_cancelar_o_agendamento_cancela_a_publicacao_pendente` |
| 18 Recarregar | `test_decisao_persiste_e_volta_na_listagem…`, `ApprovalsPage.test.tsx` |

**Navegador (local):** Central aberta num iframe de 390 px (celular): uma coluna, sem rolagem horizontal, todas as ações visíveis; aprovar a versão 1 gravou a decisão com o usuário, `approval_valid` verdadeiro, estado `approved`, e a peça saiu da Central.

## 9. Riscos residuais

- **Peças aprovadas/agendadas antes do CP-04 precisam ser aprovadas de novo** (não têm snapshot). Fail-closed de propósito. Em produção hoje não há peça agendada.
- A aprovação é do **conteúdo**, não da URL da mídia: trocar o arquivo físico de um asset já referenciado (mesmo id) não muda o hash. A biblioteca não permite editar asset, só enviar novo — risco baixo.
- O Publisher confere antes do `media_publish`; se a peça mudar **durante** a chamada à Meta (segundos), o post sai com a versão aprovada que estava no container — que era a aprovada.
- `content_decisions` é imutável no código; no banco, um DBA ainda consegue alterar. Não há trigger.
- Uma peça rejeitada pode ser desarquivada (`unarchive`) e voltar ao fluxo — decisão existente mantida; a rejeição continua no histórico.
- A Central mostra só o que espera decisão; as já decididas ficam no quadro e no histórico.
- Relatório do CP-01 continua fora do repositório; CP-03 e CP-04 locais até o push.

## 10. Estado final do Git

Commit local na `main` (sem push, sem deploy). Hash no relatório entregue ao responsável.
