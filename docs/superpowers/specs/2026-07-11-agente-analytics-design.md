# Agente `analytics` — a leitura do calendário

**Problema:** o produto planeja, escreve, revisa, ilustra, otimiza e agenda — e ninguém olha para o conjunto. O 7º e último agente (na doc: *agregações de `contents` → insights em texto + pontuação*) responde: **o que este calendário está dizendo?**

**Sem API das redes sociais, não existe desempenho.** Não há curtida, alcance nem clique — e inventá-los seria mentir. O que existe é **produtividade** (quanto se produz, com que cadência), **aderência** (o que a estratégia pediu × o que foi feito) e **qualidade interna** (o que o reviewer apontou). É sobre isso, e só isso, que o relatório fala.

## Escopo (decidido com o Djair)

| Decisão | Escolha |
|---|---|
| Pilar | **Persistir.** `contents.pillar` passa a existir e o copywriter grava |
| Saída | **Pontuação 0-100 + insights + métricas**, guardados em tabela append-only |
| Tela | **`/projects/:id/insights`**, nova |

**Fora do escopo:** métricas de desempenho (não há dados), comparação entre projetos, exportar relatório.

## O pilar era gerado e jogado fora

O `CopywriterAgent` **já pede** `pillar` no schema, e o prompt manda distribuir as peças pelos pesos da estratégia. Mas o `persist()` descarta o campo — não havia coluna. Ou seja: a informação que responde à pergunta mais importante ("a estratégia pediu 40% de Educação; entreguei quanto?") nasce a cada geração e morre ali.

Migration acrescenta `pillar` (string, nullable) em `contents`, e o copywriter passa a gravá-lo. **Peças antigas ficam sem pilar** — e as métricas contam isso (`sem_pilar: N`) em vez de fingir que a amostra é completa.

## Os números são do servidor, não do LLM

`Domain\Analytics\Metrics` calcula, em PHP:

- **Volume:** total e contagem por status.
- **Aderência:** distribuição real por pilar × os pesos da estratégia ativa, com o desvio de cada pilar em pontos percentuais.
- **Cadência:** peças agendadas nos próximos 30 dias, quantos dias têm peça e a maior lacuna sem publicação.
- **Qualidade:** quantas revisões passaram, quantas reprovaram, e as regras mais violadas.
- **Mix:** por canal e por formato.

**Não se pede conta a um LLM.** O agente recebe os números prontos e faz o que ele faz bem: ler, priorizar e dizer o que fazer.

## Backend

**Duas migrations:** `contents.pillar`; e `analytics_reports` (`project_id`, `ai_run_id`, `score` 0-100, `summary`, `insights` jsonb `[{title, detail, action}]`, `metrics` jsonb — o **snapshot** dos números que geraram aquela leitura —, `created_at`, append-only).

**`AnalyticsAgent`:**

- `schema`: `{ score, summary, insights: [{title, detail, action}] }`.
- `validate`: `score` entre 0 e 100 (o JSON Schema da API **não aceita `minimum`/`maximum`** — a regra vive aqui), e ao menos um insight. Cada insight precisa de uma ação: um diagnóstico sem próximo passo não serve para nada.
- `instructions`: julga o calendário pelos números recebidos; **não inventa métrica que não recebeu** e não fala de desempenho (não existe dado). A pontuação pondera aderência, cadência e qualidade.
- `persist`: uma linha em `analytics_reports`, com o snapshot das métricas.

**`AgentContext`** ganha `?array $projectMetrics`, carregado quando o input traz `with_metrics: true` — mesma disciplina do lote e da janela.

**Rotas:** `POST /projects/{project}/analytics:generate` (422 sem nenhuma peça; 409; 402; senão 202) e `GET /projects/{project}/analytics` (o relatório mais recente, ou `null`).

## Frontend

`InsightsPage` em `/projects/:projectId/insights`, com link de ida e volta do Conteúdo: a **pontuação em destaque**, os números (volume, aderência por pilar com o desvio, cadência, qualidade) e os insights (título, detalhe, ação). Botão **“Gerar relatório”** pelo mesmo `useGeneration`. Sem relatório: um estado vazio que explica o que a tela vai mostrar.

## Critério de sucesso

- `php artisan test` verde: `Metrics` calculando aderência, lacuna e qualidade sobre um cenário montado à mão; agente rejeitando `score` fora de 0-100 e insight sem ação; copywriter gravando `pillar`; 422/409/402.
- `pnpm test` + `pnpm build` + `oxlint` limpos.
- No browser: gerar relatório → pontuação, números e insights na tela; o `metrics` gravado bate com o que a tela mostra.
