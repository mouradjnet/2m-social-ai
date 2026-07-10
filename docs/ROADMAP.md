# 2M Social AI — Roadmap

Cada fase tem um **critério de verificação executável**. Uma fase não está pronta porque o código compila; está pronta porque o critério passa.

---

## Fase 0 — Infraestrutura ✅ concluída em 2026-07-09

| Componente | Versão | Local |
|---|---|---|
| Node / pnpm | 24.11.1 / 10.17.0 | já existia |
| PHP | **8.4.23 NTS** | `C:\Users\mysho\bin\php84` |
| Composer | **2.10.2** | `C:\Users\mysho\bin\composer` |
| PostgreSQL | **16.14** (portátil) | `C:\Users\mysho\bin\pgsql16`, dados em `C:\Users\mysho\pgdata\16` |
| Redis | — | **adiado** por decisão (ADR-10) |
| Docker | — | não necessário |

Extensões PHP habilitadas: `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `intl`, `sodium`, `gd`, `opcache`. `memory_limit = 512M`.

### Três surpresas, e o que fizemos

**1. Os pacotes do winget para PHP 8.4 estão quebrados.** Tanto `PHP.PHP.8.4` quanto `PHP.PHP.NTS.8.4` apontam para `php-8.4.22`, que o php.net removeu — o download dá 404. A versão atual do branch é **8.4.23**. Instalamos direto do php.net, consultando `releases.json` para descobrir a versão real.

**2. A EnterpriseDB bloqueia o cliente HTTP do .NET.** O download do PostgreSQL via winget (e via `Invoke-WebRequest`) devolve **403 Forbidden**; o mesmo URL com `curl.exe` devolve **200**. É bloqueio por fingerprint de cliente, não por rede.

Isso nos empurrou para uma solução melhor do que a planejada: **binários portáteis em vez do instalador**. Sem elevação, sem serviço do Windows, sem UAC, e o cluster inteiro cabe em duas pastas que dá para apagar.

**3. A máquina já tinha um PostgreSQL 17 rodando** como serviço (`postgresql-x64-17`, automático), ocupando a **5432**. Não sabemos a senha dele e não o tocamos.

**Nosso cluster 16 roda na porta 5433**, escutando apenas em `localhost`. Os dois coexistem sem conflito.

> Armadilha registrada: `Set-Content -Encoding utf8` no PowerShell 5.1 grava **BOM**, e o `postgresql.conf` com BOM faz o servidor recusar subir com "erro de sintaxe na linha 1". Use `[IO.File]::WriteAllText` com `UTF8Encoding($false)`.

### Conexão

```
host=127.0.0.1  port=5433  dbname=2m_social_ai  user=postgres  password=<ver apps/api/.env>
```

A senha vive apenas no `.env`, que não é versionado. Servidor escuta só em `localhost`. Trocar antes de qualquer uso fora desta máquina.

### O servidor não é um serviço — precisa ser iniciado

Não há auto-start. Depois de reiniciar o Windows:

```powershell
pg_ctl -D C:\Users\mysho\pgdata\16 -l C:\Users\mysho\pgdata\pg16.log start
pg_ctl -D C:\Users\mysho\pgdata\16 status   # conferir
pg_ctl -D C:\Users\mysho\pgdata\16 stop     # parar
```

**Verificação executada:** `php -v` → 8.4.23 · `composer --version` → 2.10.2 · script PDO conectou em `2m_social_ai`, criou tabela, inseriu linha, leu de volta e removeu a tabela. PHP 8.4 fala com PostgreSQL 16 de ponta a ponta.

---

## Fase 1 — Fundação

**Por que as migrations inteiras já na Fase 1:** `workspace_id` em todas as tabelas é o que evita a migração cara depois (ADR-01). Criar as tabelas vazias custa quase nada; adicionar `workspace_id` em 15 tabelas com dados custa muito.

### 1a — Backend ✅ concluído em 2026-07-09

Laravel 13.19 em `apps/api`, com:

- **13 migrations**, 20 tabelas nossas + 9 do framework. `jsonb` de verdade, `timestamptz`, enums com check constraint.
- **Sanctum** — registro, login, logout, `me`.
- **`WorkspaceRole`** — enum linear `viewer < editor < reviewer < admin < owner`. A ordem importa: se `reviewer` não estivesse acima de `editor`, "reviewer+" incluiria editores e qualquer um aprovaria conteúdo.
- **`EnsureWorkspaceMember`** — middleware `workspace:{papel}`. Não-membro recebe **404**, não 403.
- **`WorkspaceMemberScope`** — global scope no `Project`. Defesa em profundidade: mesmo que alguém esqueça um `where workspace_id`, a query não devolve linhas de outro tenant.
- **Seeder** com dois workspaces sem relação entre si, para o isolamento ser visível em desenvolvimento e não só em teste.

**Verificação executada:**
- `php artisan migrate:fresh --seed` roda limpo (2 workspaces, 6 membros, 5 projetos).
- `php artisan test` — **10 testes, 15 asserções, verdes**, rodando contra **PostgreSQL de verdade** (`2m_social_ai_test`), não SQLite: `jsonb`, `timestamptz` e enums não existem no SQLite, e testar no que não é produção é testar outra coisa.
- Os testes que importam: usuário do Workspace A recebe 404 ao acessar projeto do Workspace B; a listagem não vaza projetos de outro workspace; `viewer` recebe 403 ao criar projeto e `editor` recebe 201.
- Conferido no banco: `activity_logs.user_id` é `NOT NULL` (ADR-11), `contents.source` e `contents.origin_ai_run_id` existem, colunas de JSON são `jsonb`.

> Duas pegadinhas registradas: o pluralizador do Laravel trata `research` como invariável (ver ADR-12), e `php artisan test` não aceita `--no-interaction` (é o PHPUnit que recebe o argumento).

### 1b — Frontend ✅ concluído em 2026-07-09

Vite 8 + React 19 + TypeScript 6 + Tailwind 4 em `apps/web`.

- Tokens do [design system](DESIGN-SYSTEM.md) no bloco `@theme` de `src/index.css` (Tailwind v4 é CSS-first; não há `tailwind.config.ts`).
- Fontes Geist e Inter **auto-hospedadas**, subsetadas pelo Vite no build.
- Quatro componentes base: `Button` (primary/secondary/ghost), `Card`, `Input` (label acima, borda esmeralda só no foco, erro anunciado por `aria-describedby`) e `Stepper` (linhas finas, indicadores circulares).
- Proxy `/api` → `127.0.0.1:8000` no dev server.

**Verificação executada:** `pnpm build` passa; a página renderizada no browser reproduz o passo 1 do wizard do Perfil da Marca, e preencher o nome da marca pinta o passo 1 do Stepper de esmeralda. Nenhum erro no console vindo do nosso código.

> Duas pegadinhas registradas. **`baseUrl` foi depreciado no TypeScript 6** — com `moduleResolution: "bundler"`, os `paths` já resolvem sem ele. E o `index.html` do Vite nasce com **`lang="en"`**: o Chrome detecta o português, decide que a página está em inglês e **traduz automaticamente**, reescrevendo textos (vimos `ex:` virar `Exemplo:`). Corrigido para `pt-BR`.

---

## Fase 2 — Fatia vertical: Auth → Projeto → Perfil da Marca

A primeira fatia que um usuário consegue atravessar.

- registro, login, criação de workspace
- CRUD de projetos
- wizard de 4 passos do Perfil da Marca com `PATCH` parcial e `completion`
- Dashboard com estado vazio honesto ("Nenhum projeto ainda")

**Sem IA nenhuma nesta fase.** Ela existe para provar que o esqueleto de tenancy, autorização e persistência aguenta peso.

**Verificação (no browser, não só em teste):** criar conta → criar projeto → completar os 4 passos do wizard → recarregar a página → os dados persistem e o stepper mostra 100%.

---

## Fase 3 — Primeiro agente real: Estrategista

A fatia que prova a [camada de IA](AI-LAYER.md) de ponta a ponta.

- `LlmProvider` + `AnthropicProvider` + `MockProvider`
- `RunAgentJob`, tabela `ai_runs`, polling em `GET /ai-runs/{run}`
- Agente `strategist` com structured output validado
- Tela de Estratégia: gerar, revisar, editar, salvar
- Orçamento por workspace verificado **antes** de enfileirar
- Comando `php artisan ai:count-tokens` para medir o prefixo (decide se o cache liga algum dia)

**Verificação:** com `AI_PROVIDER=mock`, a suíte inteira roda verde sem chave de API. Com a chave real, gerar uma estratégia para um projeto de verdade, editá-la na tela, e `ai_runs` mostrar `cost_cents` preenchido e `status = succeeded`.

**Risco desta fase:** é aqui que descobrimos se os schemas de JSON Schema sobrevivem sem `minLength`/`maximum`. Reserve tempo para validação determinística em PHP.

---

## Fase 4 — Conteúdo, workflow e calendário

- CRUD de `contents`
- máquina de estados `ContentWorkflow` + `POST :transition`
- agentes `copywriter`, `seo`, `reviewer` (com os guardrails determinísticos)
- calendário como view de leitura
- comentários e histórico de revisões

**Verificação:** um conteúdo percorre `idea → production → review → approved → scheduled` com os papéis corretos; um `editor` recebe 403 ao tentar aprovar; um conteúdo com palavra proibida recebe 422 com o campo `forbidden_words` preenchido; o calendário mostra a peça no dia agendado.

Só depois desta fase o produto tem o loop completo do spec (Dashboard → Projeto → Marca → Estratégia → Conteúdo → Calendário).

---

## Fase 5 — Campanhas, Biblioteca e Exportação

- campanhas gerando múltiplos conteúdos
- `assets`, `snippets`, `templates`
- exportação `.zip` (o que "Publicação" significa no MVP — ADR-03)
- agente `designer` produzindo `image_prompt`

**Verificação:** criar campanha "Black Friday", gerar 8 conteúdos a partir dela, aprovar 3, exportar o zip e abrir o conteúdo fora do sistema.

---

## Fase 6 — Analytics de produtividade

Só o computável (ADR-08): produzidos, aprovados, tempo de aprovação, aderência, cobertura, Pontuação do Calendário com decomposição.

**Verificação:** os números do Dashboard batem com uma contagem manual no banco. Se não baterem, o Analytics está mentindo — e um Analytics que mente é pior que nenhum.

O tile "ENGAJAMENTO +12.4%" do design **não existe**. Foi substituído pela Pontuação do Calendário na Fase 6 (ADR-11). O Dashboard da Fase 2 já nasce sem ele.

---

## Depois do MVP, em ordem de valor

1. **Geração de imagem** — fecha o loop do agente Designer, que hoje só produz o prompt.
2. **Integração real com as redes** — OAuth, app review, publicação agendada. É a fase mais longa e a que desbloqueia o Analytics de desempenho de verdade.
3. **Pagamentos** — Stripe + Mercado Pago, quando houver o que cobrar.
4. **Reverb + Redis** — quando houver colaboração simultânea real e o polling incomodar.
5. **Provedores adicionais de IA** — Gemini, OpenAI, OpenRouter. Cada um é um arquivo novo implementando `LlmProvider` (ADR-06).
6. **Sentry + Telescope** — antes do primeiro usuário pagante, não antes do primeiro commit.

---

## O que mantém isso honesto

Três regras que valem para todas as fases:

1. **Nenhuma fase entrega infraestrutura sem a funcionalidade que a usa.** Redis não entra porque "vamos precisar"; entra quando um número mostrar que o driver `database` não aguenta.
2. **Nenhuma fase é declarada pronta sem o critério de verificação passar no browser.** Teste verde e feature quebrada convivem bem demais.
3. **Toda decisão que contraria o spec vira um ADR com o motivo.** Os dez primeiros estão em [ARCHITECTURE.md](ARCHITECTURE.md#2-decisões-de-arquitetura-adrs).
