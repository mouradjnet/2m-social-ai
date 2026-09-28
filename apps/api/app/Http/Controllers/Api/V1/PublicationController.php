<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Publishing\Dispatcher;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * O historico das publicacoes e os dois gestos humanos sobre ele: tentar de novo o
 * que falhou, e decidir o que a Meta deixou sem resposta.
 */
class PublicationController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => Publication::where('project_id', $project->id)
                ->with(['content:id,title,status', 'approver'])
                ->latest('scheduled_for')
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function show(Publication $publication): JsonResponse
    {
        Gate::authorize('view', $publication->content->project);

        return response()->json([
            'data' => $publication->load(['content:id,title,status', 'approver', 'attemptsLog']),
        ]);
    }

    /**
     * Tentar de novo = remarcar a peca para agora. O horario novo abre uma publicacao
     * nova (a unicidade e por peca e horario), e a que falhou fica no historico como
     * estava. So vale para falha: `unknown` nao se repete — decide-se (resolve).
     */
    public function retry(Request $request, Publication $publication, Dispatcher $dispatcher): JsonResponse
    {
        $content = $publication->content;
        Gate::authorize('update', $content->project);

        if (! in_array($publication->status, ['failed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Só uma publicação que falhou pode ser tentada de novo.',
            ], 422);
        }

        $maisRecente = Publication::where('content_id', $content->id)->latest('id')->value('id');

        if ($maisRecente !== $publication->id || $content->status !== 'scheduled') {
            return response()->json([
                'message' => 'Esta peça já tem uma publicação mais nova ou não está mais agendada.',
            ], 409);
        }

        $de = $content->scheduled_for;
        $agora = now()->startOfSecond();

        DB::transaction(function () use ($content, $de, $agora, $request) {
            $content->update(['scheduled_for' => $agora]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => $request->user()->id,
                'changes' => ['scheduled_for' => [
                    'from' => $de?->toDateTimeString(),
                    'to' => $agora->toDateTimeString(),
                ]],
            ]);
        });

        // Com a fila `sync` o job ja rodou; com worker, ela volta `pending`. Em ambos,
        // o que o banco diz agora.
        $nova = $dispatcher->prepare($content->refresh()->load('image'))?->refresh();

        return response()->json(['data' => $nova], 201);
    }

    /**
     * A Meta nao confirmou e a reconciliacao nao conseguiu decidir. Um humano olha o
     * perfil no Instagram e diz o que aconteceu. So `reviewer`+: e responder pelo que
     * esta (ou nao esta) no ar.
     */
    public function resolve(Request $request, Publication $publication): JsonResponse
    {
        $content = $publication->content;
        Gate::authorize('update', $content->project);

        if (! $content->project->workspace->roleFor($request->user())?->atLeast(WorkspaceRole::Reviewer)) {
            return response()->json(['message' => 'Só quem revisa decide o resultado de uma publicação.'], 403);
        }

        $data = $request->validate([
            'outcome' => ['required', 'in:published,failed'],
            'permalink' => ['nullable', 'url:https', 'max:500'],
        ]);

        if ($publication->status !== 'unknown') {
            return response()->json(['message' => 'Só uma publicação com resultado desconhecido é decidida à mão.'], 422);
        }

        DB::transaction(function () use ($publication, $content, $data, $request) {
            $publicada = $data['outcome'] === 'published';

            $publication->update([
                'status' => $data['outcome'],
                'permalink' => $data['permalink'] ?? $publication->permalink,
                'published_at' => $publicada ? now() : null,
                'next_attempt_at' => null,
                'last_error' => $publicada ? null : 'Marcada como não publicada por quem conferiu no Instagram.',
            ]);

            PublicationAttempt::create([
                'publication_id' => $publication->id,
                'number' => $publication->attempts,
                'step' => 'resolve',
                'outcome' => $publicada ? 'success' : 'refused',
                'message' => "Decidido por {$request->user()->name}: ".($publicada ? 'está no ar.' : 'não saiu.'),
            ]);

            if ($publicada && $content->status === 'scheduled') {
                $content->update(['status' => 'published', 'published_at' => now()]);
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $request->user()->id,
                    'from_status' => 'scheduled',
                    'to_status' => 'published',
                ]);
            }
        });

        return response()->json(['data' => $publication->refresh()]);
    }
}
