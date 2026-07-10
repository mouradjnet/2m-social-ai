# Perfil da Marca — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer a completude do Perfil da Marca medir o que o agente lê, e dar tela aos cinco campos que hoje chegam ao modelo sem UI.

**Architecture:** Duas fases. A Fase A reescreve o `Completion` para devolver uma lista de passos autodescritiva (`{steps: [{id, complete, required}], percent}`), medindo os campos que o `AgentContext` envia — o `percent` conta só os obrigatórios. A Fase B acrescenta dois passos ao wizard (`offer` obrigatório, `vocabulary` opcional) usando um campo de lista `textarea`-um-item-por-linha, e faz o `Stepper` receber do servidor quais passos são opcionais.

**Tech Stack:** Backend: PHP 8.4, Laravel 13.19, PostgreSQL 16, PHPUnit. Frontend: React 19, TypeScript 6, Vite 8, TanStack Query v5, Tailwind 4, Vitest + RTL + MSW.

**Spec:** `docs/superpowers/specs/2026-07-10-perfil-da-marca-design.md`

---

## Contexto que o executor precisa

**Working directory:** `C:\Users\mysho\2m-social-ai`. Backend em `apps/api`, frontend em `apps/web`.

**O Postgres não é serviço.** Antes de rodar teste de backend:

```bash
"C:/Users/mysho/bin/pgsql16/bin/pg_ctl.exe" -D "C:/Users/mysho/pgdata/16" -l "C:/Users/mysho/pgdata/pg16.log" -o "-p 5433" start
```

Se já estiver rodando, o comando avisa e não faz mal.

**Nunca rodar `migrate:fresh` no banco `2m_social_ai`** — tem dado real. Testes usam `2m_social_ai_test` (em `phpunit.xml`).

**Backend:** rodar `./vendor/bin/pint <arquivos>` antes de cada commit. O Pint renomeia métodos de teste para snake_case; é esperado.

**Frontend:** o lint é `pnpm exec oxlint`, **não** `pnpm lint` (este ambiente intercepta `pnpm lint` com um wrapper de ESLint inexistente). Dois warnings de `react(only-export-components)` em `src/main.tsx` e `src/test/utils.tsx` são pré-existentes e não bloqueiam (o `oxlint` sai com código 0).

**Nenhuma migration nesta fatia.** As colunas já existem; o `UpdateBrandProfileRequest` e o `$fillable` do `BrandProfile` já aceitam os onze campos.

**Assimetria conhecida (do spec):** o passo `social` do `Completion` verifica seis colunas de link, mas o wizard só edita três (`facebook`/`tiktok`/`youtube` têm coluna e nenhuma tela). Como `social` é opcional e fora do `percent`, não afeta número nenhum — não é escopo desta fatia.

**Decisão sobre `competitors`:** o wizard trata `competitors` como lista de **nomes** (strings, um por linha), igual a `required_words`/`forbidden_words`. A coluna `jsonb` aceita qualquer array, então o teste antigo `test_campos_jsonb_persistem_como_array` (que grava `[{name, url}]` via PATCH direto) continua válido e **não muda**. O `AgentContext` faz `json_encode` de qualquer forma.

---

## Estrutura de arquivos

### Fase A — backend (`apps/api`)

| Arquivo | Responsabilidade |
|---|---|
| `app/Domain/BrandProfile/Completion.php` | **Reescrever.** Devolve `{steps, percent}` |
| `tests/Feature/BrandProfileTest.php` | **Modificar.** Reescrever 3 testes; acrescentar 5 |

### Fase B — frontend (`apps/web`)

| Arquivo | Responsabilidade |
|---|---|
| `src/lib/listField.ts` | **Criar.** `toLines` / `fromLines`, puros |
| `src/lib/listField.test.ts` | **Criar.** Teste unitário |
| `src/lib/types.ts` | **Modificar.** `CompletionStep`, `BrandProfileCompletion` |
| `src/components/ui/Stepper.tsx` | **Modificar.** Prop `optionalIds` |
| `src/pages/BrandProfilePage.tsx` | **Modificar.** 2 passos novos, `kind: 'list'`, fatiar `completion.steps` |
| `src/pages/BrandProfilePage.test.tsx` | **Criar.** Testes de página com MSW |

---

# FASE A — `Completion` autodescritivo

## Task A1: Reescrever o `Completion`

**Files:**
- Modify: `apps/api/app/Domain/BrandProfile/Completion.php`
- Test: `apps/api/tests/Feature/BrandProfileTest.php`

- [ ] **Step 1: Reescrever os três testes que dependem do formato antigo**

Em `apps/api/tests/Feature/BrandProfileTest.php`, **substituir** os três métodos existentes `test_perfil_nasce_vazio_com_zero_por_cento`, `test_patch_parcial_nao_apaga_os_passos_anteriores` e `test_wizard_completo_chega_a_cem_por_cento` por estes. Acrescentar também os cinco novos e um helper. O formato da resposta mudou de `completion.identity` (bool) para `completion.steps` (lista).

Primeiro, um helper para achar um passo pela `id` dentro da lista — colocar logo abaixo do método `actAs`:

```php
    /** Preenche todos os campos de todos os passos obrigatorios: leva a 100%. */
    private function todosObrigatorios(): array
    {
        return [
            'brand_name' => 'Acme',
            'description' => 'Loja de teste',
            'audience' => 'Clinicas de pequeno porte',
            'persona' => 'Dra. Ana, 40 anos, dona de clinica',
            'tone_of_voice' => 'Direto e acolhedor',
            'differentiators' => 'Atendimento humano e rapido',
            'products' => ['Consulta', 'Exame'],
            'services' => ['Agendamento online'],
        ];
    }

    /** Extrai o bloco de um passo da resposta de completude. */
    private function stepFrom(array $completion, string $id): array
    {
        foreach ($completion['steps'] as $step) {
            if ($step['id'] === $id) {
                return $step;
            }
        }

        throw new \RuntimeException("passo {$id} ausente na resposta");
    }
```

Os três reescritos:

```php
    public function test_perfil_nasce_vazio_com_zero_por_cento(): void
    {
        $project = $this->actAs(WorkspaceRole::Viewer);

        $response = $this->getJson("/api/v1/projects/{$project->id}/brand-profile")
            ->assertOk()
            ->assertJsonPath('data.brand_name', null)
            ->assertJsonPath('completion.percent', 0);

        $identity = $this->stepFrom($response->json('completion'), 'identity');
        $this->assertFalse($identity['complete']);
        $this->assertTrue($identity['required']);
    }

    public function test_patch_parcial_nao_apaga_os_passos_anteriores(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // identity exige brand_name E description: com so um, nao completa.
        $r1 = $this->patchJson($url, ['brand_name' => 'Acme', 'description' => 'Loja'])
            ->assertOk()
            ->assertJsonPath('completion.percent', 25);
        $this->assertTrue($this->stepFrom($r1->json('completion'), 'identity')['complete']);

        // O passo audience nao menciona brand_name. Ele deve sobreviver.
        $this->patchJson($url, ['audience' => 'Clinicas', 'persona' => 'Dra. Ana'])
            ->assertOk()
            ->assertJsonPath('data.brand_name', 'Acme')
            ->assertJsonPath('completion.percent', 50);
    }

    public function test_wizard_completo_chega_a_cem_por_cento(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        $this->patchJson($url, $this->todosObrigatorios())
            ->assertOk()
            ->assertJsonPath('completion.percent', 100);
    }
```

Os cinco novos:

```php
    public function test_passo_obrigatorio_com_so_um_campo_nao_completa(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // audience exige audience E persona.
        $response = $this->patchJson($url, ['audience' => 'Clinicas'])->assertOk();

        $this->assertFalse($this->stepFrom($response->json('completion'), 'audience')['complete']);
        $this->assertSame(0, $response->json('completion.percent'));
    }

    public function test_array_vazio_nao_conta_como_preenchido(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // offer exige products E services nao-vazios.
        $response = $this->patchJson($url, ['products' => [], 'services' => []])->assertOk();

        $this->assertFalse($this->stepFrom($response->json('completion'), 'offer')['complete']);
    }

    public function test_offer_completa_com_products_e_services(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        $response = $this->patchJson($url, [
            'products' => ['Carros'],
            'services' => ['Financiamento'],
        ])->assertOk();

        $this->assertTrue($this->stepFrom($response->json('completion'), 'offer')['complete']);
        $this->assertSame(25, $response->json('completion.percent'));
    }

    public function test_passos_opcionais_nao_movem_o_percent(): void
    {
        $project = $this->actAs(WorkspaceRole::Editor);
        $url = "/api/v1/projects/{$project->id}/brand-profile";

        // Preenche so os dois opcionais: vocabulary e social.
        $response = $this->patchJson($url, [
            'forbidden_words' => ['imperdivel'],
            'website' => 'https://acme.com.br',
        ])->assertOk();

        $completion = $response->json('completion');
        $this->assertSame(0, $completion['percent']);
        $this->assertTrue($this->stepFrom($completion, 'vocabulary')['complete']);
        $this->assertTrue($this->stepFrom($completion, 'social')['complete']);
        $this->assertFalse($this->stepFrom($completion, 'vocabulary')['required']);
        $this->assertFalse($this->stepFrom($completion, 'social')['required']);
    }

    public function test_a_ordem_dos_passos_e_estavel(): void
    {
        $project = $this->actAs(WorkspaceRole::Viewer);

        $response = $this->getJson("/api/v1/projects/{$project->id}/brand-profile")->assertOk();

        $ids = array_column($response->json('completion.steps'), 'id');
        $this->assertSame(
            ['identity', 'audience', 'positioning', 'offer', 'vocabulary', 'social'],
            $ids,
        );
    }
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/api && php artisan test --filter BrandProfileTest
```

Esperado: FAIL. Os testes que leem `completion.steps` quebram porque hoje a resposta tem `completion.identity` (bool), não `completion.steps`.

- [ ] **Step 3: Reescrever o `Completion`**

Substituir `apps/api/app/Domain/BrandProfile/Completion.php` inteiro:

```php
<?php

namespace App\Domain\BrandProfile;

use App\Models\BrandProfile;

/**
 * O servidor decide quais passos existem, quais estao completos e quais sao
 * obrigatorios; o Stepper do frontend so desenha. Se a regra vivesse no cliente,
 * duas telas discordariam sobre o mesmo perfil.
 *
 * Mede PRESENCA dos campos que o AgentContext envia ao modelo, nao profundidade:
 * `differentiators = "ok"` conta. Peso por campo seria opiniao disfarcada de
 * metrica. A honestidade e outra: 100% = o agente recebeu todos os campos que le.
 *
 * `percent` conta so os passos obrigatorios. Os opcionais aparecem e informam se
 * estao preenchidos, mas nunca movem o numero.
 */
class Completion
{
    /**
     * @return array{
     *   steps: list<array{id: string, complete: bool, required: bool}>,
     *   percent: int
     * }
     */
    public static function for(BrandProfile $profile): array
    {
        $steps = [
            self::step('identity', true, filled($profile->brand_name) && filled($profile->description)),
            self::step('audience', true, filled($profile->audience) && filled($profile->persona)),
            self::step('positioning', true, filled($profile->tone_of_voice) && filled($profile->differentiators)),
            self::step('offer', true, filled($profile->products) && filled($profile->services)),
            self::step('vocabulary', false, filled($profile->competitors)
                || filled($profile->required_words)
                || filled($profile->forbidden_words)),
            self::step('social', false, filled($profile->website)
                || filled($profile->instagram)
                || filled($profile->facebook)
                || filled($profile->linkedin)
                || filled($profile->tiktok)
                || filled($profile->youtube)),
        ];

        $required = array_filter($steps, fn ($s) => $s['required']);
        $done = array_filter($required, fn ($s) => $s['complete']);

        return [
            'steps' => $steps,
            'percent' => (int) round(count($done) / count($required) * 100),
        ];
    }

    /** @return array{id: string, complete: bool, required: bool} */
    private static function step(string $id, bool $required, bool $complete): array
    {
        return ['id' => $id, 'complete' => $complete, 'required' => $required];
    }
}
```

- [ ] **Step 4: Rodar para ver passar**

```bash
cd apps/api && php artisan test --filter BrandProfileTest
```

Esperado: PASS. Todos os testes do `BrandProfileTest`.

- [ ] **Step 5: Falsificar `test_array_vazio_nao_conta`**

O `filled([])` do Laravel é o que faz um array vazio não contar. Confirmar que o teste o exige: em `Completion.php`, trocar `filled($profile->products)` por `$profile->products !== null` (que aceitaria `[]`).

```bash
cd apps/api && php artisan test --filter test_array_vazio_nao_conta_como_preenchido
```

Esperado: FAIL — `offer` completaria com arrays vazios. **Desfazer** (voltar para `filled($profile->products)`) e rodar de novo para confirmar PASS.

- [ ] **Step 6: Suíte inteira, formatar, commitar**

```bash
cd apps/api && php artisan test
./vendor/bin/pint app/Domain/BrandProfile/Completion.php tests/Feature/BrandProfileTest.php
cd ../.. && git add apps/api/app/Domain/BrandProfile/Completion.php apps/api/tests/Feature/BrandProfileTest.php
git commit -m "Completion mede o que o agente le, e se descreve

Antes: cada passo avaliado por UM campo (identity = brand_name preenchido), e
o resultado eram booleanos soltos. Um perfil com persona nula, differentiators
= 'ok' e products/services vazios marcava 100%.

Agora: cada passo obrigatorio exige TODOS os seus campos; a resposta e uma lista
de passos autodescritiva ({id, complete, required}); o percent conta so os
obrigatorios. Mede presenca, nao profundidade — de proposito.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

# FASE B — os dois passos novos no wizard

## Task B1: O módulo `listField`

**Files:**
- Create: `apps/web/src/lib/listField.ts`
- Test: `apps/web/src/lib/listField.test.ts`

- [ ] **Step 1: Escrever o teste que falha**

`apps/web/src/lib/listField.test.ts`:

```ts
import { expect, test } from 'vitest'
import { fromLines, toLines } from './listField'

test('toLines junta os itens com quebra de linha', () => {
  expect(toLines(['Carros', 'Motos'])).toBe('Carros\nMotos')
})

test('toLines de null ou undefined devolve string vazia', () => {
  expect(toLines(null)).toBe('')
  expect(toLines(undefined)).toBe('')
})

test('fromLines corta espacos e descarta linhas vazias, inclusive a ultima', () => {
  expect(fromLines('Carros\n  Motos  \n\nCaminhões\n')).toEqual(['Carros', 'Motos', 'Caminhões'])
})

test('fromLines de string vazia devolve array vazio', () => {
  expect(fromLines('')).toEqual([])
  expect(fromLines('   \n  \n')).toEqual([])
})

test('ida-e-volta preserva a lista', () => {
  const xs = ['Compra Segura', 'Negociação e Troca', 'Financiamento']
  expect(fromLines(toLines(xs))).toEqual(xs)
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test listField
```

Esperado: FAIL, `Failed to resolve import "./listField"`.

- [ ] **Step 3: Implementar**

`apps/web/src/lib/listField.ts`:

```ts
/**
 * Ponte entre o campo `jsonb` (array de strings) e o <textarea> do wizard,
 * um item por linha. Sem DOM, sem React: so string <-> array.
 */

/** ['Carros','Motos'] → "Carros\nMotos" */
export function toLines(items: string[] | null | undefined): string {
  return (items ?? []).join('\n')
}

/** "Carros\n \nMotos\n" → ['Carros','Motos'] — corta espacos, descarta vazias. */
export function fromLines(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
}
```

- [ ] **Step 4: Rodar para ver passar**

```bash
cd apps/web && pnpm test listField
```

Esperado: PASS, 5 testes.

- [ ] **Step 5: Commitar**

```bash
cd ../.. && git add apps/web/src/lib/listField.ts apps/web/src/lib/listField.test.ts
git commit -m "listField: ponte textarea <-> array de strings

Um item por linha. fromLines corta espacos e descarta vazias, inclusive a
ultima linha que todo mundo deixa ao apertar Enter.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B2: O wizard com os dois passos novos

Esta tarefa muda tipos, `Stepper`, `STEPS` e a página juntos — mudar o tipo `BrandProfileCompletion` sozinho quebraria a compilação da página. Os testes de página guiam a mudança.

**Files:**
- Modify: `apps/web/src/lib/types.ts`
- Modify: `apps/web/src/components/ui/Stepper.tsx`
- Modify: `apps/web/src/pages/BrandProfilePage.tsx`
- Test: `apps/web/src/pages/BrandProfilePage.test.tsx`

- [ ] **Step 1: Escrever os testes de página que falham**

`apps/web/src/pages/BrandProfilePage.test.tsx`:

```tsx
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { BrandProfileResponse } from '@/lib/types'
import { BrandProfilePage } from './BrandProfilePage'

const ROUTE = { path: '/projects/:projectId/brand-profile', entry: '/projects/1/brand-profile' }

function completion(percent: number) {
  return {
    percent,
    steps: [
      { id: 'identity', complete: true, required: true },
      { id: 'audience', complete: false, required: true },
      { id: 'positioning', complete: false, required: true },
      { id: 'offer', complete: false, required: true },
      { id: 'vocabulary', complete: false, required: false },
      { id: 'social', complete: false, required: false },
    ],
  }
}

function profile(overrides: Partial<BrandProfileResponse['data']> = {}): BrandProfileResponse {
  return {
    data: {
      id: 1,
      project_id: 1,
      brand_name: '2F AutoShop',
      description: null,
      audience: null,
      persona: null,
      tone_of_voice: null,
      differentiators: null,
      products: ['Carros', 'Motos'],
      services: null,
      website: null,
      instagram: null,
      linkedin: null,
      ...overrides,
    } as BrandProfileResponse['data'],
    completion: completion(25),
  }
}

test('o percent vem do servidor e aparece no cabeçalho', async () => {
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  expect(await screen.findByText(/25% completo/i)).toBeInTheDocument()
})

test('o passo Oferta renderiza um array como linhas no textarea', async () => {
  const user = userEvent.setup()
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /oferta/i }))

  const produtos = screen.getByRole('textbox', { name: /produtos/i })
  expect(produtos).toHaveValue('Carros\nMotos')
})

test('ao salvar o passo Oferta, o campo de lista vai como array', async () => {
  const user = userEvent.setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
    http.patch('/api/v1/projects/1/brand-profile', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json(profile())
    }),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /oferta/i }))

  const servicos = screen.getByRole('textbox', { name: /serviços/i })
  await user.clear(servicos)
  await user.type(servicos, 'Compra Segura\nFinanciamento')

  await user.click(screen.getByRole('button', { name: /salvar|próximo/i }))

  await waitFor(() => expect(recebido).not.toBeNull())
  expect(recebido).toMatchObject({
    products: ['Carros', 'Motos'],
    services: ['Compra Segura', 'Financiamento'],
  })
})

test('o Stepper marca os passos opcionais', async () => {
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  const nav = await screen.findByRole('navigation', { name: /progresso/i })
  // Vocabulário e Links Sociais sao os opcionais; devem trazer a marca "opcional".
  expect(within(nav).getByText(/vocabulário e concorrência/i)).toBeInTheDocument()
  const opcionais = within(nav).getAllByText(/opcional/i)
  expect(opcionais).toHaveLength(2)
})

test('os dois passos opcionais têm dicas diferentes', async () => {
  const user = userEvent.setup()
  server.use(
    http.get('/api/v1/projects/1/brand-profile', () => HttpResponse.json(profile())),
  )

  renderWithProviders(<BrandProfilePage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /vocabulário e concorrência/i }))
  expect(screen.getByText(/influencia o texto gerado/i)).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: /links sociais/i }))
  expect(screen.getByText(/não afeta a estratégia/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test BrandProfilePage
```

Esperado: FAIL. A página ainda usa `completion.identity` e não tem o passo `offer`; o `pnpm test` compila via esbuild, então erros de tipo aparecem como falhas de runtime/resolução.

- [ ] **Step 3: Reescrever os tipos de completude**

Em `apps/web/src/lib/types.ts`, **substituir** o bloco de `BrandProfileCompletion`:

```ts
export interface CompletionStep {
  id: 'identity' | 'audience' | 'positioning' | 'offer' | 'vocabulary' | 'social'
  complete: boolean
  required: boolean
}

/** Quem decide o que esta completo e o servidor. O Stepper so desenha. */
export interface BrandProfileCompletion {
  steps: CompletionStep[]
  percent: number
}
```

E acrescentar os cinco campos de lista ao `BrandProfileData` (logo depois de `differentiators`):

```ts
  differentiators: string | null
  products: string[] | null
  services: string[] | null
  competitors: string[] | null
  required_words: string[] | null
  forbidden_words: string[] | null
```

- [ ] **Step 4: O `Stepper` recebe `optionalIds`**

Em `apps/web/src/components/ui/Stepper.tsx`, na interface e na assinatura:

```tsx
interface StepperProps {
  steps: Step[]
  currentId: string
  /** Vem do campo `completion` da API: um passo pode estar completo fora de ordem. */
  completedIds?: string[]
  /** Passos que a API marcou como nao-obrigatorios; ganham a marca "opcional". */
  optionalIds?: string[]
  onSelect?: (id: string) => void
}

export function Stepper({ steps, currentId, completedIds = [], optionalIds = [], onSelect }: StepperProps) {
```

Dentro do `.map`, logo depois de `const isComplete = ...`:

```tsx
          const isOptional = optionalIds.includes(step.id)
```

E no bloco do `<span>` do `step.title`, trocar por título + marca:

```tsx
                <span
                  className={cn(
                    'block text-body-sm',
                    isCurrent ? 'text-on-surface' : 'text-on-surface-variant',
                  )}
                >
                  {step.title}
                  {isOptional && (
                    <span className="text-label-sm text-on-surface-variant"> · opcional</span>
                  )}
                </span>
```

- [ ] **Step 5: Reescrever o `BrandProfilePage`**

Em `apps/web/src/pages/BrandProfilePage.tsx`:

Trocar o import de tipos e acrescentar o `listField`:

```tsx
import { fromLines, toLines } from '@/lib/listField'
import type { BrandProfileResponse } from '@/lib/types'
```

Alargar o tipo do array `STEPS` para aceitar uma descrição por passo (hoje é `Array<Step & { fields: Field[] }>`):

```tsx
const STEPS: Array<Step & { fields: Field[]; description?: string }> = [
```

(mantendo o restante do array; os campos `description` entram nos passos abaixo.)

Alargar `EditableField` e `Field`:

```tsx
type EditableField =
  | 'brand_name'
  | 'description'
  | 'audience'
  | 'persona'
  | 'tone_of_voice'
  | 'differentiators'
  | 'products'
  | 'services'
  | 'competitors'
  | 'required_words'
  | 'forbidden_words'
  | 'website'
  | 'instagram'
  | 'linkedin'

type Field = {
  name: EditableField
  label: string
  kind: 'text' | 'textarea' | 'list'
  placeholder?: string
  hint?: string
}
```

Acrescentar `offer` e `vocabulary` ao array `STEPS`, entre `positioning` e `social`:

```tsx
  {
    id: 'offer',
    label: 'Passo 4',
    title: 'Oferta',
    fields: [
      { name: 'products', label: 'Produtos', kind: 'list', hint: 'Um por linha' },
      { name: 'services', label: 'Serviços', kind: 'list', hint: 'Um por linha' },
    ],
  },
  {
    id: 'vocabulary',
    label: 'Passo 5',
    title: 'Vocabulário e Concorrência',
    description: 'Influencia o texto gerado. Palavras proibidas nunca aparecerão nas peças.',
    fields: [
      { name: 'competitors', label: 'Concorrentes', kind: 'list', hint: 'Um por linha' },
      { name: 'required_words', label: 'Palavras obrigatórias', kind: 'list', hint: 'Uma por linha' },
      { name: 'forbidden_words', label: 'Palavras proibidas', kind: 'list', hint: 'Uma por linha' },
    ],
  },
```

E renumerar o passo social para `Passo 6`, com a dica que o distingue do vocabulário:

```tsx
  {
    id: 'social',
    label: 'Passo 6',
    title: 'Links Sociais',
    description: 'Não afeta a estratégia. Usado na exportação.',
    fields: [
      { name: 'website', label: 'Site', kind: 'text', hint: 'Precisa começar com https://' },
      { name: 'instagram', label: 'Instagram', kind: 'text' },
      { name: 'linkedin', label: 'LinkedIn', kind: 'text' },
    ],
  },
```

Dentro de `BrandProfilePage`, trocar a derivação de `completedIds` e acrescentar `optionalIds`:

```tsx
  const completedIds = completion.steps.filter((s) => s.complete).map((s) => s.id)
  const optionalIds = completion.steps.filter((s) => !s.required).map((s) => s.id)
```

Trocar o tipo da mutação e do payload (o payload agora carrega string OU array):

```tsx
  const save = useMutation({
    mutationFn: (payload: Partial<Record<EditableField, string | string[]>>) =>
      api<BrandProfileResponse>(`/projects/${projectId}/brand-profile`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (response) => queryClient.setQueryData(queryKey, response),
  })
```

Reescrever o `submit` para converter campos `list`:

```tsx
  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    // Envia apenas os campos deste passo: o PATCH e um merge parcial.
    // Campos `list` viram array de strings; o resto vai como texto.
    const payload = Object.fromEntries(
      step.fields.map((field) => {
        const raw = String(form.get(field.name) ?? '')
        return [field.name, field.kind === 'list' ? fromLines(raw) : raw]
      }),
    ) as Partial<Record<EditableField, string | string[]>>

    save.mutate(payload, {
      onSuccess: () => {
        if (nextStep) setCurrentId(nextStep.id)
      },
    })
  }
```

Trocar a `CardDescription` fixa (hoje `Seja conciso; vamos expandir isso mais tarde.`) pela descrição do passo, com aquela como fallback. É o que dá aos dois passos opcionais dicas diferentes — a decisão da Seção 2 do spec:

```tsx
            <CardDescription>
              {step.description ?? 'Seja conciso; vamos expandir isso mais tarde.'}
            </CardDescription>
```

Passar `optionalIds` ao `Stepper`:

```tsx
          <Stepper
            steps={STEPS}
            currentId={currentId}
            completedIds={completedIds}
            optionalIds={optionalIds}
            onSelect={setCurrentId}
          />
```

E no `.map` dos campos do formulário, converter o `defaultValue` de um campo `list` e renderizá-lo como `Textarea`:

```tsx
            {step.fields.map((field) => {
              const value = data[field.name]
              const props = {
                name: field.name,
                label: field.label,
                placeholder: field.placeholder,
                hint: field.hint,
                defaultValue: field.kind === 'list' ? toLines(value as string[] | null) : (value ?? ''),
                error: error?.fieldError(field.name),
              }

              return field.kind === 'textarea' || field.kind === 'list' ? (
                <Textarea key={field.name} {...props} />
              ) : (
                <Input key={field.name} {...props} />
              )
            })}
```

- [ ] **Step 6: Rodar para ver passar**

```bash
cd apps/web && pnpm test BrandProfilePage
```

Esperado: PASS, 5 testes.

- [ ] **Step 7: Suíte, build, lint**

```bash
cd apps/web && pnpm test && pnpm build && pnpm exec oxlint
```

Esperado: todos os testes verdes; build sem erro; `oxlint` só com os dois warnings pré-existentes.

- [ ] **Step 8: Commitar**

```bash
cd ../.. && git add apps/web/src/lib/types.ts apps/web/src/components/ui/Stepper.tsx apps/web/src/pages/BrandProfilePage.tsx apps/web/src/pages/BrandProfilePage.test.tsx
git commit -m "Wizard: passos Oferta e Vocabulario, e completude vinda do servidor

Cinco campos que o AgentContext envia ao modelo (products, services,
competitors, required_words, forbidden_words) ganham tela, via campo de lista
textarea-um-item-por-linha. O Stepper recebe do servidor quais passos sao
opcionais em vez de deduzir no cliente.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B3: Verificação no browser

Não produz commit.

- [ ] **Step 1: Subir o Postgres, se preciso**

```bash
"C:/Users/mysho/bin/pgsql16/bin/pg_ctl.exe" -D "C:/Users/mysho/pgdata/16" -l "C:/Users/mysho/pgdata/pg16.log" -o "-p 5433" start
```

- [ ] **Step 2: Subir a API e o Vite**

Em dois terminais:

```bash
cd apps/api && php artisan serve
cd apps/web && pnpm dev
```

- [ ] **Step 3: Percorrer**

Logar em http://localhost:5173, entrar no projeto 1, ir ao Perfil da Marca. Verificar:

1. O cabeçalho mostra o percent real (o projeto 1 hoje, com `products`/`services` preenchidos pelo PATCH da sessão anterior, deve estar em 100%; se o banco foi limpo, menos).
2. O Stepper mostra seis passos; **Vocabulário e Concorrência** e **Links Sociais** trazem "· opcional".
3. O passo **Oferta** mostra `products` e `services` como linhas num textarea.
4. Editar uma lista, salvar, recarregar (F5): as linhas persistem.
5. Preencher só um passo opcional **não** move o percent; preencher um obrigatório move.

---

## Critério de sucesso

- `cd apps/api && php artisan test` → verde, com os 3 reescritos + 5 novos.
- `cd apps/web && pnpm test` → verde, com `listField` (5) e `BrandProfilePage` (5).
- `cd apps/web && pnpm build && pnpm exec oxlint` → limpo (só os 2 warnings pré-existentes).
- No browser: percent do servidor no cabeçalho; passos opcionais marcados; campo de lista ida-e-volta.
- `git status --porcelain` vazio ao fim.
