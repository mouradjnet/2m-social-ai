# 2M Social AI

Plataforma SaaS de planejamento estratégico de conteúdo com Inteligência Artificial.

> **Estado atual (2026-09-18): MVP completo e no ar em <https://twom-social-ai.onrender.com>.**
> Os oito agentes funcionam de ponta a ponta e foram validados com a IA real em produção. Em repouso, produção roda com `AI_PROVIDER=mock` — o serviço é público e a chave é paga.
> Suíte: **309 testes no backend** (Postgres real) e **88 no frontend** (vitest).

## O que o produto faz

Perfil da Marca → Estratégia → Conteúdo → Revisão → Calendário → Exportação, com uma camada de IA em cada passo. **A IA propõe; o humano aprova.**

| Agente | O que faz |
|---|---|
| `strategist` | Gera a estratégia: linha editorial e pilares com peso |
| `copywriter` | Escreve um lote de peças a partir da estratégia ativa, sem repetir o que já existe e cobrindo o pilar em déficit |
| `social_media` | Distribui as peças aprovadas no calendário, no fuso do projeto |
| `reviewer` | Julga as peças contra o perfil da marca e aponta violações |
| `rewriter` | Conserta uma peça reprovada, no lugar |
| `designer` | Escreve o prompt de imagem das peças (não gera a imagem) |
| `seo` | Propõe título, keywords e hashtags; aplicar é do humano |
| `analytics` | Lê os números do projeto e diz o que o calendário está contando |

A entrega é um **zip**: um `.md` por peça aprovada/agendada e um `calendario.csv`. Não há integração com as redes sociais.

O trabalho é em equipe: workspaces com papéis e **convite por link** — o admin gera em `/equipe`, o convidado aceita em `/convite/{token}`. Não há envio de e-mail: o link é copiado e mandado por fora.

## Documentos

| Doc | O que resolve |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Decisões (ADRs), camadas, módulos, segurança |
| [docs/DATA-MODEL.md](docs/DATA-MODEL.md) | Tabelas, relações, máquina de estados do conteúdo |
| [docs/API.md](docs/API.md) | Contratos REST de todos os módulos |
| [docs/AI-LAYER.md](docs/AI-LAYER.md) | Agentes, providers, structured output, custo, guardrails |
| [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) | Tokens do Stitch, componentes, contradições com o spec |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Fases, cada uma com critério de verificação |
| [docs/DEPLOY.md](docs/DEPLOY.md) | Render (web + fila no mesmo container no free), banco no Neon |
| [docs/superpowers/](docs/superpowers/) | Specs e planos de cada fatia |

## Decisões travadas

- **Tenancy:** Workspace com papéis (`owner` `admin` `editor` `reviewer` `viewer`), `workspace_id` em toda tabela desde a primeira migration. O workspace vem da rota, não da sessão.
- **Publicação no MVP:** agendar e exportar. Sem API das redes sociais — e portanto sem Analytics de desempenho (só produtividade e aderência aos pilares).
- **IA:** Anthropic Claude (`claude-opus-4-8`) como único provider real, atrás da interface `LlmProvider`. Toda geração é um job: `202` + polling em `/ai-runs/{id}`.
- **Custo:** teto mensal por workspace (`AI_WORKSPACE_MONTHLY_BUDGET_CENTS`), conferido antes de enfileirar — estourou, `402`. Toda chamada paga entra na conta, inclusive as rejeitadas.
- **Cadastro fechado:** só entra quem está em `REGISTRATION_ALLOWED_EMAILS`. Login limitado a 5 tentativas por minuto por email; token expira em 7 dias.
- **Fora do MVP, de propósito:** Redis, Reverb, S3, Stripe, Mercado Pago, geração de imagem, integração com redes.

## Stack

**Frontend** React 19 · TypeScript · Vite · Tailwind 4 · TanStack Query
**Backend** Laravel 13 · PostgreSQL 16 · fila e cache em `database`
**IA** Anthropic SDK para PHP (`anthropic-ai/sdk`)
**Deploy** Render (Docker, FrankenPHP) · Postgres no Neon

## Ambiente local

| | |
|---|---|
| PHP | 8.4.23 NTS — `C:\Users\mysho\bin\php84` |
| Composer | 2.10.2 — `C:\Users\mysho\bin\composer` |
| PostgreSQL | 16.14 portátil — `C:\Users\mysho\bin\pgsql16` |
| Banco | `2m_social_ai` em `127.0.0.1:5433` (testes em `2m_social_ai_test`) |

A porta é **5433**, não 5432: esta máquina já tem um PostgreSQL 17 como serviço na 5432, intocado.

O nosso Postgres **não é um serviço** e não sobe sozinho após reiniciar:

```powershell
pg_ctl -D C:\Users\mysho\pgdata\16 -l C:\Users\mysho\pgdata\pg16.log start
```

## Backend

```powershell
cd apps\api
php artisan test         # suíte inteira contra o Postgres de teste
php artisan serve        # http://localhost:8000
php artisan queue:work   # sem isto, nenhuma geração de IA sai da fila
```

Testes rodam contra `2m_social_ai_test` no Postgres, **não** em SQLite — `jsonb`, `timestamptz`, enums e índices parciais não existem lá. Com `AI_PROVIDER=mock` a suíte roda sem chave de API.

`php artisan migrate:fresh --seed` recria o banco com dados de demonstração — **apaga o que houver no banco de dev**.

## Frontend

```powershell
cd apps\web
pnpm install
pnpm dev      # http://localhost:5173, com proxy /api -> :8000
pnpm test
pnpm build
```

Tokens do design no bloco `@theme` de `src/index.css` (Tailwind v4 é CSS-first). Nenhum componente escreve hex.

## Barreira de qualidade

O hook `pre-push` roda Pint, PHPUnit, `tsc` e vitest — o mesmo que o CI (`.github/workflows/ci.yml`). Instalar uma vez por clone:

```powershell
git config core.hooksPath .githooks
```
