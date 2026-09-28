<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Project;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContentController extends Controller
{
    /**
     * O fluxo linear que o humano percorre. `scheduled` NAO entra aqui: agendar e do
     * social_media, porque exige uma data, e este PATCH so carrega status — se
     * `scheduled` fosse o quinto elemento, o botao "Avancar" de uma peca aprovada
     * habilitaria no board e o clique tomaria 422. `published` exige publicar, que
     * nao existe. `archived` e terminal, alcancavel de qualquer estado ativo.
     */
    private const FLOW = ['idea', 'production', 'review', 'approved'];

    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            // A ultima review e a ultima sugestao de SEO vem juntas: o board mostra
            // veredito e sugestao sem uma chamada por card.
            'data' => $project->contents()->with(['latestReview', 'latestSeo', 'latestTextRevision', 'approver', 'image', 'latestPublication'])->latest()->get(),
        ]);
    }

    /**
     * Dois gestos, um endpoint: mover no fluxo (`status`) ou remarcar (`scheduled_for`).
     * Um por requisicao — juntos, a revisao nao saberia contar o que aconteceu.
     */
    public function update(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate([
            'status' => ['required_without:scheduled_for', 'prohibits:scheduled_for', 'string'],
            'scheduled_for' => ['required_without:status', 'date'],
        ]);

        // Com a publicacao em andamento, a peca nao anda nem muda de hora: o worker
        // pode estar no meio da conversa com a Meta.
        if ($emAndamento = $this->publicacaoEmAndamento($content)) {
            return $emAndamento;
        }

        if (isset($data['scheduled_for'])) {
            return $this->reschedule($content, $data['scheduled_for']);
        }

        $from = $content->status;
        $to = $data['status'];

        if (! $this->isValidTransition($from, $to)) {
            return response()->json([
                'message' => "Transicao invalida de '{$from}' para '{$to}'.",
            ], 422);
        }

        // Aprovar e o gesto que autoriza o sistema a publicar (ADR-13): so reviewer+.
        // Editor escreve e manda para revisao; quem aprova responde pelo que vai ao ar.
        if ($this->isAprovacao($from, $to)
            && ! $content->project->workspace->roleFor($request->user())?->atLeast(WorkspaceRole::Reviewer)) {
            return response()->json([
                'message' => 'Só quem revisa pode aprovar uma peça.',
            ], 403);
        }

        DB::transaction(function () use ($content, $from, $to, $request) {
            $content->update([
                'status' => $to,
                // Desagendar limpa a data: sem isto a peca voltaria para Aprovado
                // carregando uma data fantasma, e o board a mostraria agendada.
                ...$this->isDesagendamento($from, $to) ? ['scheduled_for' => null] : [],
                // A aprovacao tem nome e hora. Devolver para revisao a desfaz: o que
                // voltar a ser aprovado sera aprovado de novo, por alguem.
                ...$this->isAprovacao($from, $to) ? ['approved_by' => $request->user()->id, 'approved_at' => now()] : [],
                ...$from === 'approved' && $to === 'review' ? ['approved_by' => null, 'approved_at' => null] : [],
            ]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'from_status' => $from,
                'to_status' => $to,
            ]);
        });

        return response()->json(['data' => $content->refresh()->load('approver')]);
    }

    /**
     * Arquivar deixa de ser ponto final. O rewriter conserta peca reprovada — e o
     * texto novo ficava preso no arquivo, sem poder ser revisado nem publicado.
     *
     * Nao entra no PATCH como um `status` qualquer porque quem decide o destino e o
     * servidor, nao o cliente: a peca volta de ONDE SAIU, e so o historico sabe.
     */
    public function unarchive(Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        if ($content->status !== 'archived') {
            return response()->json([
                'message' => 'Só uma peça arquivada pode ser desarquivada.',
            ], 422);
        }

        $to = $this->statusAntesDoArquivamento($content);

        DB::transaction(function () use ($content, $to) {
            $content->update(['status' => $to]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'from_status' => 'archived',
                'to_status' => $to,
            ]);
        });

        return response()->json(['data' => $content->refresh()]);
    }

    /**
     * De onde a peca saiu: a ultima vez que ela FOI arquivada. O historico ja sabia —
     * toda transicao grava a revisao, desde antes de desarquivar existir.
     *
     * Sem revisao registrada, `idea`: peca arquivada direto no banco (as mock, a peca
     * 10 movida por SQL) nao tem de onde voltar, e o comeco do fluxo e o unico palpite
     * honesto.
     */
    private function statusAntesDoArquivamento(Content $content): string
    {
        return ContentRevision::where('content_id', $content->id)
            ->where('to_status', 'archived')
            ->latest('id')
            ->value('from_status') ?? 'idea';
    }

    /**
     * Remarcar so faz sentido para quem tem data: uma peca em `idea` nao tem o que
     * mudar. A revisao sai sem status (ambos nulos) e com `changes` contando a
     * mudanca — o historico registra data, nao so coluna.
     */
    private function reschedule(Content $content, string $quando): JsonResponse
    {
        if ($content->status !== 'scheduled') {
            return response()->json([
                'message' => 'Só uma peça agendada pode ser remarcada.',
            ], 422);
        }

        $de = $content->scheduled_for;
        $para = self::horaLocal($quando, $content->project->timezone);

        DB::transaction(function () use ($content, $de, $para) {
            $content->update(['scheduled_for' => $para]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => request()->user()->id,
                'changes' => ['scheduled_for' => [
                    'from' => $de?->toDateTimeString(),
                    'to' => $para->toDateTimeString(),
                ]],
            ]);
        });

        return response()->json(['data' => $content->refresh()]);
    }

    /**
     * O editor de publicacao: o humano corrige o texto que a IA escreveu. So antes da
     * aprovacao (ADR-13) — depois, o texto e o que foi aprovado. Grava a revisao com o
     * de-para, como a reescrita e o SEO: e o que faz o veredito do revisor ficar velho
     * e o PublishGate exigir nova aprovacao se algo escapar.
     */
    public function updateDraft(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:2200'],
            'cta' => ['sometimes', 'nullable', 'string', 'max:280'],
            'hashtags' => ['sometimes', 'array', 'max:30'],
            'hashtags.*' => ['string', 'max:100', 'regex:/^#?[\pL\pN_]+$/u'],
        ], [
            'hashtags.max' => 'O Instagram aceita até 30 hashtags.',
            'hashtags.*.regex' => 'Hashtag só tem letras, números e _ (sem espaço).',
        ]);

        if (! in_array($content->status, ['idea', 'production', 'review'], true)) {
            return response()->json([
                'message' => 'Peça aprovada não muda o texto. Devolva para revisão antes.',
            ], 422);
        }

        $mudancas = [];

        foreach ($data as $campo => $valor) {
            if ($content->{$campo} !== $valor) {
                $mudancas[$campo] = ['from' => $content->{$campo}, 'to' => $valor];
            }
        }

        if ($mudancas !== []) {
            DB::transaction(function () use ($content, $data, $mudancas, $request) {
                $content->update($data);
                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $request->user()->id,
                    'changes' => $mudancas,
                ]);
            });
        }

        return response()->json(['data' => $content->refresh()->load(['image', 'latestTextRevision'])]);
    }

    /**
     * Agendar a mao: a peca aprovada ganha data e vai para o calendario — e, sendo do
     * Instagram, para a fila de publicacao quando a hora chegar (ADR-13). O agente
     * social_media continua existindo para distribuir um lote; este e o gesto de uma
     * peca so.
     */
    public function schedule(Request $request, Content $content): JsonResponse
    {
        Gate::authorize('update', $content->project);

        $data = $request->validate(['scheduled_for' => ['required', 'date']]);

        if ($content->status !== 'approved') {
            return response()->json([
                'message' => 'Só uma peça aprovada pode ser agendada.',
            ], 422);
        }

        $quando = self::horaLocal($data['scheduled_for'], $content->project->timezone);

        if ($quando->lt(now()->addMinute())) {
            return response()->json([
                'message' => 'Escolha um horário no futuro.',
                'errors' => ['scheduled_for' => ['Escolha um horário no futuro.']],
            ], 422);
        }

        DB::transaction(function () use ($content, $quando, $request) {
            $content->update(['status' => 'scheduled', 'scheduled_for' => $quando->utc()]);
            ContentRevision::create([
                'content_id' => $content->id,
                'user_id' => $request->user()->id,
                'from_status' => 'approved',
                'to_status' => 'scheduled',
            ]);
        });

        return response()->json(['data' => $content->refresh()->load('approver')]);
    }

    /**
     * A hora que chega sem fuso ("2026-10-01T08:30", o que o <input datetime-local>
     * manda) e a hora do PROJETO — o fuso do publico da marca. Antes era lida como UTC
     * (o fuso da aplicacao) e a peca remarcada para 08:30 ia ao ar as 05:30 em Sao
     * Paulo. Com offset explicito, vale o offset.
     */
    private static function horaLocal(string $quando, ?string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($quando, $timezone ?? 'UTC')->utc();
    }

    private function publicacaoEmAndamento(Content $content): ?JsonResponse
    {
        $viva = Publication::where('content_id', $content->id)
            ->whereIn('status', Publication::IN_FLIGHT)
            ->exists();

        return $viva ? response()->json([
            'message' => 'A publicação desta peça está em andamento. Aguarde o resultado.',
        ], 409) : null;
    }

    /**
     * Arquivar de qualquer estado ativo, desagendar, ou mover +-1 passo no fluxo
     * linear. Agendar (`approved -> scheduled`) nao esta aqui de proposito: e o
     * social_media que agenda.
     */
    private function isValidTransition(string $from, string $to): bool
    {
        if ($to === 'archived') {
            return $from !== 'archived';
        }

        if ($this->isDesagendamento($from, $to)) {
            return true;
        }

        $i = array_search($from, self::FLOW, true);
        $j = array_search($to, self::FLOW, true);

        return $i !== false && $j !== false && abs($i - $j) === 1;
    }

    private function isAprovacao(string $from, string $to): bool
    {
        return $from === 'review' && $to === 'approved';
    }

    private function isDesagendamento(string $from, string $to): bool
    {
        return $from === 'scheduled' && $to === 'approved';
    }
}
