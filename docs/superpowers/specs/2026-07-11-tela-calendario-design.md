# Tela de Calendário — grade mensal e remarcação

**Problema:** o `SocialMediaAgent` agenda as peças e o board mostra uma coluna "Agendado" com cartões empilhados. Isso responde *quais* peças estão agendadas, mas não a pergunta que um calendário editorial existe para responder: **como está o meu mês?** O índice `(project_id, scheduled_for)` já dizia, em comentário, que "o calendario le por aqui" — e ninguém lia.

## Escopo (decidido com o Djair)

| Decisão | Escolha |
|---|---|
| Visualização | **Grade mensal** (7 colunas × semanas), com navegação mês anterior/próximo |
| Interação | **Mostrar + remarcar por campo de data.** Sem arrastar |

**Fora do escopo:** drag-and-drop, visão semanal, `published`, "Pontuação do Calendário", campanhas.

## Backend

**Nenhuma migration.** A coluna `content_revisions.changes` (jsonb, default `{}`) existe desde a primeira migration e nunca foi usada. Ela é o registro de uma mudança que **não é de status** — e `from_status`/`to_status` são nullable justamente para isso.

**`PATCH /contents/{id}`** passa a aceitar `scheduled_for` além de `status`. Regras:

- Exatamente **um dos dois** por requisição. Mover no fluxo e remarcar são gestos diferentes; aceitar os dois juntos criaria uma transição cuja revisão não saberia contar o que aconteceu.
- Só uma peça em `scheduled` pode ser remarcada — uma peça em `idea` não tem data para mudar. Fora disso, **422**.
- A remarcação grava uma revisão com `from_status`/`to_status` **nulos** e `changes = {"scheduled_for": {"from": ..., "to": ...}}`. O histórico continua append-only e passa a contar também as mudanças de data, não só as de coluna.

`ContentRevision` ganha `changes` no `$fillable` e o cast para array — hoje o model nem enxerga a coluna.

## Frontend

**`CalendarPage`** em `/projects/:projectId/calendar`, com link de ida e volta da `ContentPage`.

- Lê a **mesma query** `['contents', projectId]` da `ContentPage`: o cache é compartilhado, e remarcar invalida as duas telas de uma vez.
- `lib/calendarGrid.ts` — função **pura**: dado um mês, devolve as semanas (arrays de 7 dias, com os dias vizinhos preenchendo as bordas) e, dado o conjunto de peças, quais caem em cada dia. Função pura = teste sem DOM, que é onde a lógica de calendário costuma errar (fuso, virada de mês, semana que começa no domingo).
- A célula do dia mostra as peças agendadas nele (hora + título). Peça selecionada abre um painel com `datetime-local` + **Salvar** (PATCH `scheduled_for`) e **Desagendar** (PATCH `status: approved`, que o servidor já trata limpando a data).
- Mês atual por padrão; `‹` e `›` navegam. Peças fora do mês visível simplesmente não aparecem.

## Critério de sucesso

- `php artisan test` verde, incluindo: remarcar grava `changes` e revisão sem status; remarcar peça não-agendada dá 422; mandar `status` e `scheduled_for` juntos dá 422.
- `pnpm test` + `pnpm build` + `oxlint` limpos, com os testes da `ContentPage` e da `StrategyPage` intactos.
- No browser: agendar pelo agente → abrir o calendário → as peças aparecem nos dias certos → remarcar uma → ela muda de dia e o `content_revisions` registra a mudança de data.
