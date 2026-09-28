# Retomada — setembro de 2026

Diário da retomada que transforma o planejador de conteúdo em publicador para o
Instagram. Cada fase registra o que foi encontrado e o que foi verificado.

---

## Fase 0 — Estado inicial (2026-09-28)

**Repositório:** `main` limpo e sincronizado com `origin/main`, último commit `1cacc2b`.

**Banco local:** o cluster 16 na porta 5433 estava de pé, mas o `pg_ctl status`
dizia o contrário e caiu no meio da primeira execução da suíte. Um `pg_ctl start`
disparado por um shell que depois se encerra leva o servidor junto. Suba-o
desacoplado:

```powershell
Start-Process C:\Users\mysho\bin\pgsql16\bin\pg_ctl.exe -ArgumentList 'start','-D','C:\Users\mysho\pgdata\16','-l','C:\Users\mysho\pgdata\pg16.log' -WindowStyle Hidden
```

**Migrations:** o banco de desenvolvimento estava três migrations atrás
(`add_timezone_to_projects`, `index_one_active_strategy_per_project`,
`add_rewriter_to_ai_runs_agents`). Aplicadas com `php artisan migrate` — só
acrescentam coluna, índice e valor de enum; nenhum dado apagado.

**Suítes:**

| | Resultado |
|---|---|
| PHPUnit (Postgres real) | 309 testes, 782 asserções, verdes (76 s) |
| vitest | 109 testes em 15 arquivos, verdes (o README dizia 88) |
| `tsc -b` | sem erros |
| `pnpm build` | ok |

**Fumaça:** `php artisan serve` responde `/up` 200, a SPA responde nos deep links,
o login devolve 422 com credencial errada, `queue:work --stop-when-empty` roda sem
jobs presos e `failed_jobs` está vazio.

**IA:** `AI_PROVIDER=mock` local. O modelo padrão `claude-opus-4-8` continua
válido e com o mesmo preço do Opus 5. Migrar para `claude-opus-5` muda o
comportamento de thinking (ligado por padrão) e fica como recomendação separada,
fora desta retomada.

**Nada impeditivo encontrado.** A única pendência de ambiente é o Postgres local,
que não é serviço e precisa ser iniciado à mão.
