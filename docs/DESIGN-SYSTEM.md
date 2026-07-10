# 2M Social AI — Design System

Fonte: `stitch_2m_social_ai_strategic_saas.zip` (Stitch, "Executive Content Engine").
Telas fornecidas: Dashboard, Projetos, Perfil da Marca, Biblioteca.

## Narrativa

**"Organized Intelligence."** SaaS premium minimalista para diretores de conteúdo. Muito espaço em branco, bordas ultrafinas, grid estrito. A ferramenta recua para o conteúdo do usuário aparecer. Referências: Linear e Notion — exatamente as do spec.

## Tokens

### Cor

Verde esmeralda como primária, usado com parcimônia — só CTA, estado ativo e sucesso.

| Token | Hex | Uso |
|---|---|---|
| `primary` | `#006c49` | botões primários, texto de link ativo |
| `primary-container` | `#10b981` | acentos, chips, badge de sucesso |
| `on-primary` | `#ffffff` | texto sobre primária |
| `secondary` | `#2b6954` | elementos secundários de alto contraste |
| `secondary-container` | `#adedd3` | fundo do item ativo da sidebar |
| `tertiary` | `#a43a3a` | avisos não-destrutivos |
| `error` | `#ba1a1a` | erro |
| `error-container` | `#ffdad6` | fundo de erro |
| `surface` / `background` | `#faf8ff` | fundo da aplicação |
| `surface-container-lowest` | `#ffffff` | cards, modais |
| `surface-container-low` | `#f2f3ff` | sidebar |
| `surface-container` | `#eaedff` | inputs em repouso, zebra de tabela |
| `on-surface` | `#131b2e` | títulos e corpo |
| `on-surface-variant` | `#3c4a42` | texto secundário |
| `outline` | `#6c7a71` | bordas de ênfase |
| `outline-variant` | `#bbcabf` | bordas padrão |

> **Divergência a resolver.** A prosa do DESIGN.md descreve a base como "Crisp White" e cita `#F8FAFC` (cinza-azulado neutro) e bordas `#E2E8F0`. Os tokens e as telas usam `#faf8ff` / `#eaedff` — uma família **levemente lilás**, não cinza neutro. Os dois não são a mesma paleta. **Os tokens vencem** (são o que as telas renderizam); a prosa está desatualizada. Registrado aqui para não virar bug de "por que o cinza puxa pro roxo".

### Tipografia

Duas famílias, hierarquia por **peso**, não por tamanho.

- **Geist** — títulos, labels, dados técnicos. Precisão "developer-grade".
- **Inter** — todo corpo de texto e leitura longa.

| Estilo | Família | Tamanho | Peso | Line-height | Tracking |
|---|---|---|---|---|---|
| `display-lg` | Geist | 48px | 600 | 1.1 | −0.02em |
| `headline-lg` | Geist | 32px | 600 | 1.2 | −0.02em |
| `headline-lg-mobile` | Geist | 24px | 600 | 1.2 | — |
| `headline-md` | Geist | 24px | 500 | 1.3 | — |
| `body-lg` | Inter | 18px | 400 | 1.6 | — |
| `body-md` | Inter | 16px | 400 | 1.5 | — |
| `body-sm` | Inter | 14px | 400 | 1.5 | — |
| `label-md` | Geist | 14px | 500 | 1 | 0.01em |
| `label-sm` | Geist | 12px | 500 | 1 | — |

Geist e Inter serão **auto-hospedadas** (`public/fonts/`), não carregadas de CDN — evita FOUT, dependência externa e um problema de LGPD com o Google Fonts.

### Espaçamento e grid

Base **8px** (token `base` de 4px existe para meio-passos).

`xs 4` · `sm 8` · `md 16` · `lg 24` · `xl 40` · gutter `24`

Layout **fixed-fluid**: sidebar fixa em 240–280px; conteúdo fluido com `max-width: 1440px`. Margem 48px no desktop, 16px no mobile. Multi-coluna colapsa para coluna única.

### Forma

Arredondamento **soft**, "Linear-like":

- Botões e inputs: **6px**
- Cards e containers: **8–12px**
- Chips e avatares: `full`

### Elevação

Profundidade por **camadas tonais e contornos**, não por sombra.

1. Base: `surface` (`#faf8ff`)
2. Superfície: card branco com borda 1px `outline-variant`, **sem sombra**
3. Lift: só em hover ou menu flutuante — `0 1px 2px rgba(0,0,0,.05)`

## Componentes

| Componente | Regra |
|---|---|
| Botão primário | fundo `primary` sólido, texto branco, radius 6px |
| Botão secundário | fundo branco, borda 1px, texto `on-surface` |
| Botão ghost | sem fundo nem borda; texto vira `primary` no hover |
| Card | borda 1px `outline-variant`, sem sombra, padding interno 24px, título em Geist Medium |
| Input | borda 1px, radius 6px, padding-x 12px; borda vira `primary` **só no foco**; label acima em `label-sm` |
| Sidebar | fundo `surface-container-low`; item ativo = barra vertical esmeralda à esquerda + fundo `secondary-container` |
| Tabela | sem bordas verticais; só divisórias horizontais; header com fundo cinza claro e Geist SemiBold |
| Stepper | linhas finas, indicadores circulares pequenos; **cada passo isolado no seu próprio card** |

## O que as telas confirmam sobre o produto

**O Perfil da Marca é um wizard de 4 passos, não um formulário.** A tela mostra "Vamos definir sua marca", conduzida por um chip **"Estrategista de IA"**, com passos: `1 Identidade` → `2 Público-Alvo` → `3 Posicionamento de Mercado` → `4 Links Sociais`. Isso é a materialização literal de *"o usuário nunca deve sentir que está preenchendo formulários; ele deve sentir que está conversando com um estrategista"*.

Consequência para a API: `PUT /brand-profile` precisa aceitar **salvamento parcial por passo**, não um payload completo. Ver [API.md](API.md).

**O Dashboard mostra trabalho, como o spec exige.** Campanhas ativas, Próximas Postagens com chips de status (`Aprovado`, `Precisa de Revisão`, `Rascunhando`), Insights em Alta, Atividade Recente. Nenhum formulário.

**A Biblioteca é filtrada por tipo** (`Tudo` · `Postagens` · `Imagens` · `Campanhas` · `Modelos`) e cada item traz chips como **"Gerado por IA"** e **"Aprovado"**.

## Três contradições entre o design e o spec — resolvidas

Decididas em 2026-07-09. As telas do Stitch precisam ser ajustadas a estas decisões, não o contrário.

### 1. O tile "ENGAJAMENTO +12.4%" sai do Dashboard ✅ decidido

Engajamento é métrica de desempenho das redes. Sem integração com as APIs do Instagram/LinkedIn/etc. — fora do MVP (ADR-03) — esse número não existe.

**Decisão:** o tile é substituído pela **Pontuação do Calendário**, que é computável sem dado externo e já está prevista no spec (seção Analytics). Ver ADR-11 em [ARCHITECTURE.md](ARCHITECTURE.md).

O tile passa a mostrar o score de 0 a 100 e, no hover ou clique, a decomposição — diversidade de formatos, distribuição temporal, cobertura de objetivos, aderência à linha editorial. Um número de 0 a 100 sem explicação é ruído; com a decomposição, é um diagnóstico acionável.

O segundo tile, "TAREFAS CONCLUÍDAS 24/28", permanece — é produtividade, computável a partir de `contents`.

### 2. Atividade Recente nunca diz "Sistema publicou" ✅ decidido

O spec é categórico: *"Nunca publique automaticamente."* Além disso, "Tweet" não está entre as seis redes suportadas.

**Decisão:** todo item de `activity_logs` tem um **ator humano**. A frase de exemplo do design vira "Djair publicou Carrossel de Lançamento". Publicação é sempre uma ação manual explícita (`scheduled → published` exige clique de um usuário com papel `editor` ou superior).

Consequência técnica: `activity_logs.user_id` deixa de ser nullable. Não existe ator "sistema" no MVP — se um dia existir (jobs agendados), ele terá um `system_actor` explícito e um rótulo que não induza o usuário a achar que publicamos por ele.

### 3. Chip "Gerado por IA" agora tem coluna que o sustenta ✅ aplicado

`contents` ganhou `source` (`manual` | `ai` | `research`) e `origin_ai_run_id` (fk `ai_runs`, nullable). Já está em [DATA-MODEL.md](DATA-MODEL.md). Além do chip, dá rastreabilidade de custo e auditoria por peça de conteúdo.

## Rótulos de navegação: escolher um conjunto canônico

As quatro telas usam nomes diferentes para os mesmos itens:

| Tela Dashboard | Tela Perfil da Marca | Tela Biblioteca |
|---|---|---|
| Painel | Dashboard | Painel |
| Área de Trabalho | Espaço de Trabalho | Espaço de Trabalho |
| Pesquisa IA | Pesquisa IA | Pesquisa com IA |
| Análise | Análise | Análises |

**Proposta de canônico (PT-BR, singular, sem anglicismo):**
`Painel` · `Espaço de Trabalho` · `Projetos` · `Perfil da Marca` · `Pesquisa IA` · `Estratégia` · `Calendário` · `Biblioteca` · `Análise` · `Configurações` · `Suporte`

## Implementação

Tokens vivem em `apps/web/src/index.css`, no bloco `@theme` do **Tailwind v4** — não existe mais `tailwind.config.ts` na v4, a configuração é CSS-first. Viram utilitários semânticos: `bg-surface`, `text-on-surface`, `border-outline-variant`, `bg-primary`, `text-headline-md`, `rounded-control`.

**Nenhum componente escreve hex.** É o que torna o tema trocável e evita a divergência prosa-vs-token virar dívida.

Fontes **auto-hospedadas** via `@fontsource-variable/geist` e `@fontsource-variable/inter`: sem CDN, sem FOUT, e sem enviar o IP de cada visitante ao Google Fonts (LGPD). O Vite subseta e versiona os `.woff2` no build.

O `index.html` declara `lang="pt-BR"`. Com o `lang="en"` que o Vite gera por padrão, o Chrome detecta português numa página marcada como inglês, **traduz automaticamente** e reescreve o conteúdo — chegamos a ver o placeholder "ex: Acme Corp" virar "Exemplo: Acme Corp" no browser. Um leitor de tela também pronunciaria tudo em inglês.
