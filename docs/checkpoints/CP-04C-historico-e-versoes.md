# CP-04C — Histórico Editorial e Controle de Versões

**Data:** 30/09/2026 · **Situação:** implementado e testado (sem rede, sem publicação real, sem deploy, sem chamada paga de IA). Migration aplicada só no banco local, com autorização.

**Decisões do responsável (30/09/2026):** migration `create_content_versions` autorizada (tabela nova + backfill + trigger anti-UPDATE); restaurar = editor ou acima; `expected_version` **obrigatória** nas edições humanas.

## 1. Diagnóstico inicial

- `main` limpa, igual ao último commit (`958a7cc`, CP-04B); relatórios CP-04A e CP-04B em `docs/checkpoints/`; nenhuma migration pendente.
- Existia: `contents.version` (sobe no model a cada mudança do que vai ao ar), `content_decisions` (decisões humanas com snapshot + sha256, imutáveis), `content_revisions` (movimentos e de/para do texto), `GET /contents/{id}/history`.
- **Lacuna principal:** a versão era só um número. O conteúdo de cada versão não era guardado (só o aprovado tinha snapshot) — não havia como ver, comparar nem restaurar versões antigas.
- Outras lacunas: edição de texto/slides/vídeo/imagem sem `expected_version`; a queda da aprovação não virava evento; sem restauração.

## 2. Validação do CP-04A e CP-04B

`ApprovalFlowTest` + `ApproveActionTest` + `RejectAndChangesTest`: **44/44 verdes** antes de qualquer mudança. Aprovar, Rejeitar e Solicitar ajustes funcionando (e seguem verdes no fim).

## 3. Arquitetura

- **`content_versions`** (migration `2026_09_30_040000_create_content_versions`): uma linha por versão — peça, marca (projeto), workspace, `version`, `snapshot` (o mesmo de `Approval::snapshot`), `snapshot_hash` (o mesmo sha256 da aprovação), `media` (metadados: nome original, mime, tamanho, dimensões, duração, checksum), `origin`, `restored_from_version`, `invalidated_approval`, `user_id`, `ai_run_id`, `created_at`. **Único `(content_id, version)`**. **Trigger** recusa `UPDATE`.
- **Gravação num ponto só:** hooks do model `Content` (`created`, `updated` quando a versão sobe, `markContentChanged` dos slides) chamam `Versioning::record`. Cobre endpoint, agente e job sem duplicar lógica.
- **Origem:** contexto aberto por `Versioning::como()` — `ai_generation` / `ai_rewrite` (RunAgentJob, com `ai_run_id`), `ai_image` (GenerateImageJob), `seo`, `restore`, `backfill`; sem contexto, `created`/`manual_edit` (usuário da sessão) ou `system` (fora de requisição).
- **Linha do tempo** (`EditorialTimeline`): montada dos três registros append-only, sem tabela nova de eventos. A decisão também grava um movimento em `content_revisions`; ele é pareado e não aparece duas vezes.
- **Imutabilidade:** `ContentVersion` recusa update/delete no código; o banco recusa UPDATE. Nenhuma rota apaga peça.

## 4. Modelos e endpoints

| Endpoint | Permissão | O quê |
|---|---|---|
| `GET /contents/{id}/versions` | `view` | versões, versão atual, versão aprovada (`is_approved` por hash) |
| `GET /contents/{id}/versions/{n}` | `view` | snapshot + mídias de uma versão |
| `GET /contents/{id}/versions/compare?from=&to=` | `view` | diferença campo a campo |
| `POST /contents/{id}/versions/{n}/restore` `{expected_version}` | `update` (editor+) | restaura como versão nova |
| `GET /contents/{id}/history` | `view` | agora com `events` (linha do tempo) e `approved_version` |
| `PATCH /contents/{id}/draft`, `PUT …/image`, `…/slides`, `…/video` | `update` | **exigem `expected_version`** (422 sem; 409 + `current_version` se velha) |

Marca vem da rota + escopo de tenant (marca alheia = 404); autoria vem da sessão (`user_id`, `project_id`, `origin` no corpo são ignorados — teste).

Novos: `Domain/Editorial/{Versioning,EditorialTimeline,VersionConflict,RestoreRefused}.php`, `Models/ContentVersion.php`, `Controllers/Api/V1/ContentVersionController.php`, a migration, `tests/Feature/ContentVersionsTest.php`, `web/src/components/content/VersionHistory.tsx`.
Alterados: `Content`, `ContentRevision` (hora do PHP no `created_at`), `ContentController`, `ContentImageController`, `ContentMediaController`, `SeoController`, `ContentDecisionController`, `RunAgentJob`, `GenerateImageJob`, `routes/api.php`; web: `PublicationEditor`, `ApprovalsPage`, `types.ts`, `roles.ts`. Testes existentes ganharam `expected_version` nas chamadas de edição (o que cada um verifica não mudou) e `RouteAuthorizationTest` registra a rota de restauração.

## 5. Estratégia de versionamento

- Toda mudança no que vai ao ar sobe a `version` (já existia) **e agora grava a versão** com snapshot e mídias, na mesma transação da mudança.
- O hash da versão é o mesmo que a aprovação calcula: a aprovação reconhece exatamente a versão aprovada (`is_approved`).
- Numeração duplicada é impossível: o índice único recusa, e a transação desfaz a mudança da peça junto (teste com cópia velha em memória).
- Edições humanas: `Versioning::exigir` trava a linha (`lockForUpdate`) e confere a versão dentro da transação — a segunda edição concorrente recebe 409 e não sobrescreve.
- **Backfill:** as 27 peças do banco local ganharam a versão atual (`origin = backfill`). Versões anteriores de peças já existentes **não são reconstruíveis** (nunca foram gravadas).

## 6. Comparação

Campos: título, legenda, CTA, hashtags, roteiro/estrutura (slides do roteiro incluídos), formato, canal, legenda final composta, imagem, vídeo e slides. Mídia se compara pelo **registro e pelo checksum**, nunca pelo nome: mesmo nome com checksum diferente = "Arquivos diferentes"; checksum igual em registros diferentes = "conteúdo idêntico"; sem checksum = "não dá para afirmar". Slides reordenados: "Mesmas imagens, em outra ordem". Metadados guardados na versão sobrevivem à remoção do arquivo da biblioteca.

A tela (Central → Histórico) mostra só os campos que mudaram, antes/depois lado a lado (empilhado no celular); padrão: versão anterior × atual.

## 7. Restauração

- Cria **versão nova** (`origin = restore`, `restored_from_version`), nunca edita a antiga, nunca herda aprovação (número e hash novos).
- Se a peça estava aprovada, a aprovação cai pelo caminho normal do model (`approved → review`, evento `approval_invalidated`, publicação pendente cancelada). Nos demais status a peça fica onde está e segue o fluxo (a IA precisa revisar a versão nova antes de uma pessoa aprovar).
- Recusas: versão esperada velha (409), agendada (409, desagende antes), publicada/arquivada (409), versão inexistente, igual à atual ou com mídia removida da biblioteca (422).
- Tudo numa transação: falha na auditoria desfaz a restauração inteira (teste).

## 8. Testes

| Suíte | Resultado |
|---|---|
| PHPUnit | **609 passando** (587 + 22 do `ContentVersionsTest`) |
| Vitest | **188 passando** (185 − 1 substituído + 4) |
| Pint / oxlint / tsc + build | ok (2 avisos de lint anteriores) |

| Pedido | `ContentVersionsTest` |
|---|---|
| 1 Criação de versão | `test_criar_a_peca_grava_a_versao_1_com_snapshot_e_hash_da_aprovacao` |
| 2 Edição | `test_edicao_manual_cria_versao_nova_e_a_anterior_fica_igual` |
| 3 Regeneração pela IA | `test_regeneracao_pela_ia_grava_a_versao_com_origem_e_execucao` |
| 4 Histórico | `test_historico_traz_os_eventos_com_responsavel_versao_e_transicao` |
| 5 Comparação | `test_comparacao_mostra_campos_mudados_e_nao_iguala_midia_pelo_nome`, `test_comparacao_de_slides_reordenados…` |
| 6 Aprovação vinculada | `test_aprovacao_fica_presa_a_versao_exata` |
| 7 Invalidação | `test_alteracao_depois_da_aprovacao_invalida_e_fica_registrada` |
| 8 / 9 Restauração e preservação | `test_restaurar_cria_versao_nova_sem_herdar_aprovacao_e_preserva_as_antigas`, `test_versao_historica_nao_se_edita_nem_se_apaga` (inclusive SQL direto), `test_restauracao_recusa_…`, `test_peca_agendada_nao_restaura` |
| 10 Concorrência | `test_edicao_concorrente_a_segunda_recebe_409_e_nao_sobrescreve` (**falsificado**: sem a checagem, a segunda passa com 200) |
| 11 Versão desatualizada | `test_versao_desatualizada_e_409_em_toda_edicao_restauracao_e_aprovacao`, `test_edicao_sem_expected_version_e_422` |
| 12 Numeração duplicada | `test_numero_de_versao_nao_se_repete` |
| 13 Outra marca | `test_outra_marca_nao_ve_nem_compara_nem_restaura` |
| 14 Sem autorização | `test_sem_sessao_401_e_leitor_nao_restaura`, `test_autoria_vem_da_sessao_e_nao_do_corpo` |
| 15 Falha transacional | `test_falha_ao_gravar_a_versao_desfaz_a_edicao`, `test_falha_no_meio_da_restauracao_desfaz_tudo` |
| 16 Sem publicação externa | `test_editar_comparar_e_restaurar_nao_publicam_nada` |

Tela (`ApprovalsPage.test.tsx`): linha do tempo com decisões humanas marcadas; comparação (padrão anterior × atual, só campos mudados, nota da mídia); restaurar pede confirmação e envia `expected_version`; leitor não vê "Restaurar". `PublicationEditor.test.tsx`: salvar envia `expected_version` e encadeia a versão devolvida entre texto e imagem.

**Navegador (local, usuário de teste, peça 72 do projeto piloto local):** edição pela API 200 (v1→v2), repetida com a versão velha 409, sem versão 422; histórico com linha do tempo, versões e comparação do CTA; restauração v1 → v3 pela tela com confirmação e mensagem do servidor; em 390 px sem rolagem horizontal (a descrição da versão ia para uma linha própria depois de um ajuste). Token de teste revogado ao final.

**Migration:** up → down → up no banco local; trigger conferido (UPDATE recusado).

## 9. Riscos residuais

- **Peças anteriores ao CP-04C** têm só a versão atual no histórico (backfill); o conteúdo das versões antigas delas se perdeu antes deste checkpoint.
- A linha do tempo ordena pela hora; decisões e movimentos têm precisão de segundo (colunas existentes). Dois eventos no mesmo segundo podem aparecer na ordem das tabelas. Não afeta versões nem a integridade da aprovação.
- `seo:apply` e `rewrite:generate` não pedem `expected_version` (a reescrita da IA relê a peça no job). Uma edição humana simultânea a uma reescrita ainda pode ser sobrescrita pela IA (risco anterior, agora visível no histórico, com as duas versões guardadas).
- O trigger bloqueia UPDATE; DELETE continua possível pelo cascade da peça (nenhuma rota apaga peça) ou por um DBA.
- Clientes antigos do editor (aba aberta antes do deploy) recebem 422 ao salvar até recarregar: web e API precisam subir juntos.
- Em produção, a migration faz o backfill das peças do Neon/VPS no deploy (não executado).

## 10. Git

Commit local na `main`, sem push e sem deploy. Hash no relatório entregue ao responsável. CP-04D não iniciado.
