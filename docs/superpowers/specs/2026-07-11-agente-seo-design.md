# Agente `seo` — título, keywords e hashtags

**Problema:** o copywriter escreve o texto pensando na marca; ninguém pensa em **busca**. O 6º dos sete agentes (na doc: *conteúdo + palavras-chave → título otimizado, hashtags, keywords*) propõe a versão que encontra público — sem apagar a que já existe.

## Escopo (decidido com o Djair)

| Decisão | Escolha | Por quê |
|---|---|---|
| Persistência | **Propõe; o humano aplica** | `title` e `hashtags` já vêm preenchidos pelo copywriter. Sobrescrever direto destruiria o texto aprovado, sem histórico e sem desfazer — e daria à IA o poder de promover. |
| Lote | **A coluna Produção** | Mesma do designer: é onde a peça é preparada. Otimizar em Revisão mudaria o título **depois** de o reviewer ter julgado o texto — o veredito passaria a valer para uma peça que não existe mais. |

**Fora do escopo:** pesquisa real de volume de busca (não há API), `researches`/`research_insights`, editar a peça à mão.

## Backend

**Migration nova** — `content_seo`, no mesmo molde de `content_reviews`:

| Coluna | |
|---|---|
| `content_id` | FK, cascade |
| `ai_run_id` | FK — a sugestão veio de uma execução, e o custo é rastreável por ela |
| `title` | string — o título otimizado |
| `keywords` | jsonb — termos de busca |
| `hashtags` | jsonb |
| `applied_at` | timestamp nullable — **qual sugestão virou a peça** |
| `created_at` | append-only |

**`SeoAgent`:**

- `schema`: `{ seo: [{ content_id, title, keywords, hashtags }] }`.
- `instructions`: o título otimizado depende do **canal** — no blog ele responde a uma busca; no Instagram ele é um gancho. `keywords` são termos que alguém digitaria; `hashtags`, as que aquele canal usa. Respeita `forbidden_words` (a regra da marca vale aqui também) e não promete o que a peça não entrega.
- `validate`: um bloco por peça do lote, exatamente; nenhum id de fora; nenhum repetido.
- `persist`: uma linha em `content_seo`. **Não toca em `title`, `hashtags` nem no status.**

**`POST /contents/{content}/seo:apply`** — aplica a última sugestão: grava `title`/`hashtags` na peça, marca `applied_at` e escreve um `ContentRevision` **sem status** com `changes = {title: {from,to}, hashtags: {from,to}}` — o mesmo mecanismo da remarcação. Assim o histórico conta que o SEO entrou, e o texto antigo não some sem deixar rastro. Sem sugestão → **422**.

**`SeoController@generate`** — espelha o `DesignController`: 422 sem peça em Produção; 409; 402; senão 202. Rota `POST /projects/{project}/seo:generate`.

**`ContentController@index`** passa a carregar também a última sugestão de SEO (`latestSeo`).

## Frontend

- `Content` ganha `latest_seo`.
- `ContentCard` mostra a sugestão quando existe (título, keywords, hashtags) e um botão **“Aplicar SEO”**, que some quando já aplicada (a peça já é a sugestão).
- `ContentPage` ganha **“Otimizar SEO (N)”**, desabilitado quando Produção está vazia.

## Critério de sucesso

- `php artisan test` verde: agente rejeitando id fora do lote e repetido; `persist` **não** alterando `title`/`hashtags`; `seo:apply` gravando título, hashtags, `applied_at` e a revisão com `changes`; 422 ao aplicar sem sugestão; 422/409/402 do controller.
- `pnpm test` + `pnpm build` + `oxlint` limpos.
- No browser: peças em Produção → “Otimizar SEO” → sugestão no card → “Aplicar SEO” → o título da peça muda e a revisão registra o de-para.
