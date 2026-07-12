# Agente `designer` — o prompt de imagem

**Problema:** `contents.image_prompt` existe desde a primeira migration e ninguém escreve nele. E `brand_profiles.colors` (jsonb) e `logo_path` também existem — sem tela, sem prompt, sempre vazios. O 5º agente (na doc: *conteúdo + cores/logo da marca → `image_prompt`*) fecha os dois buracos de uma vez.

## Escopo (decidido com o Djair)

| Decisão | Escolha |
|---|---|
| Paleta | **Dar tela às cores.** O wizard ganha o campo Cores; o `AgentContext` passa a enviar `colors`; o prompt do designer manda respeitar a paleta |
| Lote | **As peças em Produção** — onde a peça está sendo feita é onde a imagem entra |
| UI | **`image_prompt` no card, com botão de copiar** (é o que se cola no gerador de imagem) |

**Fora do escopo:** `logo_path` (exige upload, e não há storage no MVP), gerar a imagem de fato, `image_asset_id`.

## O lote deixa de ser "do reviewer"

O `reviewer` mandava `content_ids` no `ai_runs.input` e o `AgentContext` carregava, com isso, as peças em `review`. O designer precisa do mesmo mecanismo, mas da coluna **Produção** — e com a mesma chave o contexto não saberia qual status filtrar.

Então o lote vira **genérico**: o input carrega `content_ids` **e** `batch_status`, e o contexto expõe `batchContents` + `batchStatus`. O reviewer passa a mandar `batch_status: review`, o designer `batch_status: production`. Um agente futuro que precise de um lote de outra coluna não mexe em nada disso.

O `input` continua sendo **intenção**: o filtro por status no momento da execução é a verdade — quem saiu da coluna entre o POST e a execução não é atendido.

## Backend

**Sem migration.** `image_prompt`, `colors` e `logo_path` já existem.

**`DesignerAgent`:**

- `schema`: `{ designs: [{ content_id, image_prompt }] }`.
- `instructions`: descreve a cena, o enquadramento e a luz a partir do texto da peça; respeita `colors` (hex) e o tom da marca; **o `image_prompt` sai em inglês** — é o idioma dos geradores de imagem, e é ele que o usuário vai colar. Sem texto embutido na imagem (gerador de imagem erra letra), sem logo (o logo é aplicado depois, e não temos o arquivo).
- `validate`: um design por peça do lote, exatamente; nenhum id de fora; nenhum repetido.
- `persist`: escreve `contents.image_prompt`. **Não toca no status.**

**`DesignController@generate`** — espelha o `ReviewController`: sem peça em Produção → **422**; `designer` em andamento → **409**; orçamento → **402**; senão **202**. Rota `POST /projects/{project}/design:generate`.

**Perfil da Marca:** `colors` entra no `AgentContext` (dentro de `brand_profile`) e ganha campo no wizard, no passo **Identidade**, como campo **opcional** — uma marca sem paleta declarada continua 100% completa. Isso muda, por um punhado de tokens, o prompt do strategist e do copywriter (que recebem o `brand_profile` inteiro); é o preço de o perfil ter mais um campo, e o campo é curto.

## Frontend

- `BrandProfileData` ganha `colors: string[]`; o passo Identidade ganha o campo (o `kind: 'list'` já existe: um por linha).
- `Content` ganha `image_prompt: string | null`.
- `ContentCard` mostra o prompt quando existe, com **Copiar**. **Gotcha:** `navigator.clipboard` é `undefined` em HTTP não-seguro (o dev roda em `localhost`, que é seguro, mas o LocalWP/IP não seria) — o botão usa o fallback `document.execCommand('copy')` via textarea oculta.
- `ContentPage` ganha **“Gerar imagens (N)”**, desabilitado quando Produção está vazia.

## Critério de sucesso

- `php artisan test` verde: designer rejeitando id fora do lote e repetido; persist escrevendo `image_prompt` sem mexer no status; 422/409/402; reviewer continua verde com o lote genérico.
- `pnpm test` + `pnpm build` + `oxlint` limpos.
- No browser: peças em Produção → “Gerar imagens” → o prompt aparece no card → Copiar coloca o texto na área de transferência; as cores digitadas no wizard chegam ao prompt.
