# 2M Social AI — Arquitetura

> Documento de arquitetura. Nenhum código foi escrito ainda.
> Decisões travadas com o produto em 2026-07-09.

## 1. Princípio organizador

O spec diz duas coisas que, lidas juntas, definem a arquitetura inteira:

- *"Nunca implemente funcionalidades isoladas. Toda decisão deve considerar a arquitetura completa."*
- *"Sempre que houver duas soluções possíveis, escolher a de menor complexidade e maior facilidade de manutenção."*

A leitura que adotamos: **arquitetura pensada inteira, entrega em fatias verticais finas.** O modelo de dados e os contratos de API contemplam os 15 módulos desde já (para não pagar migração cara depois); a infraestrutura entra só quando a fatia que a usa existir.

Consequência prática: `workspace_id` está em todas as tabelas desde a primeira migration, mas Redis, Reverb, S3, Stripe e Mercado Pago **não** entram no MVP.

## 2. Decisões de arquitetura (ADRs)

### ADR-01 — Tenancy: Workspace com papéis
Usuário pertence a um ou mais **Workspaces**. Projetos pertencem ao Workspace, não ao usuário. Isso atende agências (vários clientes num workspace) e freelancers (um workspace só) com o mesmo modelo, e é o que dá sentido aos campos `Responsável` e ao workflow de Revisão/Aprovação do spec.

Papéis, derivados diretamente do workflow de conteúdo:

| Papel | Pode |
|---|---|
| `owner` | tudo, incluindo billing e excluir o workspace |
| `admin` | gerenciar membros e projetos |
| `editor` | criar/editar conteúdo, mover até Revisão |
| `reviewer` | aprovar ou rejeitar (Revisão → Aprovado) |
| `viewer` | somente leitura |

**Custo aceito:** policy em toda query desde o dia 1.

### ADR-02 — Escopo de tenancy explícito na rota, não implícito na sessão
Rotas são `/api/v1/workspaces/{workspace}/projects/...`. O workspace vem da URL, passa por route model binding e por uma Policy. Não existe "workspace atual" guardado em sessão.

**Por quê:** o padrão de "workspace atual implícito" é a origem clássica de IDOR — basta esquecer um `where workspace_id = ?` em uma query. Com o workspace na rota, o middleware autoriza uma vez e o Global Scope do Eloquent filtra em profundidade. Duas camadas independentes.

### ADR-03 — Publicação = agendar + exportar. Sem API das redes no MVP
O conteúdo aprovado é agendado no calendário e exportado (texto + hashtags + imagem). O usuário publica manualmente.

**Por quê:** publicar via API no Instagram, Facebook, LinkedIn, TikTok e YouTube exige app review, verificação de negócio e OAuth por rede — meses de trabalho fora do nosso controle, que travariam todo o resto do roadmap. E é coerente com *"a IA auxilia, o usuário decide. Nunca publique automaticamente."*

**Consequência que precisa ser dita em voz alta:** sem dados de desempenho vindos das redes, o módulo Analytics **não pode** calcular "melhores horários" nem "melhores formatos". Ver ADR-08.

### ADR-04 — O calendário não é uma tabela
O spec é explícito: *"O calendário é uma visualização. Nunca será o centro do sistema."* A arquitetura reflete isso literalmente — não existe tabela `calendars`. O calendário é um endpoint de leitura (`GET .../calendar?from&to&channel&status&campaign`) que consulta `contents` por `scheduled_for`.

### ADR-05 — Transições de status por endpoint dedicado, não por PATCH
`POST /contents/{content}/transition {to: "review"}` em vez de `PATCH /contents/{content} {status: "review"}`.

**Por quê:** o workflow (Ideia → Produção → Revisão → Aprovado → Agendado → Publicado → Arquivado) é uma máquina de estados com regras de papel. Um `PATCH` genérico permite qualquer salto e espalha a validação. Um endpoint dedicado concentra a máquina de estados no servidor e registra a transição em `content_revisions` de graça.

### ADR-06 — Um provider de IA real no MVP, atrás de uma interface
O spec lista OpenAI, Gemini, Claude e OpenRouter. Implementamos a interface `LlmProvider` e **um** adapter real — Anthropic Claude (`claude-opus-4-8`) — mais um `MockProvider` para testes e desenvolvimento sem chave.

**Por quê:** quatro provedores no MVP é três vezes o trabalho de manutenção sem um único usuário para justificar. A interface garante que adicionar Gemini depois seja um arquivo novo, não uma refatoração. Detalhes em [AI-LAYER.md](AI-LAYER.md).

### ADR-07 — IA roda em fila, cliente faz polling. Sem WebSocket no MVP
Uma geração de estratégia leva dezenas de segundos. Ela vira um `Job` na fila (driver `database`), grava um registro em `ai_runs`, e o frontend faz polling em `GET /ai-runs/{run}`.

**Por quê:** Reverb + Redis para o MVP é infra que existe só para economizar um `setInterval` de 2 segundos. Quando houver colaboração simultânea real, trocamos o polling por WebSocket sem mudar o modelo de dados — `ai_runs` já é a fonte da verdade.

### ADR-08 — Analytics do MVP mede produtividade, não desempenho
Sem ADR-03 resolvido, não há dados de alcance/engajamento. O que **é** computável e útil:

- posts produzidos, aprovados, publicados por período
- tempo médio entre Produção e Aprovado (gargalo de revisão)
- aderência ao calendário (agendados que viraram publicados no prazo)
- cobertura de campanha e de objetivos
- **Pontuação do calendário** — heurística interna: diversidade de formatos, distribuição temporal, cobertura dos objetivos do projeto, respeito à linha editorial. Não precisa de dado externo.

Métricas de desempenho real ficam explicitamente fora até haver integração com as redes.

### ADR-09 — Objetivos pertencem ao Projeto, não ao Brand Profile
O spec lista "Objetivos" duas vezes: em Projetos e em Perfil da Marca. Adotamos uma fonte única — a tabela `objectives`, ligada ao `project_id`. O Brand Profile os referencia; não os duplica.

### ADR-10 — Sem Redis, S3 e pagamentos no MVP
- Cache e fila usam o driver `database`.
- Uploads vão para o disco local (`storage/app/public`), atrás do contrato `Storage` do Laravel — trocar para S3 depois é mudar uma variável de ambiente.
- Stripe e Mercado Pago entram quando houver o que cobrar.

### ADR-11 — O Dashboard não exibe métrica que não sabemos calcular
As telas do Stitch trazem um tile "ENGAJAMENTO +12.4%" e um item de atividade "Sistema publicou Tweet Matinal". Os dois descrevem um produto diferente do que ADR-03 define.

**Decidido:**
- O tile de engajamento é substituído pela **Pontuação do Calendário** (score 0–100 + decomposição), computável sem dado externo.
- **Nenhum item de `activity_logs` tem ator "sistema".** `activity_logs.user_id` é `NOT NULL`. Publicar é sempre clique humano.

**Por quê:** um Dashboard que exibe um número que ninguém consegue explicar é pior que um Dashboard sem o número. E uma linha de log dizendo que o sistema publicou algo ensina ao usuário um comportamento que o produto não tem — e que o spec proíbe.

### ADR-12 — Laravel 13, não 12
O spec pede "Laravel 12 (API REST)". O instalador entrega **13.19** — o spec dizia 12 porque era o corrente quando foi escrito.

**Decidido:** Laravel 13. Nada no nosso desenho depende da major 12 (Sanctum, filas, policies e migrations funcionam igual), e começar uma major atrás só adiaria a migração para antes do primeiro usuário pagante.

**Detalhe que custou um ciclo:** o pluralizador do Laravel trata `research` como invariável, então `foreignId('research_id')->constrained()` procura a tabela `research`, não `researches`. Precisa ser `constrained('researches')`.

## 3. Camadas

```
┌──────────────────────────────────────────────────────┐
│ Frontend — React 19 + TS + Vite + Tailwind           │
│ TanStack Query (server state) · React Router         │
│ Sem store global no MVP                              │
└───────────────────────┬──────────────────────────────┘
                        │ REST /api/v1 · Sanctum
┌───────────────────────┴──────────────────────────────┐
│ Laravel 13                                            │
│                                                       │
│  Http/         Controllers finos, Form Requests,      │
│                API Resources, Policies                │
│  Domain/       Ações e máquinas de estado             │
│                (ContentWorkflow, CalendarScore)       │
│  Ai/           Agents/ · Providers/ · Schemas/        │
│                Runs/  (ver AI-LAYER.md)               │
│  Models/       Eloquent + Global Scope de workspace   │
│  Jobs/         RunAgentJob                            │
└───────────────────────┬──────────────────────────────┘
                        │
              PostgreSQL 16  ·  fila e cache em tabela
```

**Regra de tamanho:** Controller não decide nada. Ele valida (Form Request), autoriza (Policy) e delega para uma classe de ação em `Domain/`. Toda lógica de negócio testável fica em `Domain/` e `Ai/`, sem tocar em HTTP.

## 4. Módulos e independência

Os 15 módulos do spec mapeiam assim:

| Módulo | Como existe no código |
|---|---|
| Dashboard | agregação de leitura sobre outros módulos; sem tabela própria |
| Workspace | `workspaces`, `workspace_members`, `workspace_invitations` |
| Projetos | `projects`, `objectives` |
| Perfil da Marca | `brand_profiles` (1:1 com projeto) |
| Pesquisa Inteligente | `researches`, `research_insights` |
| Estratégia | `strategies`, `content_plans` |
| Calendário | **view** sobre `contents` (ADR-04) |
| Biblioteca | `assets`, `snippets`, `templates` |
| Conteúdo | `contents`, `content_revisions`, `content_comments` |
| Campanhas | `campaigns` |
| Analytics | agregações de leitura (ADR-08) |
| Templates | `templates` |
| Configurações | `workspaces` + `workspace_members` |
| IA | `ai_runs` + `Ai/` (ver AI-LAYER.md) |
| Notificações | tabela `notifications` do Laravel |
| Ajuda | conteúdo estático; sem backend |

"Cada módulo deve ser independente" se traduz em: **um módulo só conhece outro através do banco e de ações de domínio, nunca chamando o Controller do outro.**

## 5. Segurança

Todo item da seção Segurança do spec, com onde ele vive:

| Requisito | Onde |
|---|---|
| Autenticação | Laravel Sanctum (token) |
| Autorização | Policies por model + role no `workspace_members` |
| Isolamento de tenant | workspace na rota (ADR-02) + Global Scope no Eloquent |
| Rate Limit | `throttle` por rota; limite extra e orçamento por workspace nas rotas de IA |
| Validação | Form Requests; nenhuma escrita aceita input não validado |
| Sanitização / XSS | React escapa por padrão; nada de `dangerouslySetInnerHTML` em conteúdo gerado por IA |
| SQL Injection | Eloquent/query builder; zero SQL cru com interpolação |
| CSRF | API stateless com token Bearer; CSRF só nas rotas web de sessão |
| Uploads | validação de MIME real + extensão + tamanho; servidos por rota autorizada, não por path público adivinhável |
| LGPD | export e exclusão de dados por workspace; `ai_runs` guarda prompt e resposta — precisa de política de retenção explícita |

**Ponto de atenção da IA:** `ai_runs.input` e `ai_runs.output` guardam texto que o usuário escreveu e que o modelo gerou. Isso é dado pessoal sob LGPD assim que um Brand Profile menciona pessoas. Definir retenção (sugestão: 90 dias, purga em job agendado) antes do primeiro usuário real.

## 6. Design System

Definido pelo Stitch ("Executive Content Engine") e documentado em [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md): esmeralda `#006c49` como primária usada com parcimônia, superfícies quase-brancas levemente lilás, Geist para títulos e Inter para corpo, grid de 8px, profundidade por contorno e não por sombra.

Tokens vivem no `tailwind.config.ts` como escala semântica (`bg-surface`, `text-on-surface`, `border-outline-variant`). Nenhum componente escreve hex.

O design confirma o wizard de 4 passos do Perfil da Marca e levantou três contradições com o spec — engajamento no Dashboard, publicação automática, e o chip "Gerado por IA" sem coluna que o sustente. **As três estão resolvidas** (ADR-11 e `contents.source`); as telas do Stitch precisam ser ajustadas a essas decisões.

## 7. O que está fora do MVP, de propósito

Redis · Reverb/WebSockets · S3 · Stripe · Mercado Pago · Telescope · Sentry · integração com APIs das redes sociais · analytics de desempenho · OpenAI/Gemini/OpenRouter · geração de imagem.

Cada um entra quando existir a fatia que o justifique. Ver [ROADMAP.md](ROADMAP.md).

## Documentos

- [DATA-MODEL.md](DATA-MODEL.md) — tabelas, relações, enums
- [API.md](API.md) — contratos REST
- [AI-LAYER.md](AI-LAYER.md) — agentes, providers, schemas, custo
- [ROADMAP.md](ROADMAP.md) — fases e critérios de verificação
