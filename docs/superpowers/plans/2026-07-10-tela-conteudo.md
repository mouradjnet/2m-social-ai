# Tela de Conteúdo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar às peças que o CopywriterAgent gera uma tela — uma biblioteca agrupada por status, onde o usuário gera conteúdo, vê as peças e as move pelo fluxo.

**Architecture:** Backend novo de leitura/transição de `contents` (nenhum existia), com a regra de fluxo dona do servidor e `content_revisions` gravado na transação. Frontend generaliza o `useStrategyGeneration` em `useGeneration` (parametrizado por endpoint + query a invalidar), migra a `StrategyPage` para ele, e constrói a `ContentPage` sobre o mesmo hook. Os 11 testes da `StrategyPage` são a rede que prova que a generalização não regrediu.

**Tech Stack:** Backend: PHP 8.4, Laravel 13.19, PostgreSQL 16, PHPUnit. Frontend: React 19, TypeScript 6, Vite 8, TanStack Query v5, React Router 7, Tailwind 4, Vitest + RTL + MSW.

**Spec:** `docs/superpowers/specs/2026-07-10-tela-conteudo-design.md`

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

**Frontend:** o lint é `pnpm exec oxlint`, **não** `pnpm lint` (este ambiente intercepta `pnpm lint` com um wrapper de ESLint inexistente). Dois warnings de `react(only-export-components)` em `src/main.tsx` e `src/test/utils.tsx` são pré-existentes e não bloqueiam.

**`pnpm test` (esbuild) NÃO checa tipos** — só `pnpm build` (tsc) faz a checagem completa. Rodar build antes de commitar o frontend.

**Nenhuma migration nesta fatia.** A tabela `contents` e `content_revisions` já existem (migration `2026_07_10_010500`). Confirmado: `content_revisions` só tem `created_at` (com `useCurrent()`), sem `updated_at`; `Content` já tem `#[ScopedBy(WorkspaceMemberScope::class)]`.

**Padrão a espelhar:** `StrategyController` (`index` + `update` com `Gate::authorize`) e `StrategyPage` (query da lista + mutação de status + `useGeneration`).

---

## Estrutura de arquivos

### Fase A — backend (`apps/api`)

| Arquivo | Responsabilidade |
|---|---|
| `app/Models/ContentRevision.php` | **Criar.** Model append-only (`timestamps=false`) |
| `app/Models/Project.php` | **Modificar.** Relação `contents()` |
| `app/Http/Controllers/Api/V1/ContentController.php` | **Criar.** `index` + `update` com regra de transição |
| `routes/api.php` | **Modificar.** Rotas de content |
| `tests/Feature/ContentTransitionTest.php` | **Criar.** |

### Fase B — frontend (`apps/web`)

| Arquivo | Responsabilidade |
|---|---|
| `src/hooks/useGeneration.ts` | **Criar** (rename de `useStrategyGeneration.ts`). Hook generalizado |
| `src/hooks/useStrategyGeneration.ts` | **Deletar** (vira `useGeneration.ts`) |
| `src/components/strategy/GenerationStatus.tsx` | **Modificar.** Import do tipo `GenerationState` |
| `src/pages/StrategyPage.tsx` | **Modificar.** `useGeneration` + link "Ver conteúdo" |
| `src/lib/types.ts` | **Modificar.** `Content`, `ContentStatus` |
| `src/lib/groupByStatus.ts` | **Criar.** Função pura de agrupar |
| `src/components/content/ContentCard.tsx` | **Criar.** Card apresentacional |
| `src/components/content/ContentBoard.tsx` | **Criar.** Colunas por status |
| `src/pages/ContentPage.tsx` | **Criar.** Composição + query + mutação + geração |
| `src/main.tsx` | **Modificar.** Rota `/projects/:projectId/content` |

---

# FASE A — backend

## Task A1: `ContentRevision` model e relação `contents()`

**Files:**
- Create: `apps/api/app/Models/ContentRevision.php`
- Modify: `apps/api/app/Models/Project.php`
- Test: `apps/api/tests/Feature/ContentTransitionTest.php`

- [ ] **Step 1: Escrever o teste que falha**

`apps/api/tests/Feature/ContentTransitionTest.php` (arquivo novo, um teste por ora):

```php
<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function content(Project $project, string $status = 'idea'): Content
    {
        return Content::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => 'Peca',
            'caption' => 'c',
            'cta' => 'x',
            'hashtags' => ['#a'],
            'format' => 'post',
            'channel' => 'instagram',
            'status' => $status,
            'source' => 'ai',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_revisao_e_append_only_sem_updated_at(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project);
        $user = User::factory()->create();

        $rev = ContentRevision::create([
            'content_id' => $content->id,
            'user_id' => $user->id,
            'from_status' => 'idea',
            'to_status' => 'production',
        ]);

        $this->assertFalse($rev->timestamps);
        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'from_status' => 'idea',
            'to_status' => 'production',
        ]);
    }

    public function test_project_lista_seus_contents(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        $this->content($project);

        $this->assertCount(2, $project->contents()->get());
    }
}
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/api && php artisan test --filter ContentTransitionTest
```

Esperado: FAIL — `Class "App\Models\ContentRevision" not found` e `Call to undefined method Project::contents()`.

- [ ] **Step 3: Criar o model**

`apps/api/app/Models/ContentRevision.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historico append-only de mudancas de status. So `created_at` (com useCurrent
 * na migration), sem `updated_at` — por isso timestamps desligado, senao o
 * Eloquent tentaria escrever updated_at e o insert falharia.
 */
class ContentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'user_id', 'from_status', 'to_status'];
}
```

- [ ] **Step 4: Relação `contents()` no `Project`**

Em `apps/api/app/Models/Project.php`, depois do método `strategies()`:

```php
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }
```

(O `use Illuminate\Database\Eloquent\Relations\HasMany;` já existe no arquivo — o `strategies()` usa.)

- [ ] **Step 5: Rodar para ver passar**

```bash
cd apps/api && php artisan test --filter ContentTransitionTest
```

Esperado: PASS, 2 testes.

- [ ] **Step 6: Formatar e commitar**

```bash
cd apps/api && ./vendor/bin/pint app/Models/ContentRevision.php app/Models/Project.php tests/Feature/ContentTransitionTest.php
cd ../.. && git add apps/api/app/Models/ContentRevision.php apps/api/app/Models/Project.php apps/api/tests/Feature/ContentTransitionTest.php
git commit -m "ContentRevision model e relacao Project::contents

ContentRevision e append-only: timestamps=false porque a tabela so tem
created_at (com useCurrent). Sem o flag, o Eloquent tentaria escrever
updated_at e o insert falharia.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task A2: `ContentController` — listar e transicionar status

**Files:**
- Create: `apps/api/app/Http/Controllers/Api/V1/ContentController.php`
- Modify: `apps/api/routes/api.php`
- Test: `apps/api/tests/Feature/ContentTransitionTest.php`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `ContentTransitionTest.php` os imports que faltam, no topo:

```php
use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use Laravel\Sanctum\Sanctum;
```

E, dentro da classe, um helper e os testes:

```php
    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_index_lista_as_pecas_do_projeto(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $this->content($project);
        $this->content($project);

        Sanctum::actingAs($editor);

        $this->getJson("/api/v1/projects/{$project->id}/contents")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/projects/{$target->id}/contents")->assertNotFound();
    }

    public function test_avancar_um_passo_grava_revisao_na_transacao(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertOk()
            ->assertJsonPath('data.status', 'production');

        $this->assertDatabaseHas('content_revisions', [
            'content_id' => $content->id,
            'from_status' => 'idea',
            'to_status' => 'production',
            'user_id' => $editor->id,
        ]);
    }

    public function test_voltar_um_passo_e_valido(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'review');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertOk()
            ->assertJsonPath('data.status', 'production');
    }

    public function test_pular_etapa_devolve_422_e_nao_muda_nada(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'published'])
            ->assertStatus(422);

        $this->assertSame('idea', $content->fresh()->status);
        $this->assertSame(0, ContentRevision::query()->count());
    }

    public function test_avancar_alem_de_approved_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'approved');

        Sanctum::actingAs($editor);

        // 'scheduled' e o proximo, mas nao esta no FLOW desta fatia.
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'scheduled'])
            ->assertStatus(422);
    }

    public function test_arquivar_de_qualquer_estado_ativo_e_valido(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'review');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_arquivar_o_que_ja_esta_arquivado_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'archived');

        Sanctum::actingAs($editor);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'archived'])
            ->assertStatus(422);
    }

    public function test_viewer_nao_pode_mover(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->memberOf($workspace, WorkspaceRole::Viewer);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($viewer);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertForbidden();
    }

    public function test_peca_de_outro_workspace_devolve_404(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = $this->memberOf($mine, WorkspaceRole::Owner);
        $target = Project::factory()->create(['workspace_id' => $theirs->id]);
        $content = $this->content($target, 'idea');

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'production'])
            ->assertNotFound();
    }
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/api && php artisan test --filter ContentTransitionTest
```

Esperado: FAIL — a rota `contents` não existe (404 onde se espera 200/422/403).

- [ ] **Step 3: Criar o controller**

`apps/api/app/Http/Controllers/Api/V1/ContentController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContentController extends Controller
{
    /**
     * O fluxo linear. `scheduled`/`published` ficam de fora desta fatia: exigem
     * agendar e exportar, que nao existem. `archived` e terminal, alcancavel de
     * qualquer estado ativo — nao entra na sequencia.
     */
    private const FLOW = ['idea', 'production', 'review', 'approved'];

    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => $project->contents()->latest()->get(),
        ]);
    }

    public function update(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate(['status' => ['required', 'string']]);
        $from = $content->status;
        $to = $data['status'];

        if (! $this->isValidTransition($from, $to)) {
            return response()->json([
                'message' => "Transicao invalida de '{$from}' para '{$to}'.",
            ], 422);
        }

        DB::transaction(function () use ($content, $from, $to) {
            $content->update(['status' => $to]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'from_status' => $from,
                'to_status' => $to,
            ]);
        });

        return response()->json(['data' => $content->refresh()]);
    }

    /** Arquivar de qualquer estado ativo, ou mover +-1 passo no fluxo linear. */
    private function isValidTransition(string $from, string $to): bool
    {
        if ($to === 'archived') {
            return $from !== 'archived';
        }

        $i = array_search($from, self::FLOW, true);
        $j = array_search($to, self::FLOW, true);

        return $i !== false && $j !== false && abs($i - $j) === 1;
    }
}
```

- [ ] **Step 4: Registrar as rotas**

Em `apps/api/routes/api.php`, acrescentar o import junto dos outros controllers V1:

```php
use App\Http\Controllers\Api\V1\ContentController;
```

E as rotas, logo depois da linha `copy:generate`:

```php
        Route::get('projects/{project}/contents', [ContentController::class, 'index']);
        Route::patch('contents/{content}', [ContentController::class, 'update']);
```

- [ ] **Step 5: Rodar para ver passar**

```bash
cd apps/api && php artisan test --filter ContentTransitionTest
```

Esperado: PASS, 12 testes (2 da A1 + 10 novos).

- [ ] **Step 6: Falsificar a regra de transição**

A regra `abs($i - $j) === 1` é o que barra pular etapa. Confirmar que o teste a exige: em `ContentController.php`, trocar por `$i !== false && $j !== false` (aceitaria qualquer salto dentro do FLOW).

```bash
cd apps/api && php artisan test --filter test_avancar_alem_de_approved_devolve_422
```

Isso não pega (scheduled não está no FLOW de qualquer forma). O teste que pega é outro — rodar:

```bash
cd apps/api && php artisan test --filter test_pular_etapa_devolve_422
```

`idea → published`: `published` não está no FLOW, então `array_search` devolve false e ainda barra. **Este salto não distingue a regra.** O salto que distingue é `idea → review` (ambos no FLOW, distância 2). Acrescentar temporariamente este teste para provar a regra:

```php
    public function test_pular_de_idea_para_review_dentro_do_fluxo_devolve_422(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = $this->memberOf($workspace, WorkspaceRole::Editor);
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);
        $content = $this->content($project, 'idea');

        Sanctum::actingAs($editor);

        // idea e review estao ambos no FLOW, distancia 2 — a regra +-1 barra.
        $this->patchJson("/api/v1/contents/{$content->id}", ['status' => 'review'])
            ->assertStatus(422);
    }
```

Com a regra sabotada (`abs !== 1` removido), este teste falha (aceitaria `idea → review`). Restaurar a regra e confirmar que passa. **Manter este teste** — ele é o que prova a distância ±1.

- [ ] **Step 7: Suíte inteira, formatar, commitar**

```bash
cd apps/api && php artisan test
./vendor/bin/pint app/Http/Controllers/Api/V1/ContentController.php routes/api.php tests/Feature/ContentTransitionTest.php
cd ../.. && git add apps/api/app/Http/Controllers/Api/V1/ContentController.php apps/api/routes/api.php apps/api/tests/Feature/ContentTransitionTest.php
git commit -m "ContentController: listar e transicionar status de pecas

GET /projects/{id}/contents lista; PATCH /contents/{id} move o status. O fluxo
(idea->production->review->approved) e dono do servidor: mover +-1 passo, ou
arquivar de qualquer estado ativo. Pular etapa e 422. scheduled/published ficam
fora (exigem agendar+exportar). Cada transicao grava content_revisions na mesma
transacao.

Verificado por falsificacao: remover a regra abs(i-j)===1 faz idea->review
(distancia 2, ambos no fluxo) passar quando deveria ser 422.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

# FASE B — frontend

## Task B1: Generalizar o hook, migrar a `StrategyPage`

Esta é a tarefa delicada: renomeia um hook em uso e migra a `StrategyPage`. Os 11 testes da `StrategyPage` são a rede — eles rodam sem alteração e provam que nada regrediu.

**Files:**
- Create: `apps/web/src/hooks/useGeneration.ts`
- Delete: `apps/web/src/hooks/useStrategyGeneration.ts`
- Modify: `apps/web/src/components/strategy/GenerationStatus.tsx`
- Modify: `apps/web/src/pages/StrategyPage.tsx`

- [ ] **Step 1: Criar `useGeneration.ts` (generalizado)**

`apps/web/src/hooks/useGeneration.ts`:

```ts
import { useEffect, useRef } from 'react'
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

interface MessageBody {
  message: string
}

interface Config {
  projectId: string
  /** 'strategies:generate' | 'copy:generate' */
  endpoint: string
  /** A query a invalidar quando a execucao tem sucesso. */
  invalidateKey: unknown[]
}

/**
 * O unico lugar que sabe que existem URL, intervalo de polling e mutacao.
 * Serve os dois agentes: o que muda e o endpoint e a query a invalidar.
 *
 * O ai_run_id vive na query string, nao em useState: se vivesse na memoria,
 * recarregar a pagina no meio da geracao perderia o acompanhamento, e uma
 * recusa nunca mostraria o porque — o usuario clicaria em Gerar de novo e
 * levaria outra recusa.
 */
export function useGeneration({ projectId, endpoint, invalidateKey }: Config) {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const runId = params.get('run')

  // invalidateKey e um array literal recriado a cada render pelo chamador. Uma
  // ref evita que ele entre nas deps do efeito (identidade nova toda render) sem
  // precisar de JSON.stringify nem de disable de lint.
  const invalidateRef = useRef(invalidateKey)
  invalidateRef.current = invalidateKey

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
      api<{ ai_run_id: number }>(`/projects/${projectId}/${endpoint}`, {
        method: 'POST',
      }),
    onSuccess: ({ ai_run_id }) => setParams({ run: String(ai_run_id) }, { replace: true }),
  })

  const succeeded = runQuery.data?.status === 'succeeded'

  // O useQuery da v5 nao tem mais onSuccess. Este efeito e a unica forma de
  // reagir ao sucesso da execucao. Nao e descuido.
  useEffect(() => {
    if (!succeeded) return
    queryClient.invalidateQueries({ queryKey: invalidateRef.current })
    setParams({}, { replace: true })
  }, [succeeded, queryClient, setParams])

  const state = deriveState(
    runId,
    runQuery.data,
    runQuery.error,
    generation.error,
    generation.isPending,
  )

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

/** A ordem e a regra: os erros de mutacao (422/402/409) acontecem SEM criar
 *  execucao. 422 (sem estrategia ativa, so no copy) e a pre-condicao mais
 *  especifica; 402 orcamento; 409 concorrencia. Nao-retentaveis: quem precisa
 *  agir e o usuario (aprovar estrategia) ou a outra geracao (terminar). */
function deriveState(
  runId: string | null,
  run: AiRun | undefined,
  runError: unknown,
  generationError: unknown,
  generationPending: boolean,
): GenerationState {
  if (generationError instanceof ApiError && generationError.status === 422) {
    const body = generationError.body as MessageBody

    return { kind: 'failed', message: body.message, retryable: false }
  }

  if (generationError instanceof ApiError && generationError.status === 402) {
    const body = generationError.body as BudgetBody

    return { kind: 'budget', spentCents: body.spent_cents, limitCents: body.limit_cents }
  }

  if (generationError instanceof ApiError && generationError.status === 409) {
    const body = generationError.body as MessageBody

    return { kind: 'failed', message: body.message, retryable: false }
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

- [ ] **Step 2: Deletar o hook antigo**

```bash
cd apps/web && rm src/hooks/useStrategyGeneration.ts
```

- [ ] **Step 3: Atualizar o import do tipo em `GenerationStatus`**

Em `apps/web/src/components/strategy/GenerationStatus.tsx`, linha 2:

```tsx
import type { GenerationState } from '@/hooks/useGeneration'
```

- [ ] **Step 4: Migrar a `StrategyPage`**

Em `apps/web/src/pages/StrategyPage.tsx`, trocar o import (linha 7):

```tsx
import { useGeneration } from '@/hooks/useGeneration'
```

E a chamada (linha 14):

```tsx
  const { state, generate, retry, dismiss } = useGeneration({
    projectId: projectId!,
    endpoint: 'strategies:generate',
    invalidateKey: ['strategies', projectId],
  })
```

- [ ] **Step 5: Rodar os testes da `StrategyPage` — a rede de segurança**

```bash
cd apps/web && pnpm test StrategyPage
```

Esperado: PASS, os mesmos testes de antes (o caminho feliz, as falhas, a retomada por `?run`). Se algum quebrar, a extração está errada — comparar o `useGeneration` novo com o `useStrategyGeneration` que o git ainda tem no diff.

- [ ] **Step 6: Build (o tsc pega import quebrado) e commit**

```bash
cd apps/web && pnpm build && pnpm test && pnpm exec oxlint
cd ../.. && git add apps/web/src/hooks/useGeneration.ts apps/web/src/components/strategy/GenerationStatus.tsx apps/web/src/pages/StrategyPage.tsx
git rm apps/web/src/hooks/useStrategyGeneration.ts
git commit -m "Generaliza useStrategyGeneration em useGeneration

O que muda entre gerar estrategia e copy sao dois valores: o endpoint e a query
a invalidar. Todo o resto — ?run na URL, polling, precedencia de estado — e
identico. useStrategyGeneration deixa de existir; vira useGeneration parametrizado.

O 422 (sem estrategia ativa, so no copy) entra no deriveState, nao-retentavel.
A StrategyPage migra sem mudar sua assinatura; seus 11 testes rodam sem alteracao
e provam que a extracao nao regrediu.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B2: Tipos de `Content` e `groupByStatus`

**Files:**
- Modify: `apps/web/src/lib/types.ts`
- Create: `apps/web/src/lib/groupByStatus.ts`
- Test: `apps/web/src/lib/groupByStatus.test.ts`

- [ ] **Step 1: Escrever o teste que falha**

`apps/web/src/lib/groupByStatus.test.ts`:

```ts
import { expect, test } from 'vitest'
import type { Content } from '@/lib/types'
import { COLUMNS, groupByStatus } from './groupByStatus'

function content(id: number, status: Content['status']): Content {
  return {
    id,
    project_id: 1,
    title: `Peca ${id}`,
    caption: 'c',
    cta: 'x',
    hashtags: ['#a'],
    format: 'post',
    channel: 'instagram',
    status,
    source: 'ai',
    origin_ai_run_id: 7,
  }
}

test('agrupa as pecas nas colunas por status', () => {
  const groups = groupByStatus([
    content(1, 'idea'),
    content(2, 'idea'),
    content(3, 'review'),
  ])

  expect(groups.idea).toHaveLength(2)
  expect(groups.review).toHaveLength(1)
  expect(groups.production).toEqual([])
  expect(groups.approved).toEqual([])
  expect(groups.archived).toEqual([])
})

test('as colunas visiveis sao as cinco do fluxo + arquivado, nesta ordem', () => {
  expect(COLUMNS).toEqual(['idea', 'production', 'review', 'approved', 'archived'])
})

test('um status oculto (published) nao aparece em nenhuma coluna', () => {
  const groups = groupByStatus([content(1, 'published')])

  for (const col of COLUMNS) {
    expect(groups[col]).toEqual([])
  }
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test groupByStatus
```

Esperado: FAIL, `Failed to resolve import "./groupByStatus"`.

- [ ] **Step 3: Acrescentar os tipos**

No fim de `apps/web/src/lib/types.ts`:

```ts
export type ContentStatus =
  | 'idea'
  | 'production'
  | 'review'
  | 'approved'
  | 'scheduled'
  | 'published'
  | 'archived'

export interface Content {
  id: number
  project_id: number
  title: string
  caption: string | null
  cta: string | null
  hashtags: string[]
  format: 'post' | 'carousel' | 'reel' | 'story' | 'video' | 'article' | 'thread'
  channel: 'instagram' | 'facebook' | 'linkedin' | 'tiktok' | 'youtube' | 'blog'
  status: ContentStatus
  source: 'manual' | 'ai' | 'research'
  origin_ai_run_id: number | null
}
```

- [ ] **Step 4: Implementar `groupByStatus`**

`apps/web/src/lib/groupByStatus.ts`:

```ts
import type { Content, ContentStatus } from '@/lib/types'

/** As colunas visiveis, na ordem do fluxo. scheduled/published nao entram nesta
 *  fatia (dependem de agendar+exportar). */
export const COLUMNS = ['idea', 'production', 'review', 'approved', 'archived'] as const

export type Column = (typeof COLUMNS)[number]

/** Agrupa as pecas por status nas colunas visiveis. Um status fora das colunas
 *  (scheduled/published) simplesmente nao aparece — o backend nao deixa uma peca
 *  chegar la por esta tela, entao e defesa, nao caso real. */
export function groupByStatus(contents: Content[]): Record<Column, Content[]> {
  const groups = Object.fromEntries(COLUMNS.map((c) => [c, []])) as Record<Column, Content[]>

  for (const content of contents) {
    if ((COLUMNS as readonly ContentStatus[]).includes(content.status)) {
      groups[content.status as Column].push(content)
    }
  }

  return groups
}
```

- [ ] **Step 5: Rodar para ver passar**

```bash
cd apps/web && pnpm test groupByStatus
```

Esperado: PASS, 3 testes.

- [ ] **Step 6: Commitar**

```bash
cd ../.. && git add apps/web/src/lib/types.ts apps/web/src/lib/groupByStatus.ts apps/web/src/lib/groupByStatus.test.ts
git commit -m "Tipos de Content e groupByStatus

groupByStatus agrupa as pecas nas 5 colunas visiveis (idea, production, review,
approved, archived). scheduled/published nao aparecem — o backend nao deixa uma
peca chegar la por esta tela.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B3: `ContentCard` e `ContentBoard`

**Files:**
- Create: `apps/web/src/components/content/ContentCard.tsx`
- Create: `apps/web/src/components/content/ContentBoard.tsx`
- Test: `apps/web/src/components/content/ContentCard.test.tsx`

- [ ] **Step 1: Escrever o teste que falha**

`apps/web/src/components/content/ContentCard.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import type { Content } from '@/lib/types'
import { ContentCard } from './ContentCard'

function content(overrides: Partial<Content> = {}): Content {
  return {
    id: 1,
    project_id: 1,
    title: 'Vistoria completa',
    caption: 'Antes de qualquer veículo entrar no pátio, ele passa por uma vistoria.',
    cta: 'Chame no WhatsApp',
    hashtags: ['#2FAutoShop', '#OrigemVerificada'],
    format: 'reel',
    channel: 'instagram',
    status: 'idea',
    source: 'ai',
    origin_ai_run_id: 7,
    ...overrides,
  }
}

const noop = { onAdvance: vi.fn(), onBack: vi.fn(), onArchive: vi.fn(), pending: false }

test('mostra formato, canal, titulo e o chip Gerado por IA', () => {
  render(<ContentCard content={content()} {...noop} />)

  expect(screen.getByText(/reel/i)).toBeInTheDocument()
  expect(screen.getByText(/instagram/i)).toBeInTheDocument()
  expect(screen.getByText('Vistoria completa')).toBeInTheDocument()
  expect(screen.getByText(/gerado por ia/i)).toBeInTheDocument()
})

test('peca manual nao mostra o chip de IA', () => {
  render(<ContentCard content={content({ source: 'manual' })} {...noop} />)

  expect(screen.queryByText(/gerado por ia/i)).not.toBeInTheDocument()
})

test('em idea, Voltar esta desabilitado e Avancar habilitado', () => {
  render(<ContentCard content={content({ status: 'idea' })} {...noop} />)

  expect(screen.getByRole('button', { name: /voltar/i })).toBeDisabled()
  expect(screen.getByRole('button', { name: /avançar/i })).toBeEnabled()
})

test('em approved, Avancar esta desabilitado', () => {
  render(<ContentCard content={content({ status: 'approved' })} {...noop} />)

  expect(screen.getByRole('button', { name: /avançar/i })).toBeDisabled()
})

test('em archived, os tres botoes estao desabilitados', () => {
  render(<ContentCard content={content({ status: 'archived' })} {...noop} />)

  expect(screen.getByRole('button', { name: /voltar/i })).toBeDisabled()
  expect(screen.getByRole('button', { name: /avançar/i })).toBeDisabled()
  expect(screen.getByRole('button', { name: /arquivar/i })).toBeDisabled()
})

test('Avancar chama onAdvance', async () => {
  const user = userEvent.setup()
  const onAdvance = vi.fn()
  render(<ContentCard content={content()} {...noop} onAdvance={onAdvance} />)

  await user.click(screen.getByRole('button', { name: /avançar/i }))
  expect(onAdvance).toHaveBeenCalledOnce()
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test ContentCard
```

Esperado: FAIL, `Failed to resolve import "./ContentCard"`.

- [ ] **Step 3: Implementar `ContentCard`**

`apps/web/src/components/content/ContentCard.tsx`:

```tsx
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import type { Content, ContentStatus } from '@/lib/types'

interface Props {
  content: Content
  pending: boolean
  onAdvance: () => void
  onBack: () => void
  onArchive: () => void
}

const CHIPS: Record<ContentStatus, string> = {
  idea: 'Ideia',
  production: 'Produção',
  review: 'Revisão',
  approved: 'Aprovado',
  scheduled: 'Agendado',
  published: 'Publicado',
  archived: 'Arquivado',
}

// A mesma ordem do FLOW do backend. No cliente e so para habilitar/desabilitar;
// o servidor valida de verdade (um botao habilitado errado vira 422, nao dano).
const FLOW: ContentStatus[] = ['idea', 'production', 'review', 'approved']

export function ContentCard({ content, pending, onAdvance, onBack, onArchive }: Props) {
  const i = FLOW.indexOf(content.status)
  const isArchived = content.status === 'archived'
  const canBack = i > 0 && ! isArchived
  const canAdvance = i >= 0 && i < FLOW.length - 1 && ! isArchived

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <span className="text-label-sm text-on-surface-variant">
          {content.format} · {content.channel}
        </span>
        <span className="text-label-sm bg-surface-container text-on-surface-variant shrink-0 rounded-full px-2 py-0.5">
          {CHIPS[content.status]}
        </span>
      </div>

      <h3 className="text-label-md text-on-surface mt-2">{content.title}</h3>

      {content.caption && (
        <p className="text-body-sm text-on-surface-variant mt-1 line-clamp-3">{content.caption}</p>
      )}

      {content.cta && (
        <p className="text-body-sm text-on-surface mt-2">CTA: {content.cta}</p>
      )}

      {content.hashtags.length > 0 && (
        <p className="text-body-sm text-primary mt-2">{content.hashtags.join(' ')}</p>
      )}

      {content.source === 'ai' && (
        <span className="text-label-sm bg-secondary-container text-secondary mt-3 inline-block rounded-full px-2 py-0.5">
          Gerado por IA
        </span>
      )}

      <div className="mt-4 flex gap-2">
        <Button size="sm" variant="secondary" disabled={pending || ! canBack} onClick={onBack}>
          ← Voltar
        </Button>
        <Button size="sm" disabled={pending || ! canAdvance} onClick={onAdvance}>
          Avançar →
        </Button>
        <Button size="sm" variant="ghost" disabled={pending || isArchived} onClick={onArchive}>
          Arquivar
        </Button>
      </div>
    </Card>
  )
}
```

- [ ] **Step 4: Rodar para ver passar**

```bash
cd apps/web && pnpm test ContentCard
```

Esperado: PASS, 6 testes.

- [ ] **Step 5: Implementar `ContentBoard`** (sem teste próprio — exercitado pela `ContentPage` na B4)

`apps/web/src/components/content/ContentBoard.tsx`:

```tsx
import { ContentCard } from '@/components/content/ContentCard'
import { COLUMNS, type Column } from '@/lib/groupByStatus'
import type { Content } from '@/lib/types'

interface Props {
  groups: Record<Column, Content[]>
  pending: boolean
  onMove: (id: number, status: Content['status']) => void
}

const TITLES: Record<Column, string> = {
  idea: 'Ideia',
  production: 'Produção',
  review: 'Revisão',
  approved: 'Aprovado',
  archived: 'Arquivado',
}

// A ordem do fluxo, para calcular o proximo/anterior status ao mover.
const FLOW: Content['status'][] = ['idea', 'production', 'review', 'approved']

export function ContentBoard({ groups, pending, onMove }: Props) {
  return (
    <div className="flex gap-4 overflow-x-auto">
      {COLUMNS.map((col) => (
        <section key={col} className="w-72 shrink-0">
          <h2 className="text-label-md text-on-surface">
            {TITLES[col]} ({groups[col].length})
          </h2>

          <div className="mt-3 flex flex-col gap-3">
            {groups[col].map((content) => {
              const i = FLOW.indexOf(content.status)

              return (
                <ContentCard
                  key={content.id}
                  content={content}
                  pending={pending}
                  onAdvance={() => i >= 0 && i < FLOW.length - 1 && onMove(content.id, FLOW[i + 1])}
                  onBack={() => i > 0 && onMove(content.id, FLOW[i - 1])}
                  onArchive={() => onMove(content.id, 'archived')}
                />
              )
            })}
          </div>
        </section>
      ))}
    </div>
  )
}
```

- [ ] **Step 6: Build, lint, commit**

```bash
cd apps/web && pnpm build && pnpm test && pnpm exec oxlint
cd ../.. && git add apps/web/src/components/content/ContentCard.tsx apps/web/src/components/content/ContentBoard.tsx apps/web/src/components/content/ContentCard.test.tsx
git commit -m "ContentCard e ContentBoard

ContentCard: peca apresentacional com chip de status, chip 'Gerado por IA'
quando source=ai, e Voltar/Avancar/Arquivar habilitados por status. A logica de
habilitar espelha o FLOW do backend — no cliente e so sugestao, o servidor valida.

ContentBoard: as 5 colunas do fluxo; calcula o proximo/anterior status ao mover.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B4: A `ContentPage` e a rota

**Files:**
- Create: `apps/web/src/pages/ContentPage.tsx`
- Modify: `apps/web/src/main.tsx`
- Modify: `apps/web/src/pages/StrategyPage.tsx`
- Test: `apps/web/src/pages/ContentPage.test.tsx`

- [ ] **Step 1: Escrever os testes que falham**

`apps/web/src/pages/ContentPage.test.tsx`:

```tsx
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { server } from '@/test/server'
import { renderWithProviders } from '@/test/utils'
import type { AiRun, Content } from '@/lib/types'
import { ContentPage } from './ContentPage'

const ROUTE = { path: '/projects/:projectId/content', entry: '/projects/1/content' }

function content(id: number, status: Content['status']): Content {
  return {
    id,
    project_id: 1,
    title: `Peca ${id}`,
    caption: 'Legenda da peca.',
    cta: 'Chame no WhatsApp',
    hashtags: ['#marca'],
    format: 'post',
    channel: 'instagram',
    status,
    source: 'ai',
    origin_ai_run_id: 7,
  }
}

function run(overrides: Partial<AiRun>): AiRun {
  return {
    id: 42,
    agent: 'copywriter',
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

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  vi.useRealTimers()
})

function setup() {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
}

test('lista vazia mostra o botão gerar e a frase de vazio', async () => {
  server.use(http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })))

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByRole('button', { name: /gerar conteúdo/i })).toBeInTheDocument()
  expect(screen.getByText(/ainda não há conteúdo/i)).toBeInTheDocument()
})

test('agrupa as peças: 5 em Ideia, colunas seguintes vazias', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () =>
      HttpResponse.json({ data: [1, 2, 3, 4, 5].map((i) => content(i, 'idea')) }),
    ),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByText('Ideia (5)')).toBeInTheDocument()
  expect(screen.getByText('Produção (0)')).toBeInTheDocument()
  expect(screen.getByText('Aprovado (0)')).toBeInTheDocument()
})

test('avançar uma peça faz PATCH com o próximo status', async () => {
  const user = setup()
  let recebido: unknown = null

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
    http.patch('/api/v1/contents/1', async ({ request }) => {
      recebido = await request.json()
      return HttpResponse.json({ data: { ...content(1, 'production') } })
    }),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /avançar/i }))

  await waitFor(() => expect(recebido).toEqual({ status: 'production' }))
})

test('o card de source=ai mostra o chip Gerado por IA', async () => {
  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [content(1, 'idea')] })),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  expect(await screen.findByText(/gerado por ia/i)).toBeInTheDocument()
})

test('gerar: clique dispara POST e mostra o estado de geração', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })),
    http.post('/api/v1/projects/1/copy:generate', () =>
      HttpResponse.json({ ai_run_id: 42 }, { status: 202 }),
    ),
    http.get('/api/v1/ai-runs/42', () => HttpResponse.json(run({ status: 'running' }))),
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar conteúdo/i }))

  expect(await screen.findByText(/gerando/i)).toBeInTheDocument()
})

test('422 sem estratégia ativa mostra a mensagem e não inicia polling', async () => {
  const user = setup()

  server.use(
    http.get('/api/v1/projects/1/contents', () => HttpResponse.json({ data: [] })),
    http.post('/api/v1/projects/1/copy:generate', () =>
      HttpResponse.json({ message: 'Aprove uma estrategia antes de gerar conteudo.' }, { status: 422 }),
    ),
    // Sem handler /ai-runs/*: se a tela fizer polling, o MSW estoura.
  )

  renderWithProviders(<ContentPage />, ROUTE)

  await user.click(await screen.findByRole('button', { name: /gerar conteúdo/i }))

  expect(await screen.findByText(/aprove uma estrategia/i)).toBeInTheDocument()
  expect(screen.queryByText(/gerando/i)).not.toBeInTheDocument()
})
```

- [ ] **Step 2: Rodar para ver falhar**

```bash
cd apps/web && pnpm test ContentPage
```

Esperado: FAIL, `Failed to resolve import "./ContentPage"`.

- [ ] **Step 3: Implementar a `ContentPage`**

`apps/web/src/pages/ContentPage.tsx`:

```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ContentBoard } from '@/components/content/ContentBoard'
import { GenerationStatus } from '@/components/strategy/GenerationStatus'
import { Button } from '@/components/ui/Button'
import { Shell } from '@/components/ui/Shell'
import { useGeneration } from '@/hooks/useGeneration'
import { api } from '@/lib/api'
import { groupByStatus } from '@/lib/groupByStatus'
import type { Content } from '@/lib/types'

export function ContentPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const { state, generate, retry, dismiss } = useGeneration({
    projectId: projectId!,
    endpoint: 'copy:generate',
    invalidateKey: ['contents', projectId],
  })

  const contents = useQuery({
    queryKey: ['contents', projectId],
    queryFn: () => api<{ data: Content[] }>(`/projects/${projectId}/contents`),
  })

  const move = useMutation({
    mutationFn: ({ id, status }: { id: number; status: Content['status'] }) =>
      api(`/contents/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] }),
  })

  if (contents.isPending) return <Shell>Carregando…</Shell>
  if (contents.isError) return <Shell>Projeto não encontrado.</Shell>

  const pieces = contents.data.data
  const generating = state.kind === 'starting' || state.kind === 'running'

  return (
    <Shell>
      <Link
        to={`/projects/${projectId}/strategy`}
        className="text-body-sm text-on-surface-variant hover:text-primary"
      >
        ← Estratégia
      </Link>

      <div className="mt-4 flex items-center justify-between gap-4">
        <h1 className="text-display-lg text-on-surface">Conteúdo</h1>
        <Button disabled={generating} onClick={generate}>
          Gerar conteúdo
        </Button>
      </div>

      <GenerationStatus state={state} onRetry={retry} onDismiss={dismiss} />

      <div className="mt-8">
        {pieces.length === 0 && state.kind === 'idle' ? (
          <p className="text-body-lg text-on-surface-variant">
            Ainda não há conteúdo. Gere a partir da estratégia ativa.
          </p>
        ) : (
          <ContentBoard
            groups={groupByStatus(pieces)}
            pending={move.isPending}
            onMove={(id, status) => move.mutate({ id, status })}
          />
        )}
      </div>
    </Shell>
  )
}
```

- [ ] **Step 4: Registrar a rota**

Em `apps/web/src/main.tsx`, acrescentar o import junto dos outros:

```tsx
import { ContentPage } from '@/pages/ContentPage'
```

E a rota, depois da rota de `strategy`:

```tsx
          <Route
            path="/projects/:projectId/content"
            element={
              <RequireAuth>
                <ContentPage />
              </RequireAuth>
            }
          />
```

- [ ] **Step 5: Link "Ver conteúdo" na `StrategyPage`**

Em `apps/web/src/pages/StrategyPage.tsx`, logo depois do parágrafo introdutório (o `<p>` que termina em "próximos meses."), acrescentar:

```tsx
      <Link
        to={`/projects/${projectId}/content`}
        className="text-body-sm text-primary mt-4 inline-block hover:underline"
      >
        Ver conteúdo →
      </Link>
```

O `Link` já está importado na `StrategyPage`.

- [ ] **Step 6: Rodar para ver passar**

```bash
cd apps/web && pnpm test ContentPage
```

Esperado: PASS, 6 testes.

- [ ] **Step 7: Suíte, build, lint**

```bash
cd apps/web && pnpm test && pnpm build && pnpm exec oxlint
```

Esperado: todos verdes; build sem erro; oxlint só com os dois warnings pré-existentes.

- [ ] **Step 8: Commitar**

```bash
cd ../.. && git add apps/web/src/pages/ContentPage.tsx apps/web/src/pages/ContentPage.test.tsx apps/web/src/main.tsx apps/web/src/pages/StrategyPage.tsx
git commit -m "ContentPage: biblioteca por status, com geracao

Board por status reusando ContentBoard; botao Gerar conteudo reusando
useGeneration (endpoint copy:generate, invalida ['contents']). Estado vazio
oferece gerar; 422 sem estrategia ativa mostra a mensagem sem polling. Rota
/projects/:projectId/content e link 'Ver conteudo' a partir da Estrategia.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task B5: Verificação no browser

Não produz commit.

- [ ] **Step 1: Postgres + serve + queue:work + vite**

```bash
"C:/Users/mysho/bin/pgsql16/bin/pg_ctl.exe" -D "C:/Users/mysho/pgdata/16" -l "C:/Users/mysho/pgdata/pg16.log" -o "-p 5433" start
cd apps/api && php artisan serve       # terminal 1
cd apps/api && php artisan queue:work  # terminal 2
cd apps/web && pnpm dev                # terminal 3
```

(Deixar `AI_PROVIDER=mock` — a verificação no browser usa o mock, que devolve 5 peças. A geração real do copywriter já foi verificada na fatia anterior.)

- [ ] **Step 2: Garantir estratégia ativa no projeto 1**

```bash
cd apps/api && php artisan tinker --execute="
\$s = \App\Models\Strategy::withoutGlobalScopes()->where('project_id',1)->latest()->first();
if (\$s) { \$s->update(['status'=>'active']); print('estrategia '.\$s->id.' -> active'.PHP_EOL); }
"
```

- [ ] **Step 3: Percorrer**

Logar em http://localhost:5173, ir ao projeto 1, à Estratégia, clicar em "Ver conteúdo →". Verificar:

1. Estado vazio: "Ainda não há conteúdo" + botão "Gerar conteúdo".
2. Clicar em Gerar. Aparece "Gerando sua estratégia…" (a mensagem do `GenerationStatus` é genérica) e, em ~1-2s (mock), 5 peças aparecem na coluna **Ideia (5)**.
3. Cada card mostra format·channel, título, caption, CTA, hashtags, chip "Gerado por IA".
4. Numa peça, clicar "Avançar →". Ela migra para **Produção**. Clicar de novo → **Revisão** → **Aprovado**. Em Aprovado, "Avançar" desabilita.
5. "Voltar" traz de volta um passo. "Arquivar" leva para **Arquivado**.
6. Conferir no banco que `content_revisions` registrou os movimentos:

```bash
export PGPASSWORD="$DB_PASSWORD"  # vem do apps/api/.env, nunca escrita aqui
"C:/Users/mysho/bin/pgsql16/bin/psql.exe" -h 127.0.0.1 -p 5433 -U postgres -d 2m_social_ai -Atc \
 "select from_status||' -> '||to_status||' (user '||user_id||')' from content_revisions order by id desc limit 5;"
```

- [ ] **Step 4: Limpar**

- Apagar as peças de teste do projeto 1: `delete from contents where project_id=1;` (e as revisões caem por cascade).
- Estratégia 1 de volta a `draft` se você a ativou só para o teste.
- Derrubar `serve`, `queue:work`, `pnpm dev`.
- `git status --porcelain` vazio.

---

## Critério de sucesso

- `php artisan test` verde — `ContentTransitionTest` (13: 2 model + 10 controller + 1 falsificação) e os anteriores intactos.
- `pnpm test` verde — `groupByStatus` (3), `ContentCard` (6), `ContentPage` (6), e os **11 da `StrategyPage` sem alteração**.
- `pnpm build && pnpm exec oxlint` limpo.
- No browser: gerar → 5 peças em Ideia → avançar até Aprovado → `content_revisions` gravado.
- `git status --porcelain` vazio ao fim.
