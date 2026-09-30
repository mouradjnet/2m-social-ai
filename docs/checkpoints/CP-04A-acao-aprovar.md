# CP-04A — Ação Aprovar

**Data:** 30/09/2026 · **Situação:** IMPLEMENTADO e testado (sem rede, sem publicação real, sem deploy). Proposta abaixo; resultado em "Implementação" no fim.

**Decisões do responsável (30/09/2026):** exigir `pending_approval`; `request_key` obrigatória; migration em `content_decisions` autorizada.

## Etapa 0 — Auditoria

| Item | Encontrado |
|---|---|
| Git | `main` limpa, igual a `origin/main` (`8b08fb9`, CP-04) |
| Checkpoints | CP-02, CP-03 e CP-04 documentados em `docs/checkpoints/`; CP-01 só na conversa. CP-02 não validado com chamada real |
| Modelos editoriais | `Content` (com `version`), `ContentDecision` (append-only, snapshot + sha256), `ContentRevision`, `ContentReview` (IA), `Publication` (`content_version`, `decision_id`) |
| Estados | status no banco: `idea, production, review, approved, scheduled, published, archived`; estado editorial derivado no servidor (`EditorialState`), incluindo `pending_approval` |
| Autenticação | Sanctum (token), rotas em `auth:sanctum` |
| Permissões | `WorkspaceRole` (viewer < editor < reviewer < admin < owner) + `ProjectPolicy` (`view`, `update`); tenant pelo `WorkspaceMemberScope` (peça de outro workspace = 404) |
| Agendamento / publicação | `ContentController::schedule`, agente `social_media`, `Dispatcher`, `Publisher` + `PublishJob` (fila `publishing`); porta `PublishGate` em todos eles |
| Auditoria | `content_decisions` (decisões humanas), `content_revisions` (movimentos e mudanças), `activity_logs` (gestos sem rastro próprio), `publication_attempts` |
| Convenções | Rotas `POST contents/{id}/<ação>`; mensagens em PT-BR; testes Feature com `RefreshDatabase`, `Http::fake` para a Meta; `RouteAuthorizationTest` exige teste de autorização em toda rota que muda estado |

## O que o CP-04 já entrega (reaproveitado, sem duplicar)

- `POST /contents/{id}/approve` com `version` (concorrência otimista), `lockForUpdate`, 409 em versão desatualizada.
- Snapshot canônico + sha256 do que vai ao ar (texto, CTA, hashtags, roteiro, formato, imagem, vídeo, slides, legenda final) em `content_decisions`, imutável no código.
- Qualquer mudança posterior sobe a versão e derruba a aprovação (model), cancelando publicação pendente.
- Transação única: decisão + status + `content_revisions`.
- `approved_by` vem do usuário autenticado; projeto/marca vêm da rota + escopo — nada do cliente é autoridade.
- Porta de publicação fail-closed (versão + hash) no agendamento, Dispatcher, agente e Publisher.

## Lacunas frente ao CP-04A

| # | Requisito | Hoje | Lacuna |
|---|---|---|---|
| L1 | Permissão **específica** de aprovação | Checagem de papel inline no controller (`atLeast(Reviewer)`) | Sem ability nomeada; a regra não é reutilizável nem visível para a tela |
| L2 | Estado **PENDING_APPROVAL** | Aprova qualquer peça em `review` (inclusive `in_review` e `needs_revision`) e `approved` com aprovação vencida | Não exige `pending_approval` |
| L3 | **Idempotência** com `requestKey` | Inexistente. Repetir a aprovação dá 409 (peça já aprovada), não o resultado original | Falta chave, fingerprint do payload e replay |
| L4 | Auditoria transacional **provada** | Tudo na mesma transação | Sem teste de falha da auditoria / rollback |
| L5 | Interface | Mostra versão, conteúdo, roteiro, 1ª imagem | Falta sequência completa de slides (imagens), **confirmação**, botão só para quem pode, mensagem de sucesso do servidor |
| L6 | Testes | 17 do CP-04 | Faltam: conteúdo inexistente, estado inválido, aprovação duplicada, reuso de requestKey, falha de auditoria, falha transacional |

## Plano

**1. Autorização (sem migration).** Ability `approve` no `ProjectPolicy` (deny-by-default: só `reviewer`, `admin`, `owner` do workspace da peça). O controller passa a usar `Gate::authorize('approve', $project)`. Ordem de validação: autenticado (401) → peça existe no escopo do usuário (404, cobre marca alheia) → permissão (403) → payload (422) → estado (409) → versão (409). A listagem de projetos/workspace já traz o papel; a tela usa uma função `podeAprovar(role)` espelhando a ability (a autoridade continua sendo o servidor).

**2. Estado.** Aprovar só com `editorial_state = pending_approval` (revisão da IA aprovou a versão atual, ou aprovação antiga vencida). **Decisão pendente** (ver abaixo).

**3. Versão.** Aceitar `expected_version` (nome pedido) e manter `version` como alias por compatibilidade; snapshot/hash já existentes.

**4. Transação.** Já atômica; acrescentar a gravação da chave de idempotência dentro da mesma transação e provar o rollback com testes de falha.

**5. Idempotência — migration necessária (proposta).**
Tabela nova `content_decision_requests` *ou* duas colunas em `content_decisions`. Proposta: colunas em `content_decisions` (menos estrutura):
- `request_key` (uuid, nulo para decisões antigas) e `request_fingerprint` (sha256 de `content_id + ação + expected_version + reason`), índice único `(user_id, request_key)`.
- Replay: mesma chave + mesmo fingerprint → devolve a decisão original (200) sem gravar nada. Mesma chave + payload diferente → **422** "chave reutilizada com outro conteúdo". Chave ausente → **422** (obrigatória na aprovação: deny-by-default).
- Concorrência: duas requisições simultâneas com a mesma chave → o índice único garante uma só; a perdedora relê e devolve o original.

**Impacto:** só acréscimo; colunas nulas; nenhuma linha convertida; decisões antigas continuam válidas. **Reversão:** `down()` remove o índice e as colunas (testado local antes).

**6. Interface (Central).** Faixa de miniaturas com **todos** os slides em ordem (carrossel) e vídeo (Reels); botão "Aprovar versão N" só para quem pode (os demais veem "Aguardando um revisor"); **diálogo de confirmação** ("Aprovar a versão N de ‹título›? Esta aprovação vale só para esta versão."); gera a `request_key` (UUID) ao abrir o diálogo e a reenvia em caso de nova tentativa; mostra a resposta do servidor (sucesso com quem/quando/versão, ou o erro); atualiza a lista **depois** da resposta (sem otimismo). O "Avançar" do quadro passa a pedir a mesma confirmação e enviar a chave.

**7. Testes (todos sem rede).** Aprovação válida; não autenticado (401); sem permissão (403 editor/viewer); outra marca (404); conteúdo inexistente (404); estado inválido (409 — `draft`, `in_review`, `needs_revision`, agendada); versão desatualizada (409); edição concorrente (409 + versão atual); aprovação duplicada com a mesma chave (mesmo resultado, 1 registro); reuso da chave com outro payload (422); falha na gravação da auditoria (exceção forçada em `content_revisions` → nada gravado, status intacto); falha transacional (exceção após a decisão → rollback completo); alteração posterior (aprovação cai); publicação sem aprovação válida (porta recusa, nada chega à Meta). Mais os testes de tela (confirmação, botão por papel, slides, resposta do servidor).

## Decisões pendentes

1. **Estado exigido (L2).** Exigir `pending_approval` significa: peça que a IA **reprovou** (`needs_revision`) ou que a IA **ainda não revisou** (`in_review`) não pode ser aprovada por uma pessoa sem antes passar pela revisão da IA (ou ser corrigida). Recomendação: **exigir** (é o pedido do CP-04A e o fluxo mais seguro); a pessoa que discorda da IA edita ou pede nova revisão.
2. **`request_key` obrigatória (L3).** Recomendação: **obrigatória** na aprovação.
3. **Onde guardar a chave.** Recomendação: colunas em `content_decisions` (sem tabela nova).

## Fora de escopo

Rejeitar e pedir ajustes (outras ações do CP-04) ficam como estão; deploy; banco de produção; chamadas pagas; publicação real.

## Implementação

**Migration** `2026_09_30_030000_add_idempotency_to_content_decisions`: `request_key` (uuid), `request_fingerprint` (sha256), único `(user_id, request_key)`. Só acréscimo; up/down/up testado no banco local.

**Backend**
- `ProjectPolicy::approve` — permissão nomeada, deny-by-default (revisor, admin, dono), com mensagem em PT. Também protege rejeitar e pedir ajustes.
- `POST /contents/{id}/approve` — ordem: 401 → 404 (escopo/marca/inexistente) → 403 → 422 (`expected_version` ou `version`, `request_key` UUID obrigatória) → 409 (estado ≠ `pending_approval`, ou versão). Resposta traz `decision` (versão, quem, hash) e `replayed`.
- `Approval::approve` — replay pela chave (mesmo fingerprint → decisão original, nada gravado; outro fingerprint → 422); corrida com a mesma chave resolvida pelo índice único; exige `pending_approval` dentro da transação com a linha travada; decisão + status + `content_revisions` na mesma transação.
- Autoridade só do servidor: `approved_by`, `user_id`, `project_id`, `brand_id` ou `role` enviados no corpo são ignorados (teste).

**Frontend**
- Central: botão "Aprovar versão N" só para revisor+ (espelho da policy; demais veem "Aguardando um revisor"), habilitado só em `pending_approval` (com o motivo quando não), **diálogo de confirmação**, `request_key` gerada ao abrir a confirmação (reusada em nova tentativa após falha de rede, descartada após 409/422), aviso com o resultado do servidor (incluindo replay), lista atualizada só depois da resposta, miniaturas de **todos** os slides na ordem.
- Quadro: "Decidir na Central →" em peça de revisão (não aprova direto).

**Testes**

| Suíte | Resultado |
|---|---|
| PHPUnit | **572 passando** (560 + 12 do `ApproveActionTest`) |
| Vitest | **183 passando** (178 + 5) |
| Pint / oxlint / tsc + build | ok (2 avisos de lint anteriores) |

| Pedido | Onde |
|---|---|
| Aprovação válida (e autoridade do cliente ignorada) | `ApproveActionTest::test_aprovacao_valida_ignora_autoridade_vinda_do_cliente` |
| Não autenticado | `test_sem_sessao_e_401` |
| Sem permissão | `test_sem_permissao_de_aprovar_e_403_em_portugues` |
| Outra marca / conteúdo inexistente | `test_outra_marca_e_conteudo_inexistente_sao_404` |
| Estado inválido | `test_so_pending_approval_pode_ser_aprovada` (rascunho, sem IA, reprovada, já aprovada, agendada) |
| Versão desatualizada | `test_versao_desatualizada_e_409_com_a_versao_atual` |
| Edição concorrente | `ApprovalFlowTest::test_aprovar_versao_velha_e_409_e_a_edicao_concorrente_vence` |
| Aprovação duplicada | `test_mesma_chave_e_mesmo_pedido_devolvem_o_resultado_original` |
| Reuso indevido da chave | `test_mesma_chave_com_outro_pedido_e_recusada`, `test_o_banco_nao_aceita_duas_decisoes…` |
| Falha na auditoria | `test_falha_na_auditoria_desfaz_tudo` |
| Falha transacional | `test_falha_depois_de_gravar_a_decisao_desfaz_tudo` |
| Alteração posterior | `ApprovalFlowTest::test_mudanca_depois_da_aprovacao…` |
| Publicação sem aprovação válida | `ApprovalFlowTest::test_publicacao_sem_aprovacao_valida…`, `test_o_worker_confere_de_novo…` |
| Payload inválido | `test_payload_invalido_e_422` |
| Tela | `ApprovalsPage.test.tsx` (confirmação, cancelar, chave UUID, replay, papel, estado, slides, 409), `ContentPage.test.tsx` |

**Navegador (local, mock):** revisor de IA rodou em duas peças; a reprovada aparece com "Aprovar" desabilitado, a aprovada habilitada; confirmação mostrada; o POST levou `expected_version` + `request_key` UUID; aviso "aprovada por …" veio do servidor; o **reenvio da mesma requisição devolveu a mesma decisão com `replayed: true`**.

**Ajustes em testes existentes** (o que cada um verifica não mudou): aprovações passam por `Tests\Support\Aprovar::pedido()` (revisão de IA `pass` + chave); o teste de regeneração do CP-04 agora aprova antes e recebe a reprovação tardia depois (a regra nova não permite aprovar peça reprovada); `ScheduleGenerationTest` conta só execuções do agendamento.

**Riscos residuais**
- Uma pessoa não consegue mais aprovar peça que a IA reprovou ou não revisou (decisão do responsável). Com a IA em mock, o revisor mock reprova a primeira peça do lote.
- `crypto.randomUUID()` exige contexto seguro (HTTPS ou localhost) — VPS e Render são HTTPS.
- O replay devolve a decisão original mesmo que a peça tenha mudado depois; a resposta traz o estado atual da peça.
- Rejeitar e pedir ajustes não têm idempotência (fora do escopo do CP-04A).
