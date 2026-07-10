# Tela de Estratégia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar o laço da Fase 3 no produto — o usuário gera uma estratégia editorial com IA, acompanha a execução assíncrona, e aprova o rascunho.

**Architecture:** Duas fases sequenciais. A Fase A persiste em `ai_runs` a classificação da falha que o `RunAgentJob` já conhece mas descarta (`error_code`), porque a tela precisa distinguir uma recusa do modelo — onde insistir é dano — de uma falha transiente. A Fase B constrói a tela sobre um hook que é o único dono da URL, do polling e da mutação; os componentes abaixo dele são funções de props para JSX. O `ai_run_id` vive na query string (`?run=42`), não em estado do React, para que uma recusa sobreviva a um reload.

**Tech Stack:** Backend: PHP 8.4, Laravel 13.19, PostgreSQL 16, PHPUnit. Frontend: React 19, TypeScript 6, Vite 8, TanStack Query v5, React Router 7, Tailwind 4. Infra de teste do frontend (nova): Vitest, jsdom, Testing Library, MSW v2.

**Spec:** `docs/superpowers/specs/2026-07-10-tela-estrategia-design.md`

---

## Contexto que o executor precisa

**Working directory:** `C:\Users\mysho\2m-social-ai`. Backend em `apps/api`, frontend em `apps/web`. Branch `main`.

**O Postgres não é serviço.** Antes de rodar qualquer teste do backend:

```bash
"C:/Users/mysho/bin/pgsql16/bin/pg_ctl.exe" -D "C:/Users/mysho/pgdata/16" -l "C:/Users/mysho/pgdata/pg16.log" -o "-p 5433" start
```

Se já estiver rodando, o comando avisa e não faz mal.

**Nunca rodar `migrate:fresh` no banco `2m_social_ai`** — tem dado real. Os testes usam `2m_social_ai_test`, configurado em `phpunit.xml`, e podem ser zerados à vontade.

**`AI_PROVIDER=mock` e `QUEUE_CONNECTION=sync`** estão no `phpunit.xml`. Isso significa que, nos testes, o `RunAgentJob` roda **dentro da própria requisição HTTP** — as asserções sobre `ai_runs` valem logo depois do `postJson`. Nenhum teste chama a API da Anthropic.

**Formatação:** rodar `./vendor/bin/pint <arquivos>` antes de cada commit do backend. O Pint renomeia métodos de teste para snake_case (`php_unit_method_casing`); é esperado.

---

## Estrutura de arquivos

### Fase A — backend (`apps/api`)

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/2026_07_10_020000_add_error_code_to_ai_runs_table.php` | **Criar.** Coluna `error_code` |
| `app/Models/AiRun.php` | **Modificar.** `error_code` no `$fillable` |
| `app/Jobs/RunAgentJob.php` | **Modificar.** `errorCodeFor()` + gravar no `catch` |
| `app/Http/Controllers/Api/V1/AiRunController.php` | **Modificar.** Expor `error_code` |
| `tests/Feature/StrategyGenerationTest.php` | **Modificar.** Quatro casos novos |

### Fase B — frontend (`apps/web`)

| Arquivo | Responsabilidade |
|---|---|
| `vite.config.ts` | **Modificar.** Bloco `test` do Vitest |
| `package.json` | **Modificar.** Dependências e script `test` |
| `src/test/setup.ts` | **Criar.** jsdom + jest-dom + ciclo do MSW |
| `src/test/server.ts` | **Criar.** Servidor MSW e handlers padrão |
| `src/test/utils.tsx` | **Criar.** `renderWithProviders` (QueryClient novo por teste) |
| `src/components/ui/Shell.tsx` | **Criar.** Extração das duas cópias |
| `src/lib/types.ts` | **Modificar.** `Pillar`, `Strategy`, `AiRun`, `AiRunErrorCode` |
| `src/components/strategy/Pillars.tsx` | **Criar.** Apresentacional puro |
| `src/components/strategy/StrategyCard.tsx` | **Criar.** Apresentacional puro + callbacks |
| `src/components/strategy/GenerationStatus.tsx` | **Criar.** Desenha o `GenerationState` |
| `src/hooks/useStrategyGeneration.ts` | **Criar.** Único dono de URL, polling e mutação |
| `src/pages/StrategyPage.tsx` | **Criar.** Composição + query da lista + mutação de status |
| `src/main.tsx` | **Modificar.** Rota `/projects/:projectId/strategy` |
| `src/pages/ProjectsPage.tsx` | **Modificar.** Importar `Shell` extraído |
| `src/pages/BrandProfilePage.tsx` | **Modificar.** Importar `Shell` extraído + link para a tela |

---

# FASE A — `error_code` em `ai_runs`

## Task A1: Coluna `error_code` e exposição no endpoint

**Files:**
- Create: `apps/api/database/migrations/2026_07_10_020000_add_error_code_to_ai_runs_table.php`
- Modify: `apps/api/app/Models/AiRun.php`
- Modify: `apps/api/app/Http/Controllers/Api/V1/AiRunController.php`
- Test: `apps/api/tests/Feature/StrategyGenerationTest.php`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `apps/api/tests/Feature/StrategyGenerationTest.php`, dentro da classe:

```php
    public function test_execucao_bem_sucedida_nao_tem_error_code(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('succeeded', $run->status);

        // assertDatabaseHas, e nao assertNull($run->error_code): o Eloquent
        // devolve null para atributo inexistente, sem erro. Este assert toca a
        // coluna no SQL, entao falha de verdade enquanto ela nao existir.
        $this->assertDatabaseHas('ai_runs', ['id' => $run->id, 'error_code' => null]);
    }

    public function test_polling_expoe_o_error_code(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $run = AiRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'agent' => 'strategist',
            'provider' => 'mock',
            'model' => 'claude-opus-4-8',
            'status' => 'failed',
            'input' => [],
            'error' => 'O modelo recusou esta requisicao.',
            'error_code' => 'refused',
            'created_by' => $editor->id,
        ]);

        Sanctum::actingAs($editor);

        $this->getJson("/api/v1/ai-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('error_code', 'refused');
    }
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/api && php artisan test --filter "error_code"
```

Esperado: FAIL, 2 testes. Ambos param em `SQLSTATE[42703]: Undefined column: ... column "error_code" ... does not exist` — o primeiro no `assertDatabaseHas`, o segundo no `AiRun::create`.

- [ ] **Step 3: Criar a migration**

`apps/api/database/migrations/2026_07_10_020000_add_error_code_to_ai_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classificacao da falha, para a UI decidir se insistir e util. `null` no
     * sucesso: o codigo so e escrito no `catch`, entao `null` significa "nao
     * falhou", nunca "falhou por motivo desconhecido".
     *
     * Sem `->after()`: e modificador exclusivo do MySQL e o Postgres o ignora.
     */
    public const CODES = ['refused', 'rejected_output', 'provider_failed'];

    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->enum('error_code', self::CODES)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('error_code');
        });
    }
};
```

- [ ] **Step 4: Adicionar ao `$fillable`**

Em `apps/api/app/Models/AiRun.php`, trocar a linha do `$fillable` que termina em `'error', 'created_by',`:

```php
    protected $fillable = [
        'workspace_id', 'project_id', 'agent', 'provider', 'model', 'status',
        'input', 'output', 'input_tokens', 'output_tokens', 'cache_read_tokens',
        'cache_write_tokens', 'cost_cents', 'latency_ms', 'error', 'error_code',
        'created_by',
    ];
```

- [ ] **Step 5: Expor no controller**

Em `apps/api/app/Http/Controllers/Api/V1/AiRunController.php`, acrescentar a chave logo depois de `'error'`:

```php
            // Mensagem legivel, nunca stack trace.
            'error' => $aiRun->error,
            // Classificacao da falha: a UI decide por ela, nunca pela prosa.
            'error_code' => $aiRun->error_code,
```

- [ ] **Step 6: Rodar para ver passar**

```bash
cd apps/api && php artisan test --filter "error_code"
```

Esperado: PASS, 2 testes.

- [ ] **Step 7: Rodar a suíte inteira**

```bash
cd apps/api && php artisan test
```

Esperado: PASS, 49 testes (47 + 2).

- [ ] **Step 8: Formatar e commitar**

```bash
cd apps/api && ./vendor/bin/pint database/migrations app/Models/AiRun.php app/Http/Controllers/Api/V1/AiRunController.php tests/Feature/StrategyGenerationTest.php
cd ../.. && git add apps/api/database/migrations apps/api/app/Models/AiRun.php apps/api/app/Http/Controllers/Api/V1/AiRunController.php apps/api/tests/Feature/StrategyGenerationTest.php
git commit -m "Adiciona coluna error_code em ai_runs e expoe no polling

A coluna nasce nullable: o codigo so e escrito no catch do RunAgentJob,
entao null significa 'nao falhou', nunca 'falhou por motivo desconhecido'.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task A2: O job classifica recusa e falha do provedor

**Files:**
- Modify: `apps/api/app/Jobs/RunAgentJob.php`
- Test: `apps/api/tests/Feature/StrategyGenerationTest.php`

- [ ] **Step 1: Escrever os testes que falham**

Primeiro, acrescentar os imports que faltam no topo de `StrategyGenerationTest.php`:

```php
use App\Ai\Exceptions\LlmFailedException;
```

Depois, dentro da classe, um helper e dois testes:

```php
    /**
     * Liga um provider fake no container, no lugar do MockProvider.
     * O closure devolve a MESMA instancia — testes que contam chamadas dependem
     * disso (ver test_saida_fora_das_regras...).
     */
    private function bindProvider(LlmProvider $provider): void
    {
        $this->app->bind(LlmProvider::class, fn () => $provider);
    }

    public function test_recusa_do_modelo_grava_error_code_refused(): void
    {
        $this->bindProvider(new class implements LlmProvider
        {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new LlmRefusedException;
            }
        });

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('refused', $run->error_code);
    }

    public function test_falha_do_provedor_grava_error_code_provider_failed(): void
    {
        $this->bindProvider(new class implements LlmProvider
        {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new LlmFailedException('a rede caiu');
            }
        });

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_failed', $run->error_code);
        // A mensagem crua do provedor nunca vaza para o usuario.
        $this->assertStringNotContainsString('a rede caiu', $run->error);
    }
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/api && php artisan test --filter "grava_error_code"
```

Esperado: FAIL, 2 testes. `Failed asserting that null is identical to 'refused'` — o job não grava o código.

- [ ] **Step 3: Implementar `errorCodeFor()` no job**

Em `apps/api/app/Jobs/RunAgentJob.php`, acrescentar o método logo **acima** de `userFacingMessage()`:

```php
    /**
     * Irmao de userFacingMessage(). Fazem `match` na mesma coisa de proposito:
     * um responde a um humano, o outro a uma maquina. A mensagem vai mudar de
     * redacao; o codigo nunca pode mudar.
     */
    private function errorCodeFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof LlmRefusedException => 'refused',
            $e instanceof OutputRejectedException => 'rejected_output',
            default => 'provider_failed',
        };
    }
```

- [ ] **Step 4: Gravar o código no `catch`**

No mesmo arquivo, no bloco `catch (Throwable $e)` do `handle()`, acrescentar a chave:

```php
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => $this->userFacingMessage($e),
                'error_code' => $this->errorCodeFor($e),
                'latency_ms' => $this->elapsedMs($startedAt),
            ]);

            return;
        }
```

- [ ] **Step 5: Rodar para ver passar**

```bash
cd apps/api && php artisan test --filter "grava_error_code"
```

Esperado: PASS, 2 testes.

- [ ] **Step 6: Formatar e commitar**

```bash
cd apps/api && ./vendor/bin/pint app/Jobs/RunAgentJob.php tests/Feature/StrategyGenerationTest.php
cd ../.. && git add apps/api/app/Jobs/RunAgentJob.php apps/api/tests/Feature/StrategyGenerationTest.php
git commit -m "RunAgentJob classifica a falha em error_code

O job ja sabia qual falha ocorreu — fazia match na classe da excecao para
montar a mensagem — e jogava a informacao fora. Agora persiste, para que a
UI decida se insistir e util sem comparar prosa em portugues.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task A3: Saída rejeitada, e a primeira cobertura do laço de retentativa

**Files:**
- Test: `apps/api/tests/Feature/StrategyGenerationTest.php`

O `generateAndValidate()` do job tenta duas vezes quando o modelo devolve JSON válido mas fora das regras de domínio. Esse laço nunca teve teste. Este caso cobre os dois: o código `rejected_output` e o fato de que o provider é chamado exatamente duas vezes.

- [ ] **Step 1: Escrever o teste que falha**

Em `StrategyGenerationTest.php`, dentro da classe:

```php
    public function test_saida_fora_das_regras_e_rejeitada_depois_de_duas_tentativas(): void
    {
        // Pilares somando 99: passa no JSON Schema, falha no validate() do agente.
        $provider = new class implements LlmProvider
        {
            public int $chamadas = 0;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->chamadas++;

                return new LlmResponse(
                    output: [
                        'title' => 't',
                        'summary' => 's',
                        'editorial_line' => 'e',
                        'pillars' => [
                            ['name' => 'a', 'weight' => 40, 'description' => 'd'],
                            ['name' => 'b', 'weight' => 35, 'description' => 'd'],
                            ['name' => 'c', 'weight' => 24, 'description' => 'd'],
                        ],
                    ],
                    model: 'claude-opus-4-8',
                    inputTokens: 10,
                    outputTokens: 10,
                );
            }
        };

        $this->bindProvider($provider);

        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($editor);

        $response = $this->generate($project)->assertStatus(202);
        $run = AiRun::withoutGlobalScopes()->findOrFail($response->json('ai_run_id'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('rejected_output', $run->error_code);
        $this->assertStringContainsString('A soma dos pesos deve ser 100', $run->error);

        // O job tenta uma segunda vez antes de desistir.
        $this->assertSame(2, $provider->chamadas);

        // Nada foi persistido: a estrategia so nasce depois do validate().
        $this->assertSame(0, Strategy::withoutGlobalScopes()->count());
    }
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/api && php artisan test --filter "saida_fora_das_regras"
```

Esperado: FAIL. `Failed asserting that null is identical to 'rejected_output'`.

Se falhar em `$provider->chamadas` sendo `1`, o `bindProvider` está devolvendo uma instância nova por resolução — confirme que o closure é `fn () => $provider` (captura a mesma instância) e não `fn () => new ...`.

- [ ] **Step 3: Nenhuma implementação nova**

Este teste passa apenas com o `errorCodeFor()` da Task A2 — `OutputRejectedException` já cai no braço `rejected_output`. Se ele falhar aqui, a Task A2 está incompleta.

- [ ] **Step 4: Rodar para ver passar**

```bash
cd apps/api && php artisan test --filter "saida_fora_das_regras"
```

Esperado: PASS, 1 teste.

- [ ] **Step 5: Rodar a suíte inteira**

```bash
cd apps/api && php artisan test
```

Esperado: PASS, 52 testes.

- [ ] **Step 6: Formatar e commitar**

```bash
cd apps/api && ./vendor/bin/pint tests/Feature/StrategyGenerationTest.php
cd ../.. && git add apps/api/tests/Feature/StrategyGenerationTest.php
git commit -m "Cobre o laco de retentativa do RunAgentJob

Quando o modelo devolve JSON valido mas fora das regras de dominio, o job
tenta uma segunda vez antes de desistir. Esse laco nunca teve teste; agora
o caso de rejected_output tambem asserta que o provider foi chamado duas
vezes, e que nenhuma estrategia foi persistida.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

# FASE B — a tela

## Task B1: Infra de teste do frontend

**Files:**
- Modify: `apps/web/package.json`
- Modify: `apps/web/vite.config.ts`
- Create: `apps/web/src/test/setup.ts`
- Create: `apps/web/src/test/server.ts`
- Create: `apps/web/src/test/utils.tsx`
- Test: `apps/web/src/components/ui/Button.test.tsx`

- [ ] **Step 1: Instalar as dependências**

```bash
cd apps/web
pnpm add -D vitest jsdom @testing-library/react @testing-library/user-event @testing-library/jest-dom msw
```

- [ ] **Step 2: Acrescentar o script de teste**

Em `apps/web/package.json`, no bloco `"scripts"`, acrescentar:

```json
    "test": "vitest run",
    "test:watch": "vitest"
```

- [ ] **Step 3: Configurar o Vitest**

Substituir `apps/web/vite.config.ts` inteiro. Note o import: `defineConfig` vem de `vitest/config`, não de `vite` — só essa versão aceita o bloco `test`.

```ts
import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
```

- [ ] **Step 4: Criar o servidor MSW**

`apps/web/src/test/server.ts`:

```ts
import { setupServer } from 'msw/node'

/**
 * Sem handlers padrao: cada teste declara o que o servidor responde, com
 * `server.use(...)`. Uma requisicao nao declarada estoura em `onUnhandledRequest`
 * — e melhor um teste que quebra do que um que passa por acidente.
 */
export const server = setupServer()
```

- [ ] **Step 5: Criar o setup**

`apps/web/src/test/setup.ts`:

```ts
import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterAll, afterEach, beforeAll } from 'vitest'
import { server } from './server'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))

afterEach(() => {
  cleanup()
  server.resetHandlers()
  localStorage.clear()
})

afterAll(() => server.close())
```

- [ ] **Step 6: Criar o helper de render**

`apps/web/src/test/utils.tsx`:

```tsx
import type { ReactElement } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'

/**
 * Um QueryClient NOVO por teste. Um cliente compartilhado vaza cache entre
 * testes, e o retry padrao transforma um 404 esperado em segundos de espera.
 */
function newQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
}

/**
 * O MemoryRouter guarda a URL em memoria e NAO toca window.location. Asserir
 * `window.location.search` num teste destes passa sempre, testando nada. Esta
 * sonda expoe a query string do router.
 */
function LocationProbe() {
  const location = useLocation()
  return <span data-testid="location-search">{location.search}</span>
}

/** A query string atual do router, ex: '?run=42' ou '' quando limpa. */
export function currentSearch(): string {
  return screen.getByTestId('location-search').textContent ?? ''
}

interface Options {
  /** Rota que o componente ocupa, ex: '/projects/:projectId/strategy' */
  path: string
  /** URL inicial, ex: '/projects/1/strategy?run=42' */
  entry: string
}

export function renderWithProviders(ui: ReactElement, { path, entry }: Options) {
  return render(
    <QueryClientProvider client={newQueryClient()}>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route
            path={path}
            element={
              <>
                {ui}
                <LocationProbe />
              </>
            }
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
```

- [ ] **Step 7: Escrever o teste de fumaça**

`apps/web/src/components/ui/Button.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import { expect, test } from 'vitest'
import { Button } from './Button'

test('renderiza o rotulo', () => {
  render(<Button>Gerar estratégia</Button>)

  expect(screen.getByRole('button', { name: 'Gerar estratégia' })).toBeInTheDocument()
})
```

- [ ] **Step 8: Rodar**

```bash
cd apps/web && pnpm test
```

Esperado: PASS, 1 teste. Se falhar com `document is not defined`, o `environment: 'jsdom'` não pegou. Se falhar com `toBeInTheDocument is not a function`, o `setupFiles` não rodou.

- [ ] **Step 9: Commitar**

```bash
cd ../.. && git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/vite.config.ts apps/web/src/test apps/web/src/components/ui/Button.test.tsx
git commit -m "Infra de teste do frontend: vitest, jsdom, RTL e MSW

O apps/web nao tinha nenhum teste. A tela de Estrategia tem logica que da
para errar sem aparecer — polling, limpeza da query string, e um botao que
so pode existir em um dos tres error_code — entao a infra vem antes dela.

O MSW sobe sem handlers padrao e com onUnhandledRequest: 'error': uma
requisicao nao declarada quebra o teste em vez de passar por acidente.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B2: Extrair o `Shell`

O `Shell` está copiado em `ProjectsPage` e `BrandProfilePage`. Esta tela seria a terceira cópia.

**Files:**
- Create: `apps/web/src/components/ui/Shell.tsx`
- Modify: `apps/web/src/pages/ProjectsPage.tsx`
- Modify: `apps/web/src/pages/BrandProfilePage.tsx`

- [ ] **Step 1: Criar o componente**

`apps/web/src/components/ui/Shell.tsx`:

```tsx
import type { ReactNode } from 'react'

export function Shell({ children }: { children: ReactNode }) {
  return <main className="mx-auto max-w-(--container-shell) px-12 py-16">{children}</main>
}
```

- [ ] **Step 2: Trocar em `ProjectsPage`**

Em `apps/web/src/pages/ProjectsPage.tsx`: apagar a função `Shell` local (linhas 144-148) e acrescentar o import junto dos outros:

```tsx
import { Shell } from '@/components/ui/Shell'
```

- [ ] **Step 3: Trocar em `BrandProfilePage`**

Em `apps/web/src/pages/BrandProfilePage.tsx`: apagar a função `Shell` local (linhas 201-203) e acrescentar o mesmo import.

- [ ] **Step 4: Verificar que compila e nada quebrou**

```bash
cd apps/web && pnpm build && pnpm lint && pnpm test
```

Esperado: build sem erro, lint sem erro, 1 teste passando. O `noUnusedLocals` do tsconfig pega uma função `Shell` esquecida.

- [ ] **Step 5: Commitar**

```bash
cd ../.. && git add apps/web/src/components/ui/Shell.tsx apps/web/src/pages/ProjectsPage.tsx apps/web/src/pages/BrandProfilePage.tsx
git commit -m "Extrai o Shell duplicado para components/ui

Estava copiado em duas paginas; a tela de Estrategia seria a terceira copia.
Arrumacao do comodo em que ja estamos trabalhando.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B3: Tipos e o componente `Pillars`

**Files:**
- Modify: `apps/web/src/lib/types.ts`
- Create: `apps/web/src/components/strategy/Pillars.tsx`
- Test: `apps/web/src/components/strategy/Pillars.test.tsx`

- [ ] **Step 1: Escrever o teste que falha**

`apps/web/src/components/strategy/Pillars.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import { expect, test } from 'vitest'
import type { Pillar } from '@/lib/types'
import { Pillars } from './Pillars'

const PILARES: Pillar[] = [
  { name: 'Educação', weight: 40, description: 'Explicar o que o público não sabe.' },
  { name: 'Prova social', weight: 35, description: 'Casos e números verificáveis.' },
  { name: 'Bastidores', weight: 25, description: 'Mostrar o processo.' },
]

test('renderiza nome, peso e descrição de cada pilar', () => {
  render(<Pillars pillars={PILARES} />)

  expect(screen.getByText('Educação')).toBeInTheDocument()
  expect(screen.getByText('40%')).toBeInTheDocument()
  expect(screen.getByText('Prova social')).toBeInTheDocument()
  expect(screen.getByText('35%')).toBeInTheDocument()
  expect(screen.getByText('Bastidores')).toBeInTheDocument()
  expect(screen.getByText('25%')).toBeInTheDocument()
  expect(screen.getByText('Mostrar o processo.')).toBeInTheDocument()
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test Pillars
```

Esperado: FAIL, `Failed to resolve import "./Pillars"`.

- [ ] **Step 3: Acrescentar os tipos**

No fim de `apps/web/src/lib/types.ts`:

```ts
export interface Pillar {
  name: string
  weight: number
  description: string
}

export interface Strategy {
  id: number
  workspace_id: number
  project_id: number
  title: string
  summary: string | null
  editorial_line: string | null
  pillars: Pillar[]
  status: 'draft' | 'active' | 'archived'
  ai_run_id: number | null
}

/** Classificacao da falha. `provider_failed` e o unico onde insistir ajuda. */
export type AiRunErrorCode = 'refused' | 'rejected_output' | 'provider_failed'

export interface AiRun {
  id: number
  agent: string
  status: 'queued' | 'running' | 'succeeded' | 'failed'
  output: unknown
  error: string | null
  error_code: AiRunErrorCode | null
  cost_cents: number | null
  latency_ms: number | null
  created_at: string
}
```

- [ ] **Step 4: Implementar o componente**

`apps/web/src/components/strategy/Pillars.tsx`:

```tsx
import type { Pillar } from '@/lib/types'

/**
 * Nao confere que os pesos somam 100. Quem garante isso e o
 * StrategistAgent::validate() no servidor; duplicar a regra aqui criaria
 * dois donos para ela.
 */
export function Pillars({ pillars }: { pillars: Pillar[] }) {
  return (
    <ul className="mt-6 space-y-4">
      {pillars.map((pillar) => (
        <li key={pillar.name}>
          <div className="flex items-baseline justify-between gap-4">
            <span className="text-label-md text-on-surface">{pillar.name}</span>
            <span className="text-label-sm text-on-surface-variant">{pillar.weight}%</span>
          </div>

          <div className="bg-surface-container mt-2 h-1.5 w-full rounded-full">
            <div
              className="bg-primary h-1.5 rounded-full"
              style={{ width: `${pillar.weight}%` }}
            />
          </div>

          <p className="text-body-sm text-on-surface-variant mt-2">{pillar.description}</p>
        </li>
      ))}
    </ul>
  )
}
```

- [ ] **Step 5: Rodar para ver passar**

```bash
cd apps/web && pnpm test Pillars
```

Esperado: PASS, 1 teste.

- [ ] **Step 6: Commitar**

```bash
cd ../.. && git add apps/web/src/lib/types.ts apps/web/src/components/strategy/Pillars.tsx apps/web/src/components/strategy/Pillars.test.tsx
git commit -m "Tipos da estrategia e componente Pillars

Pillars nao confere que os pesos somam 100: quem garante e o
StrategistAgent::validate(). Duplicar a regra criaria dois donos.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B4: O hook, e o caminho feliz da tela

Este é o coração. O hook é o único lugar que sabe que existem URL, polling e mutação.

**Files:**
- Create: `apps/web/src/hooks/useStrategyGeneration.ts`
- Create: `apps/web/src/components/strategy/GenerationStatus.tsx`
- Create: `apps/web/src/components/strategy/StrategyCard.tsx`
- Create: `apps/web/src/pages/StrategyPage.tsx`
- Test: `apps/web/src/pages/StrategyPage.test.tsx`

- [ ] **Step 1: Escrever os testes que falham**

`apps/web/src/pages/StrategyPage.test.tsx`:

```tsx
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { currentSearch, renderWithProviders } from '@/test/utils'
import type { AiRun, Strategy } from '@/lib/types'
import { StrategyPage } from './StrategyPage'

const ROUTE = { path: '/projects/:projectId/strategy', entry: '/projects/1/strategy' }

const ESTRATEGIA: Strategy = {
  id: 7,
  workspace_id: 1,
  project_id: 1,
  title: 'Estratégia editorial trimestral',
  summary: 'Posicionar a marca como referência técnica.',
  editorial_line: 'Falar de resultado antes de falar de produto.',
  pillars: [
    { name: 'Educação', weight: 40, description: 'd' },
    { name: 'Prova social', weight: 35, description: 'd' },
    { name: 'Bastidores', weight: 25, description: 'd' },
  ],
  status: 'draft',
  ai_run_id: 42,
}

function run(overrides: Partial<AiRun>): AiRun {
  return {
    id: 42,
    agent: 'strategist',
    status: 'running',
    output: null,
    error: null,
    error_code: null,
    cost_cents: null,
    latency_ms: null,
    created_at: new Date().toISOString(),
    ...overrides,
  }
}

function strategies(...data: Strategy[]) {
  return http.get('/api/v1/projects/1/strategies', () => HttpResponse.json({ data }))
}

beforeEach(() => {
  // shouldAdvanceTime: sem isso o findBy* da Testing Library nunca resolve,
  // porque ele tambem depende de timer.
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  vi.useRealTimers()
})

function setup() {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
}

test('projeto sem estratégia oferece gerar', async () => {
  server.use(strategies())

  renderWithProviders(<StrategyPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /gerar estratégia/i })).toBeInTheDocument()
})

test('caminho feliz: gera, faz polling e mostra a estratégia', async () => {
  const user = setup()
  let geradas: Strategy[] = []
  let chamadasDoRun = 0

  server.use(
    http.get('/api/v1/projects/1/strategies', () => HttpResponse.json({ data: geradas })),
    http.post('/api/v1/projects/1/strategies:generate', () =>
      HttpResponse.json({ ai_run_id: 42 }, { status: 202 }),
    ),
    http.get('/api/v1/ai-runs/42', () => {
      chamadasDoRun++
      if (chamadasDoRun === 1) return HttpResponse.json(run({ status: 'running' }))
      geradas = [ESTRATEGIA]
      return HttpResponse.json(run({ status: 'succeeded' }))
    }),
  )

  renderWithProviders(<StrategyPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar estratégia/i }))

  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()

  expect(await screen.findByText('Estratégia editorial trimestral')).toBeInTheDocument()
  expect(screen.getByText('Educação')).toBeInTheDocument()
  expect(screen.getByText('Rascunho')).toBeInTheDocument()

  // succeeded limpa o ?run: a execucao nao interessa mais.
  await waitFor(() => expect(currentSearch()).toBe(''))
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: FAIL, `Failed to resolve import "./StrategyPage"`.

- [ ] **Step 3: Implementar o hook**

`apps/web/src/hooks/useStrategyGeneration.ts`:

```ts
import { useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ApiError, api } from '@/lib/api'
import type { AiRun } from '@/lib/types'

export const POLL_INTERVAL_MS = 1500
export const SLOW_AFTER_MS = 120_000

export type GenerationState =
  | { kind: 'idle' }
  | { kind: 'starting' }
  | { kind: 'running'; slow: boolean }
  | { kind: 'failed'; message: string; retryable: boolean }
  | { kind: 'budget'; spentCents: number; limitCents: number }
  | { kind: 'lost' }

interface BudgetBody {
  spent_cents: number
  limit_cents: number
}

/**
 * O unico lugar que sabe que existem URL, intervalo de polling e mutacao.
 *
 * O ai_run_id vive na query string, nao em useState: se vivesse na memoria,
 * recarregar a pagina no meio da geracao perderia o acompanhamento, e uma
 * recusa nunca mostraria o porque — o usuario clicaria em Gerar de novo e
 * levaria outra recusa.
 */
export function useStrategyGeneration(projectId: string) {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const runId = params.get('run')

  const runQuery = useQuery({
    queryKey: ['ai-run', runId],
    queryFn: () => api<AiRun>(`/ai-runs/${runId}`),
    enabled: runId !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'succeeded' || status === 'failed' ? false : POLL_INTERVAL_MS
    },
  })

  const generation = useMutation({
    mutationFn: () =>
      api<{ ai_run_id: number }>(`/projects/${projectId}/strategies:generate`, {
        method: 'POST',
      }),
    onSuccess: ({ ai_run_id }) => setParams({ run: String(ai_run_id) }, { replace: true }),
  })

  const succeeded = runQuery.data?.status === 'succeeded'

  // O useQuery da v5 nao tem mais onSuccess. Este efeito e a unica forma de
  // reagir ao sucesso da execucao. Nao e descuido.
  useEffect(() => {
    if (!succeeded) return
    queryClient.invalidateQueries({ queryKey: ['strategies', projectId] })
    setParams({}, { replace: true })
  }, [succeeded, projectId, queryClient, setParams])

  const state = deriveState(runId, runQuery.data, runQuery.error, generation.error, generation.isPending)

  return {
    state,
    generate: () => {
      generation.reset()
      generation.mutate()
    },
    retry: () => {
      setParams({}, { replace: true })
      generation.reset()
      generation.mutate()
    },
    dismiss: () => {
      generation.reset()
      setParams({}, { replace: true })
    },
  }
}

/** A ordem e a regra: o 402 acontece SEM criar execucao, entao vem primeiro. */
function deriveState(
  runId: string | null,
  run: AiRun | undefined,
  runError: unknown,
  generationError: unknown,
  generationPending: boolean,
): GenerationState {
  if (generationError instanceof ApiError && generationError.status === 402) {
    const body = generationError.body as BudgetBody
    return { kind: 'budget', spentCents: body.spent_cents, limitCents: body.limit_cents }
  }

  if (runId && runError instanceof ApiError && runError.status === 404) {
    return { kind: 'lost' }
  }

  if (run?.status === 'failed') {
    return {
      kind: 'failed',
      message: run.error ?? 'A geração falhou.',
      retryable: run.error_code === 'provider_failed',
    }
  }

  if (run?.status === 'queued' || run?.status === 'running') {
    return { kind: 'running', slow: Date.now() - Date.parse(run.created_at) > SLOW_AFTER_MS }
  }

  if (generationPending || (runId !== null && run === undefined)) {
    return { kind: 'starting' }
  }

  return { kind: 'idle' }
}
```

- [ ] **Step 4: Implementar `GenerationStatus`**

`apps/web/src/components/strategy/GenerationStatus.tsx`:

```tsx
import { Button } from '@/components/ui/Button'
import type { GenerationState } from '@/hooks/useStrategyGeneration'

interface Props {
  state: GenerationState
  onRetry: () => void
  onDismiss: () => void
}

function dollars(cents: number): string {
  return `US$ ${(cents / 100).toFixed(2)}`
}

/** Desenha o estado. Nao sabe que a rede existe. */
export function GenerationStatus({ state, onRetry, onDismiss }: Props) {
  switch (state.kind) {
    case 'idle':
      return null

    case 'starting':
    case 'running':
      return (
        <p className="text-body-md text-on-surface-variant mt-6" role="status">
          Gerando sua estratégia…
          {state.kind === 'running' && state.slow && (
            <span className="mt-1 block">Isto está demorando mais que o normal.</span>
          )}
        </p>
      )

    case 'failed':
      return (
        <div className="bg-error-container mt-6 rounded-card p-4" role="alert">
          <p className="text-body-md text-on-surface">{state.message}</p>
          <div className="mt-3 flex gap-2">
            {/* Sem botao quando a falha e uma recusa: o mesmo prompt sera
                recusado de novo, e a tentativa custa orcamento. */}
            {state.retryable && (
              <Button size="sm" onClick={onRetry}>
                Tentar de novo
              </Button>
            )}
            <Button size="sm" variant="secondary" onClick={onDismiss}>
              Dispensar
            </Button>
          </div>
        </div>
      )

    case 'budget':
      return (
        <div className="bg-error-container mt-6 rounded-card p-4" role="alert">
          <p className="text-body-md text-on-surface">
            Orçamento mensal de IA esgotado para este espaço de trabalho. Você gastou{' '}
            {dollars(state.spentCents)} de {dollars(state.limitCents)}.
          </p>
          <Button className="mt-3" size="sm" variant="secondary" onClick={onDismiss}>
            Dispensar
          </Button>
        </div>
      )

    case 'lost':
      return (
        <div className="bg-error-container mt-6 rounded-card p-4" role="alert">
          <p className="text-body-md text-on-surface">Não encontramos essa geração.</p>
          <Button className="mt-3" size="sm" variant="secondary" onClick={onDismiss}>
            Dispensar
          </Button>
        </div>
      )
  }
}
```

- [ ] **Step 5: Implementar `StrategyCard`**

`apps/web/src/components/strategy/StrategyCard.tsx`:

```tsx
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Pillars } from '@/components/strategy/Pillars'
import type { Strategy } from '@/lib/types'

interface Props {
  strategy: Strategy
  pending: boolean
  onApprove: () => void
  onArchive: () => void
  onRegenerate: () => void
}

const CHIPS: Record<Strategy['status'], string> = {
  draft: 'Rascunho',
  active: 'Ativa',
  archived: 'Arquivada',
}

export function StrategyCard({ strategy, pending, onApprove, onArchive, onRegenerate }: Props) {
  return (
    <Card>
      <div className="flex items-start justify-between gap-4">
        <h2 className="text-headline-lg font-display text-on-surface">{strategy.title}</h2>
        <span className="text-label-sm bg-surface-container text-on-surface-variant shrink-0 rounded-full px-3 py-1">
          {CHIPS[strategy.status]}
        </span>
      </div>

      {strategy.summary && (
        <p className="text-body-lg text-on-surface-variant mt-4">{strategy.summary}</p>
      )}

      {strategy.editorial_line && (
        <>
          <h3 className="text-label-md text-on-surface mt-8">Linha editorial</h3>
          <p className="text-body-md text-on-surface-variant mt-2">{strategy.editorial_line}</p>
        </>
      )}

      <h3 className="text-label-md text-on-surface mt-8">Pilares de conteúdo</h3>
      <Pillars pillars={strategy.pillars} />

      <div className="mt-8 flex gap-2">
        {strategy.status === 'draft' ? (
          <>
            <Button disabled={pending} onClick={onApprove}>
              Aprovar estratégia
            </Button>
            <Button variant="secondary" disabled={pending} onClick={onArchive}>
              Descartar
            </Button>
          </>
        ) : (
          <Button variant="secondary" onClick={onRegenerate}>
            Gerar nova
          </Button>
        )}
      </div>
    </Card>
  )
}
```

- [ ] **Step 6: Implementar `StrategyPage`**

`apps/web/src/pages/StrategyPage.tsx`:

```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { StrategyCard } from '@/components/strategy/StrategyCard'
import { Button } from '@/components/ui/Button'
import { Shell } from '@/components/ui/Shell'
import { useStrategyGeneration } from '@/hooks/useStrategyGeneration'
import { api } from '@/lib/api'
import type { Strategy } from '@/lib/types'

export function StrategyPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const { state, generate, retry, dismiss } = useStrategyGeneration(projectId!)

  const strategies = useQuery({
    queryKey: ['strategies', projectId],
    queryFn: () => api<{ data: Strategy[] }>(`/projects/${projectId}/strategies`),
  })

  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'active' | 'archived' }) =>
      api(`/strategies/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['strategies', projectId] }),
  })

  if (strategies.isPending) return <Shell>Carregando…</Shell>
  if (strategies.isError) return <Shell>Projeto não encontrado.</Shell>

  // So a mais recente. O historico existe no banco e nao aparece aqui.
  const current = strategies.data.data[0]

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/brand-profile`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Perfil da Marca
      </Link>

      <h1 className="text-display-lg text-on-surface mt-4">Estratégia editorial</h1>
      <p className="text-body-lg text-on-surface-variant mt-4 max-w-2xl">
        A partir do perfil da sua marca, o Estrategista define a linha editorial e os pilares
        que vão sustentar o planejamento dos próximos meses.
      </p>

      <GenerationStatus state={state} onRetry={retry} onDismiss={dismiss} />

      <div className="mt-8">
        {current ? (
          <StrategyCard
            strategy={current}
            pending={setStatus.isPending}
            onApprove={() => setStatus.mutate({ id: current.id, status: 'active' })}
            onArchive={() => setStatus.mutate({ id: current.id, status: 'archived' })}
            onRegenerate={generate}
          />
        ) : (
          state.kind === 'idle' && (
            <Button onClick={generate}>Gerar estratégia</Button>
          )
        )}
      </div>
    </Shell>
  )
}
```

- [ ] **Step 7: Rodar para ver passar**

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: PASS, 2 testes.

Se o segundo teste travar esperando "Gerando", confirme o `shouldAdvanceTime: true` no `vi.useFakeTimers`.

- [ ] **Step 8: Commitar**

```bash
cd ../.. && git add apps/web/src/hooks apps/web/src/components/strategy apps/web/src/pages/StrategyPage.tsx apps/web/src/pages/StrategyPage.test.tsx
git commit -m "Tela de Estrategia: hook de geracao e caminho feliz

O useStrategyGeneration e o unico dono da URL, do polling e da mutacao;
os componentes abaixo dele sao funcoes de props para JSX.

O ai_run_id vive em ?run=42, nao em useState: recarregar a pagina no meio
da geracao nao pode perder o acompanhamento.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B5: As três falhas

Aqui mora o teste que justifica a Fase A inteira.

**Files:**
- Test: `apps/web/src/pages/StrategyPage.test.tsx`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar ao fim de `StrategyPage.test.tsx`:

```tsx
test('recusa mostra a mensagem e NÃO oferece tentar de novo', async () => {
  server.use(
    strategies(),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json(
        run({
          status: 'failed',
          error: 'O modelo recusou esta requisicao.',
          error_code: 'refused',
        }),
      ),
    ),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=42',
  })

  expect(await screen.findByText('O modelo recusou esta requisicao.')).toBeInTheDocument()

  // A assercao que justifica o error_code: insistir aqui e dano.
  expect(screen.queryByRole('button', { name: /tentar de novo/i })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: /dispensar/i })).toBeInTheDocument()
})

test('falha transiente oferece tentar de novo, e o clique dispara um POST novo', async () => {
  const user = setup()
  let posts = 0

  server.use(
    strategies(),
    http.post('/api/v1/projects/1/strategies:generate', () => {
      posts++
      return HttpResponse.json({ ai_run_id: 99 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json(
        run({
          status: 'failed',
          error: 'Falha ao gerar. Tente novamente em alguns instantes.',
          error_code: 'provider_failed',
        }),
      ),
    ),
    http.get('/api/v1/ai-runs/99', () => HttpResponse.json(run({ id: 99, status: 'running' }))),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=42',
  })

  await user.click(await screen.findByRole('button', { name: /tentar de novo/i }))

  await waitFor(() => expect(posts).toBe(1))
  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()
})

test('402 mostra o orçamento e não inicia polling', async () => {
  const user = setup()

  server.use(
    strategies(),
    http.post('/api/v1/projects/1/strategies:generate', () =>
      HttpResponse.json(
        {
          message: 'Orcamento mensal de IA esgotado para este espaco de trabalho.',
          spent_cents: 5000,
          limit_cents: 5000,
        },
        { status: 402 },
      ),
    ),
    // Nao registramos /ai-runs/*: se a tela fizer polling, o MSW estoura com
    // onUnhandledRequest: 'error' e o teste quebra. E o ponto.
  )

  renderWithProviders(<StrategyPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar estratégia/i }))

  expect(await screen.findByText(/orçamento mensal de ia esgotado/i)).toBeInTheDocument()
  expect(screen.getByText(/US\$ 50\.00 de US\$ 50\.00/)).toBeInTheDocument()
  expect(currentSearch()).toBe('')
  expect(screen.queryByText(/gerando/i)).not.toBeInTheDocument()
})
```

- [ ] **Step 2: Rodar**

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: PASS, 5 testes. **Nenhuma implementação nova é necessária** — o hook e o `GenerationStatus` da Task B4 já cobrem os três casos. Se algum falhar, o bug está lá, não aqui.

- [ ] **Step 3: Provar que o teste da recusa realmente pega o bug**

Temporariamente, em `GenerationStatus.tsx`, trocar `{state.retryable && (` por `{true && (`.

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: FAIL no teste da recusa, com `expected element not to be in the document`. **Desfazer a mudança** e rodar de novo para confirmar PASS.

Um teste que assere ausência precisa ser visto falhando, ou ele não está asserindo nada.

- [ ] **Step 4: Commitar**

```bash
cd ../.. && git add apps/web/src/pages/StrategyPage.test.tsx
git commit -m "Testa as tres falhas da tela de Estrategia

A recusa nao oferece botao de tentar de novo — a assercao que justifica a
coluna error_code. Verificado que o teste falha se a condicao for removida.

O 402 nao registra handler para /ai-runs/*: se a tela fizer polling, o MSW
estoura. E o jeito de asserir que nenhuma requisicao aconteceu.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B6: Retomada por `?run`, execução perdida, e a demora

**Files:**
- Test: `apps/web/src/pages/StrategyPage.test.tsx`

- [ ] **Step 1: Escrever os testes**

Acrescentar ao fim de `StrategyPage.test.tsx`:

```tsx
test('montar com ?run na URL retoma o acompanhamento sem disparar POST', async () => {
  let posts = 0

  server.use(
    strategies(),
    http.post('/api/v1/projects/1/strategies:generate', () => {
      posts++
      return HttpResponse.json({ ai_run_id: 42 }, { status: 202 })
    }),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=42',
  })

  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()
  expect(posts).toBe(0)
})

test('?run apontando para execução inexistente vira estado perdido', async () => {
  server.use(
    strategies(),
    http.get('/api/v1/ai-runs/404', () => new HttpResponse(null, { status: 404 })),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=404',
  })

  expect(await screen.findByText(/não encontramos essa geração/i)).toBeInTheDocument()
})

test('execução parada há mais de dois minutos avisa que está demorando', async () => {
  const velha = new Date(Date.now() - 3 * 60_000).toISOString()

  server.use(
    strategies(),
    http.get('/api/v1/ai-runs/42', () =>
      HttpResponse.json(run({ status: 'running', created_at: velha })),
    ),
  )

  renderWithProviders(<StrategyPage />, {
    path: ROUTE.path,
    entry: '/projects/1/strategy?run=42',
  })

  expect(await screen.findByText(/está demorando mais que o normal/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Rodar**

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: PASS, 8 testes. Nenhuma implementação nova.

Se o teste do 404 falhar por timeout, confirme que o `renderWithProviders` cria o `QueryClient` com `retry: false` — senão o TanStack repete o 404 e o teste espera segundos.

- [ ] **Step 3: Commitar**

```bash
cd ../.. && git add apps/web/src/pages/StrategyPage.test.tsx
git commit -m "Testa retomada por ?run, execucao perdida e aviso de demora

O teste da retomada e o que justifica pôr o ai_run_id na URL: montar com
?run=42 acompanha a geracao sem disparar POST nenhum. E o reload no meio.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B7: Aprovar o rascunho

**Files:**
- Test: `apps/web/src/pages/StrategyPage.test.tsx`

- [ ] **Step 1: Escrever o teste**

Acrescentar ao fim de `StrategyPage.test.tsx`:

```tsx
test('aprovar o rascunho troca o chip para Ativa', async () => {
  const user = setup()
  let atual: Strategy = ESTRATEGIA

  server.use(
    http.get('/api/v1/projects/1/strategies', () => HttpResponse.json({ data: [atual] })),
    http.patch('/api/v1/strategies/7', async ({ request }) => {
      const body = (await request.json()) as { status: Strategy['status'] }
      atual = { ...atual, status: body.status }
      return HttpResponse.json({ data: atual })
    }),
  )

  renderWithProviders(<StrategyPage />, ROUTE)

  expect(await screen.findByText('Rascunho')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: /aprovar estratégia/i }))

  expect(await screen.findByText('Ativa')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /gerar nova/i })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /aprovar estratégia/i })).not.toBeInTheDocument()
})
```

- [ ] **Step 2: Rodar**

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: PASS, 9 testes. Nenhuma implementação nova.

- [ ] **Step 3: Rodar a suíte inteira do frontend**

```bash
cd apps/web && pnpm test && pnpm lint && pnpm build
```

Esperado: 11 testes (1 Button + 1 Pillars + 9 StrategyPage), lint limpo, build sem erro.

- [ ] **Step 4: Commitar**

```bash
cd ../.. && git add apps/web/src/pages/StrategyPage.test.tsx
git commit -m "Testa a aprovacao do rascunho

Aprovar troca o chip para Ativa e substitui as acoes de rascunho por
Gerar nova. O status manda nas afordancias.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B8: Ligar a tela na navegação

**Files:**
- Modify: `apps/web/src/main.tsx`
- Modify: `apps/web/src/pages/BrandProfilePage.tsx`

- [ ] **Step 1: Registrar a rota**

Em `apps/web/src/main.tsx`, acrescentar o import junto dos outros:

```tsx
import { StrategyPage } from '@/pages/StrategyPage'
```

E a rota, depois da rota de `brand-profile`:

```tsx
          <Route
            path="/projects/:projectId/strategy"
            element={
              <RequireAuth>
                <StrategyPage />
              </RequireAuth>
            }
          />
```

- [ ] **Step 2: Link a partir do Perfil da Marca**

Em `apps/web/src/pages/BrandProfilePage.tsx`, logo depois do parágrafo que mostra a porcentagem de completude (o `<p>` que contém `Perfil {completion.percent}% completo.`), acrescentar:

```tsx
      <Link
        to={`/projects/${projectId}/strategy`}
        className="text-body-sm text-primary mt-4 inline-block hover:underline"
      >
        Ir para a Estratégia editorial →
      </Link>
```

O `Link` já está importado nesse arquivo.

- [ ] **Step 3: Verificar**

```bash
cd apps/web && pnpm build && pnpm lint && pnpm test
```

Esperado: tudo verde.

- [ ] **Step 4: Commitar**

```bash
cd ../.. && git add apps/web/src/main.tsx apps/web/src/pages/BrandProfilePage.tsx
git commit -m "Liga a tela de Estrategia na navegacao

Rota /projects/:projectId/strategy e link a partir do Perfil da Marca.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B9: Verificação no browser

Testes verdes não provam que a tela funciona contra o servidor real. Este passo não produz commit.

- [ ] **Step 1: Subir o Postgres, se preciso**

```bash
"C:/Users/mysho/bin/pgsql16/bin/pg_ctl.exe" -D "C:/Users/mysho/pgdata/16" -l "C:/Users/mysho/pgdata/pg16.log" -o "-p 5433" start
```

- [ ] **Step 2: Tornar o polling visível**

O `MockProvider` responde instantaneamente e o estado `running` mal aparece. Em `apps/api/app/Ai/Providers/MockProvider.php`, no topo do `generate()`, acrescentar **temporariamente**:

```php
        sleep(3);
```

- [ ] **Step 3: Subir os três processos**

Em três terminais:

```bash
cd apps/api && php artisan serve
cd apps/api && php artisan queue:work
cd apps/web && pnpm dev
```

- [ ] **Step 4: Percorrer o caminho feliz**

Abrir http://localhost:5173, logar, entrar num projeto, ir ao Perfil da Marca e clicar em "Ir para a Estratégia editorial".

Verificar, nesta ordem:

1. A tela mostra o botão "Gerar estratégia".
2. Clicar. A URL ganha `?run=<id>` e aparece "Gerando sua estratégia…".
3. **Recarregar a página (F5) enquanto gera.** O acompanhamento continua — é o ponto de pôr o id na URL.
4. Depois de ~3 s, a estratégia aparece, o `?run` some da URL, e o chip diz "Rascunho".
5. Os três pilares aparecem com barras somando 100%.
6. Clicar em "Aprovar estratégia". O chip vira "Ativa" e as ações viram "Gerar nova".

- [ ] **Step 5: Verificar o 402**

Parar o `artisan serve`, pôr `AI_WORKSPACE_MONTHLY_BUDGET_CENTS=0` no `apps/api/.env`, subir de novo, e clicar em "Gerar nova". Deve aparecer a mensagem de orçamento esgotado, e a URL **não** deve ganhar `?run=`.

Desfazer a variável depois.

- [ ] **Step 6: Desfazer o `sleep(3)`**

Remover a linha do `MockProvider.php`.

```bash
cd /c/Users/mysho/2m-social-ai && git status --porcelain
```

Esperado: saída vazia. Se `MockProvider.php` aparecer como modificado, o `sleep` ficou para trás.

---

## Critério de sucesso

- `cd apps/api && php artisan test` → 52 testes verdes.
- `cd apps/web && pnpm test` → 11 testes verdes.
- `cd apps/web && pnpm lint && pnpm build` → limpo.
- O caminho feliz percorrido no browser, incluindo o reload no meio da geração.
- O teste da recusa foi visto **falhando** quando a condição `state.retryable` é removida (Task B5, Step 3).
- `git status --porcelain` vazio ao fim.
