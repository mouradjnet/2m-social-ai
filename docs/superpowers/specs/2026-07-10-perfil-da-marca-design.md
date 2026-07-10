# Perfil da Marca: completude honesta e campos alcançáveis — design

Data: 2026-07-10
Estado: aprovado, pronto para plano de implementação

## Problema

Três defeitos, descobertos ao gerar uma estratégia real com a API da Anthropic.

**A tela mente.** O `Completion` avalia cada passo por **um único campo**:
`identity` = `brand_name` preenchido, `audience` = `audience`, `positioning` =
`tone_of_voice`, `social` = qualquer link. O perfil do projeto 1 marcava
**"100% completo"** com `persona` nula, `differentiators` valendo literalmente
`"ok"`, e `products`/`services` vazios. O `claude-opus-4-8`, lendo o mesmo
perfil, escreveu que ele era "raso" e citou os campos vazios pelo nome.

**Cinco campos não têm tela.** O `AgentContext` envia onze campos ao modelo.
O wizard edita nove — e cinco não se sobrepõem: `products`, `services`,
`competitors`, `required_words`, `forbidden_words` chegam ao agente e **não
existem em lugar nenhum da UI**. O `UpdateBrandProfileRequest` e o `$fillable`
já aceitam todos; só a tela não pede.

**Três campos não servem para nada.** `website`, `instagram` e `linkedin`
ocupam o passo 4 inteiro e **nunca são enviados ao agente**.

O custo disso é mensurável. Com o perfil raso, o Opus 4.8 entregou quatro
pilares genéricos que serviriam para qualquer loja de carros do Brasil. Com o
perfil preenchido — mesmo agente, mesmo modelo, mesmos 4 centavos — entregou
pilares que citam o simulador de financiamento do site, o bairro da
Imbiribeira e as três categorias sob o mesmo teto. A diferença veio inteira de
`products`, `services` e `persona`: os campos que o wizard não alcança.

Um usuário real preencheria o wizard até 100%, geraria, receberia a estratégia
genérica, e nunca saberia o porquê.

## Escopo

**Dentro.** Reescrever o `Completion` para medir o que o agente lê. Acrescentar
dois passos ao wizard: `offer` (obrigatório) e `vocabulary` (opcional). Marcar
`social` como opcional. Tornar a resposta de completude autodescritiva.

**Fora.** Bloquear a geração de estratégia com perfil incompleto. Medir
profundidade em vez de presença. Enviar links sociais ao agente. Qualquer
migration — nenhuma coluna muda.

## Decisões

### O `Completion` mede o que o agente lê

Completo = os campos que o `AgentContext` envia ao modelo estão preenchidos.
`percent` passa a prever a qualidade da estratégia; hoje não prevê nada.

Redes sociais saem do cálculo, porque não influenciam a saída.

### A resposta é autodescritiva

O docblock atual do `Completion` já declara a intenção: *"O servidor decide
quais estão completos; o Stepper do frontend só desenha. Se a regra vivesse no
cliente, duas telas discordariam sobre o mesmo perfil."*

Se a obrigatoriedade dos passos morasse no array `STEPS` do React, criaríamos
exatamente a divergência que aquele comentário existe para impedir: nada
impediria o `Completion` de contar quatro passos enquanto o wizard mostra
outra coisa.

Então o servidor diz **quais passos existem, quais estão completos e quais são
obrigatórios**:

```php
[
  'steps' => [
    ['id' => 'identity',    'complete' => true,  'required' => true],
    ['id' => 'audience',    'complete' => false, 'required' => true],
    ['id' => 'positioning', 'complete' => true,  'required' => true],
    ['id' => 'offer',       'complete' => false, 'required' => true],
    ['id' => 'vocabulary',  'complete' => false, 'required' => false],
    ['id' => 'social',      'complete' => true,  'required' => false],
  ],
  'percent' => 50,
]
```

`percent` = passos obrigatórios completos ÷ 4. Os opcionais aparecem, informam
se estão preenchidos, e nunca movem o número.

Isto quebra o formato `{identity, audience, positioning, social, percent}`. O
único consumidor é o nosso frontend; não há contrato externo.

### Presença, não profundidade

Um passo obrigatório está completo quando **todos** os seus campos estão
preenchidos — não um deles, que é o bug de hoje.

Ainda assim, `differentiators = "ok"` continua marcando `positioning` como
completo. Isto é aceito de propósito. Uma nota que afirme que uma persona de
400 caracteres vale mais que uma de 10 é opinião disfarçada de métrica.

A honestidade que ganhamos é outra, e é verificável: **100% significa "o agente
recebeu todos os campos que lê"**.

### `vocabulary` e `social` são opcionais por motivos diferentes

`competitors`, `required_words` e `forbidden_words` **vão para o modelo**;
`forbidden_words` muda a saída diretamente. `website` e `instagram` não vão a
lugar nenhum.

Se os dois passos aparecerem com o mesmo rótulo "opcional", trocamos uma
mentira por outra: o usuário pularia o vocabulário achando que não influencia
nada. O chip é o mesmo; a dica é diferente.

## Fatia A — backend (`apps/api`)

### As regras, por passo

| Passo | Campos | Obrigatório |
|---|---|---|
| `identity` | `brand_name` **e** `description` | sim |
| `audience` | `audience` **e** `persona` | sim |
| `positioning` | `tone_of_voice` **e** `differentiators` | sim |
| `offer` | `products` **e** `services` (arrays não-vazios) | sim |
| `vocabulary` | qualquer de `competitors`, `required_words`, `forbidden_words` | não |
| `social` | qualquer de `website`, `instagram`, `facebook`, `linkedin`, `tiktok`, `youtube` | não |

O `filled()` do Laravel trata `[]` como vazio, então um array de produtos vazio
não conta — de graça, sem código extra.

**Assimetria conhecida e aceita:** o passo `social` verifica seis colunas de
link, mas o wizard só edita três (`facebook`, `tiktok` e `youtube` têm coluna e
nenhuma tela). Como `social` é opcional e está fora do `percent`, isso não
afeta número nenhum. Não é escopo desta fatia; fica registrado para não ser
redescoberto como bug.

### O que muda

`app/Domain/BrandProfile/Completion.php` inteiro. Mais nada: nenhuma migration,
nenhuma coluna, nenhuma mudança no `UpdateBrandProfileRequest` (já aceita os
onze campos), nenhuma mudança no `AgentContext`.

### Testes

Os três existentes mudam de forma e precisam ser reescritos:
`test_perfil_nasce_vazio_com_zero_por_cento`,
`test_patch_parcial_nao_apaga_os_passos_anteriores`,
`test_wizard_completo_chega_a_cem_por_cento`.

Novos:

- um passo obrigatório com **um** dos dois campos preenchidos não conta
- `products: []` não conta como preenchido
- passos opcionais completos não movem o `percent`
- a ordem dos passos é estável (o `Stepper` depende dela)
- perfil vazio → `percent` 0; os quatro obrigatórios preenchidos → 100

### O contrato resultante

```json
{
  "data": { ...BrandProfileData },
  "completion": {
    "steps": [{ "id": "identity", "complete": true, "required": true }, ...],
    "percent": 50
  }
}
```

## Fatia B — frontend (`apps/web`)

### O tipo de campo `list`

`products`, `services`, `competitors`, `required_words` e `forbidden_words` são
`jsonb`. O wizard é um formulário **não-controlado** por passo, que lê tudo com
`FormData` e remonta a cada troca (`key={step.id}`).

A entrada é um `<textarea>`, **um item por linha**. Nenhum componente novo,
nenhum estado controlado, o `FormData` continua sendo a fonte da verdade.

Toda a conversão vive num módulo puro, `src/lib/listField.ts`:

```ts
/** ['Carros','Motos'] → "Carros\nMotos" */
export function toLines(items: string[] | null | undefined): string

/** "Carros\n \nMotos\n" → ['Carros','Motos'] */
export function fromLines(text: string): string[]
```

`fromLines` corta espaços e descarta linhas vazias — inclusive a última, que
todo mundo deixa ao apertar Enter.

O `Field` do wizard ganha `kind: 'list'`. No `submit`, um campo `list` passa por
`fromLines`; no `defaultValue`, por `toLines`. O tipo do payload alarga para
`Partial<Record<EditableField, string | string[]>>`.

### Os seis passos

| # | `id` | Título | Campos | Rótulo |
|---|---|---|---|---|
| 1 | `identity` | Identidade | `brand_name`, `description` | — |
| 2 | `audience` | Público-Alvo | `audience`, `persona` | — |
| 3 | `positioning` | Posicionamento de Mercado | `tone_of_voice`, `differentiators` | — |
| 4 | `offer` | Oferta | `products` (list), `services` (list) | — |
| 5 | `vocabulary` | Vocabulário e Concorrência | `competitors`, `required_words`, `forbidden_words` (todos list) | opcional |
| 6 | `social` | Links Sociais | `website`, `instagram`, `linkedin` | opcional |

As dicas dos opcionais:

- **Vocabulário**: "Influencia o texto gerado. Palavras proibidas nunca
  aparecerão nas peças."
- **Links Sociais**: "Não afeta a estratégia. Usado na exportação."

### O `Stepper` para de saber a regra

Hoje recebe `completedIds`. Passa a receber também `optionalIds`, e ambos vêm
do servidor — a página apenas fatia a resposta:

```ts
const completedIds = completion.steps.filter((s) => s.complete).map((s) => s.id)
const optionalIds  = completion.steps.filter((s) => !s.required).map((s) => s.id)
```

Nenhuma lista de obrigatoriedade no frontend.

### Tipos

`BrandProfileCompletion` deixa de ser `{identity, audience, positioning, social,
percent}` e passa a ser:

```ts
export interface CompletionStep {
  id: 'identity' | 'audience' | 'positioning' | 'offer' | 'vocabulary' | 'social'
  complete: boolean
  required: boolean
}

export interface BrandProfileCompletion {
  steps: CompletionStep[]
  percent: number
}
```

### Testes

`src/lib/listField.test.ts` — puro, sem DOM:

- ida-e-volta: `fromLines(toLines(xs))` devolve `xs`
- espaços em volta de cada item são cortados
- linhas vazias somem, inclusive a última
- `toLines(null)` devolve `''`

`src/pages/BrandProfilePage.test.tsx`, com MSW:

- um passo `list` renderiza o array como linhas no textarea
- ao salvar, o campo `list` é enviado como **array**, não como string
- passos opcionais mostram o chip; os obrigatórios não
- o `percent` do servidor é exibido tal como veio

### O que fica de fora

Não bloquear a geração de estratégia com perfil abaixo de 100%. O agente sabe
se defender de perfil raso — foi observado gerando uma estratégia conservadora
e explicando o porquê. Travar o botão puniria o usuário por uma decisão que é
dele. **O `percent` informa; não impede.**

## Critério de sucesso

- `php artisan test` verde, com os testes novos do `Completion` e os três
  antigos reescritos.
- `pnpm test` verde, com `listField` e os testes de página.
- No browser: um perfil vazio marca 0%; preencher os quatro passos obrigatórios
  leva a 100%; preencher só os opcionais não move o número.
- O perfil do projeto 1, como estava antes do `PATCH` desta sessão
  (`persona` nula, `products`/`services` vazios), marcaria **50%** — não 100%.
