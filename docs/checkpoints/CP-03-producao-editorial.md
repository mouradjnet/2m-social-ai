# CP-03 — Produção Editorial Inteligente

**Data:** 30/09/2026 · **Situação:** implementado e testado **no mock** (sem chamada paga). Sem deploy, sem mudança no banco de produção.

## 1. Diagnóstico inicial

A operação editorial já existia quase inteira: estrategista, planejador semanal, calendário mensal, redator, reescritor, revisor, SEO, designer, social media e reaproveitador, com edição de texto, pré-visualização do Instagram e aprovação humana antes de agendar. Faltava:

- **Estratégia** sem objetivos, temas, formatos, frequência nem distribuição educativo/institucional/comercial (a tabela não tinha onde guardar).
- **Formatos sem estrutura:** carrossel, Reels e Stories saíam como título + legenda + CTA, iguais a um post. Nada de slides, telas ou roteiro.
- **Planejamento** sem objetivo e CTA por horário, sem usar a frequência da estratégia e sem checar conflito com peças já marcadas.
- **Revisor** tratava expressões proibidas só pelo prompt (a IA podia deixar passar).
- **Estados** da revisão (precisa de ajuste / pronta para aprovação) não eram explícitos.
- **Piloto** configurado para uma identidade antiga (educação em saúde), não a do CP-03 (loja online sem catálogo confirmado).

## 2. Estado dos checkpoints anteriores

| Item | Resultado |
|---|---|
| Branch / árvore | `main`, limpa, igual a `origin/main` |
| CP-01 | Commits `083444f`, `675b47f`, `3a035d0`; relatório **só na conversa** (não há `CP-01` em `docs/checkpoints/`) |
| CP-02 | Commit `2e7668e`; relatório em `docs/checkpoints/CP-02-…`. **Implementado, não validado com chamada real** |
| Testes antes de começar | 515 PHPUnit + 163 vitest, verdes |
| Perfil da Marca | Persistência coberta pelos testes do CP-01 |
| Motor de IA / agentes | 11 agentes no mesmo motor (`RunAgentJob` → `LlmProvider`) |
| Migrations pendentes | Nenhuma |

Nada bloqueante. Tudo do CP-03 foi provado com o `MockProvider`.

## 3. Funcionalidades existentes reutilizadas

Os 11 agentes (nenhum criado), o calendário mensal (não foi criado outro), o quadro de conteúdo, o `PublicationEditor` (edição de texto, imagem, prévia, agendamento), o `InstagramPreview`, a biblioteca de mídia, os estados `idea → production → review → approved → scheduled → published / archived`, a aprovação humana (ADR-13) e o comando `pilot:2m-saude-feminina`.

## 4. Implementações realizadas

**Migration (autorizada):** `2026_09_30_010000_add_editorial_structure` — duas colunas jsonb **opcionais**, só acréscimo: `strategies.guidelines` e `contents.structure`. Nada apagado nem convertido; dados antigos ficam com `null`. `down()` testado localmente.

**Estratégia (estrategista):** a resposta passa a trazer `guidelines` — objetivos, temas, formatos (post/carousel/reel/story), frequência semanal (1–14) e distribuição educativo/institucional/comercial somando 100. Regras conferidas no código: **comercial > 0 só com produto ou serviço real no perfil** ("A CONFIRMAR", "Nenhum" ou vazio não contam), e nenhuma expressão proibida no texto da estratégia. A tela mostra tudo isso antes do botão "Aprovar estratégia".

**Planejamento (planejador):** cada horário traz `objective` e `cta`; CTA de venda só com oferta real. A frequência da estratégia aprovada vira o número de peças padrão (na API e pré-preenchida na tela, editável). Horários já ocupados por peças agendadas ou planejadas (no fuso do projeto) são recusados; horários repetidos já eram.

**Formatos (redator, reescritor, reaproveitador):** `structure` com o ROTEIRO do formato, validado no código (`Domain\Editorial\FormatStructure`):

| Formato | Roteiro | Regra |
|---|---|---|
| Feed (post) | proposta visual | visual obrigatório |
| Carrossel | capa, slides, encerramento | 3 a 10 slides, nenhum vazio |
| Stories | telas com texto curto, visual, interação | 2 a 10 telas, texto até 120 caracteres |
| Reels | gancho, cenas (descrição, texto na tela, narração), produção | gancho, ≥ 2 cenas, orientações de produção |

A tela mostra o roteiro com o aviso **"Roteiro para produção — não é a arte nem o vídeo final"**. Mídia real continua na biblioteca; nenhuma peça nasce com imagem ou vídeo.

**Revisão:** o prompt do revisor passa a cobrir promessa sem comprovação, informação/depoimento inventado, oferta/preço/promoção inexistente, saúde (diagnóstico, prescrição, serviço médico), linguagem inadequada e falta de informação essencial, julgando também o roteiro. **Checagem determinística** (`Domain\Editorial\BrandRules`): expressão proibida encontrada no texto inteiro da peça (roteiro incluso, sem diferença de maiúsculas/acentos, palavra inteira) **reprova a peça mesmo que a IA aprove**, com a violação registrada. Redator, reescritor, reaproveitador e estrategista recusam a própria saída com expressão proibida (1 retentativa).

**Estados editoriais (sem coluna nova):** `editorial_state` em toda peça da API — `draft` (idea/production), `in_review`, `needs_revision`, `ready_for_approval` (derivados do último veredito; se o texto mudou depois do veredito, volta a `in_review`), e `approved/scheduled/published/archived`. `ready_for_approval` **não aprova**: aprovar e publicar continuam gesto humano.

**Piloto 2M Saúde Feminina:** o comando `pilot:2m-saude-feminina` passa a gravar a identidade do CP-03 — loja online de produtos femininos + conteúdo de autocuidado, beleza e bem-estar; público de mulheres de todas as idades no Brasil; tom feminino, acolhedor, inspirador e responsável; **produtos "A CONFIRMAR", sem serviço**; regras de saúde (sem diagnóstico, prescrição nem serviço médico) e comerciais (sem produto, preço, promoção ou depoimento inventados). Estratégia com comercial 0%, 70% educativo / 30% institucional, 4 formatos, 3 posts/semana. Continua conservador: só preenche campo vazio e não aprova, agenda nem publica. **Rodado apenas no banco local.**

## 5. Arquivos modificados

Novos: `app/Domain/Editorial/{BrandRules,FormatStructure,EditorialState}.php`, a migration, `tests/Feature/{EditorialFormatsTest,EditorialStateTest}.php`, `tests/Unit/EditorialRulesTest.php`, `tests/Support/Roteiro.php`, `apps/web/src/components/content/{StructurePreview.tsx,EditorialCp03.test.tsx}`, este documento.

Alterados (API): os agentes `Strategist`, `Planner`, `Copywriter`, `Rewriter`, `Repurposer`, `Reviewer`, `AgentContext`, `MockProvider`, `WeekPlanController`, os models `Content` e `Strategy`, o comando do piloto. Testes existentes atualizados só onde montavam saída de agente sem os campos novos (o que cada um verifica não mudou).

Alterados (web): `lib/types.ts`, `ContentCard`, `StrategyCard`, `WeekPlanPanel`, `ContentPage`.

## 6. Testes executados

| Suíte | Resultado |
|---|---|
| PHPUnit | **543 passando** (515 antes + 28 novos) |
| Vitest | **171 passando** (163 antes + 8 novos) |

| Pedido da Etapa 9 | Onde |
|---|---|
| Estratégia com perfil completo / incompleto | `StrategistAgentTest` (comercial com oferta real aceito; sem oferta rejeitado), `PilotCommandTest` |
| Planejamento semanal / frequência | `WeekPlanTest::test_sem_numero_de_posts_usa_a_frequencia…`, `EditorialCp03.test.tsx` |
| Edição do calendário | Testes existentes de remarcação (`ContentController`, `CalendarPage`) + plano editável no nº de peças |
| Feed, Stories, Reels, Carrosséis | `EditorialRulesTest`, `EditorialFormatsTest` (os 4 de ponta a ponta), `EditorialCp03.test.tsx` |
| Revisão editorial / expressões proibidas | `ReviewGenerationTest::test_expressao_proibida_reprova_mesmo_quando_a_ia_aprova` (proibida escondida num slide), `EditorialRulesTest` |
| Regeneração | `RewriteGenerationTest`, `RepurposeTest` (agora com roteiro) |
| Persistência / recarga | `EditorialFormatsTest` (listagem que a tela lê), `EditorialStateTest` |
| Isolamento entre marcas | `EditorialFormatsTest::test_pecas_de_uma_marca…`, `AiIntegrationTest` (CP-02) |
| Falhas da IA | `EditorialFormatsTest::test_falha_da_ia_nao_grava_peca_pela_metade`, `AiIntegrationTest` |
| Bloqueio de publicação externa | `EditorialFormatsTest` (0 publicações, nada agendado), `ApprovalTest` |

**Navegador (local, mock):** projeto do piloto criado pelo comando no banco local → estratégia com objetivos, temas, formatos, frequência e "Comercial 0%" → campo de peças pré-preenchido com a frequência (3) → plano com objetivo e CTA por horário → 3 peças escritas (post, carrossel, post), todas com roteiro → após recarregar, o roteiro do carrossel aparece com capa, slides, encerramento e o aviso de que não é arte final. **Achado e corrigido:** a tela fixava "3 peças" e ignorava a frequência da estratégia.

## 7. Builds

`pint --test` ok · `oxlint` ok (2 avisos anteriores ao CP) · `tsc -b` + `vite build` ok.

## 8. Riscos residuais

- **Nada validado com a IA real** (depende da validação pendente do CP-02). O modelo pode precisar de ajuste de prompt para produzir roteiros bons; as regras no código garantem só a forma.
- **O roteiro não é editável na tela** — texto, CTA e hashtags são; o roteiro muda por reescrita ou nova geração. Edição campo a campo do roteiro fica para depois.
- **O plano não é editável horário a horário** antes de escrever; a pessoa ajusta o número de peças e depois edita/remarca as peças geradas (que nascem como ideia, sem agenda).
- **Checagem de expressão proibida é literal:** pega a expressão cadastrada, não sinônimos nem paráfrases (isso fica com o revisor de IA). Também acusa a expressão em negação ("sem antes e depois") — o próprio piloto foi corrigido por isso.
- **Fontes de saúde:** o prompt exige base reconhecida, mas nada verifica a citação automaticamente.
- **"Oferta real"** é detectada por marcadores ("A CONFIRMAR", "Nenhum", vazio); um texto como "Em breve" contaria como produto.
- **Relatório do CP-01** continua sem arquivo no repositório.
- **Dados locais de teste:** o banco local ganhou o projeto #8 (piloto) e 3 peças de teste; produção não foi tocada.

## 9. Dependente de integração externa

- Geração real de texto e roteiros: IA Anthropic (CP-02, aguardando validação autorizada).
- Imagem das peças: `IMAGE_PROVIDER=openai` (hoje `fake`).
- **Vídeo finalizado de Reels: não há gerador** — o sistema entrega o roteiro; o vídeo é gravado fora e sobe na biblioteca.
- Publicação e métricas: Meta / Instagram (app da Meta pendente); nenhuma geração publica.

## 10. Estado final do Git

Commit local na `main` (sem push, sem deploy). Hash no relatório entregue ao responsável.
