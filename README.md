# 2M Social AI

Plataforma SaaS de planejamento estratégico de conteúdo com Inteligência Artificial.

> **Estado atual: Fases 0, 1 e 2 concluídas e verificadas.**
> A fatia vertical Auth → Projeto → Perfil da Marca funciona de ponta a ponta no browser. Próximo: Fase 3 — o primeiro agente de IA (Estrategista).

## Documentos

| Doc | O que resolve |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Decisões (10 ADRs), camadas, módulos, segurança |
| [docs/DATA-MODEL.md](docs/DATA-MODEL.md) | Tabelas, relações, máquina de estados do conteúdo |
| [docs/API.md](docs/API.md) | Contratos REST de todos os módulos |
| [docs/AI-LAYER.md](docs/AI-LAYER.md) | Sete agentes, providers, structured output, custo, guardrails |
| [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) | Tokens do Stitch, componentes, contradições com o spec |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Seis fases, cada uma com critério de verificação |

## Decisões travadas

- **Tenancy:** Workspace com papéis (`owner` `admin` `editor` `reviewer` `viewer`), `workspace_id` em toda tabela desde a primeira migration.
- **Publicação no MVP:** agendar e exportar. Sem API das redes sociais — e portanto sem Analytics de desempenho.
- **IA:** Anthropic Claude (`claude-opus-4-8`) como único provider real, atrás da interface `LlmProvider`.
- **Fora do MVP, de propósito:** Redis, Reverb, S3, Stripe, Mercado Pago, geração de imagem, integração com redes.

## Stack

**Frontend** React 19 · TypeScript · Vite · Tailwind · TanStack Query
**Backend** Laravel 13 · PostgreSQL 16 · fila e cache em `database`
**IA** Anthropic SDK para PHP (`anthropic-ai/sdk`)

## Ambiente local

| | |
|---|---|
| PHP | 8.4.23 NTS — `C:\Users\mysho\bin\php84` |
| Composer | 2.10.2 — `C:\Users\mysho\bin\composer` |
| PostgreSQL | 16.14 portátil — `C:\Users\mysho\bin\pgsql16` |
| Banco | `2m_social_ai` em `127.0.0.1:5433` |

A porta é **5433**, não 5432: esta máquina já tem um PostgreSQL 17 como serviço na 5432, intocado.

O nosso Postgres **não é um serviço** e não sobe sozinho após reiniciar:

```powershell
pg_ctl -D C:\Users\mysho\pgdata\16 -l C:\Users\mysho\pgdata\pg16.log start
```

## Backend

```powershell
cd apps\api
php artisan migrate:fresh --seed   # 20 tabelas + dados de demonstração
php artisan test                   # 10 testes contra o Postgres de teste
php artisan serve                  # http://localhost:8000
```

Testes rodam contra `2m_social_ai_test` no Postgres, **não** em SQLite — `jsonb`, `timestamptz` e enums não existem lá.

## Frontend

```powershell
cd apps\web
pnpm install
pnpm dev      # http://localhost:5173, com proxy /api -> :8000
pnpm build
```

Tokens do design no bloco `@theme` de `src/index.css` (Tailwind v4 é CSS-first). Nenhum componente escreve hex.

## Próximo passo

**Fase 3** — o primeiro agente de IA real (Estrategista), com `LlmProvider`, `ai_runs` e polling.
Critério: com `AI_PROVIDER=mock` a suíte inteira roda verde sem chave de API; com a chave real, gerar uma estratégia e ver `cost_cents` preenchido em `ai_runs`.
