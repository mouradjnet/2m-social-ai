<?php

namespace App\Domain\Editorial;

use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CP-04C: as versoes de uma peca. Cada vez que o que vai ao ar muda (a `version` sobe
 * no model — ver Content::booted()), grava-se uma linha imutavel em content_versions
 * com o snapshot (o MESMO de Approval::snapshot, entao o hash da aprovacao e o hash da
 * versao aprovada sao iguais) e os metadados das midias.
 *
 * Quem e de onde: o contexto aberto com como() (IA, SEO, restauracao...). Sem
 * contexto, o usuario autenticado e `manual_edit`, ou `system` fora de requisicao.
 */
class Versioning
{
    public const ORIGINS = [
        'created', 'manual_edit', 'ai_generation', 'ai_rewrite', 'ai_image', 'seo', 'restore', 'backfill', 'system',
    ];

    /** @var list<array{origin: string, user_id: ?int, ai_run_id: ?int, restored_from: ?int}> */
    private static array $contexto = [];

    /**
     * Roda $fn com a origem das versoes que ela gerar.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function como(string $origin, ?int $userId, callable $fn, ?int $aiRunId = null, ?int $restoredFrom = null): mixed
    {
        self::$contexto[] = ['origin' => $origin, 'user_id' => $userId, 'ai_run_id' => $aiRunId, 'restored_from' => $restoredFrom];

        try {
            return $fn();
        } finally {
            array_pop(self::$contexto);
        }
    }

    /** Grava a versao ATUAL da peca. O unico (content_id, version) recusa numero repetido. */
    public static function record(Content $content, bool $invalidouAprovacao = false): ContentVersion
    {
        $ctx = end(self::$contexto) ?: [
            'origin' => Auth::id() !== null ? ((int) $content->version === 1 ? 'created' : 'manual_edit') : 'system',
            'user_id' => Auth::id(),
            'ai_run_id' => null,
            'restored_from' => null,
        ];

        $snapshot = Approval::snapshot($content);

        return ContentVersion::create([
            'workspace_id' => $content->workspace_id,
            'project_id' => $content->project_id,
            'content_id' => $content->id,
            'version' => (int) $content->version,
            'snapshot' => $snapshot,
            'snapshot_hash' => Approval::hash($snapshot),
            'media' => self::midias($snapshot),
            'origin' => $ctx['origin'],
            'restored_from_version' => $ctx['restored_from'],
            'invalidated_approval' => $invalidouAprovacao,
            'user_id' => $ctx['user_id'],
            'ai_run_id' => $ctx['ai_run_id'],
        ]);
    }

    /**
     * Concorrencia otimista das edicoes humanas. Chamar DENTRO da transacao da edicao:
     * trava a linha e confere que a peca ainda esta na versao que a pessoa viu.
     *
     * @throws VersionConflict
     */
    public static function exigir(Content $content, int $esperada): void
    {
        $atual = (int) Content::withoutGlobalScopes()->whereKey($content->id)->lockForUpdate()->value('version');

        if ($atual !== $esperada) {
            throw new VersionConflict(
                "A peça mudou (versão {$atual}) desde que você a abriu (versão {$esperada}). Recarregue antes de editar.",
                $atual,
            );
        }

        // O model em memoria precisa da versao do banco: e dela que o hook sobe +1.
        $content->setRawAttributes(['version' => $atual] + $content->getAttributes(), true);
    }

    /**
     * Restaura o conteudo de uma versao anterior como uma versao NOVA. Nunca herda
     * aprovacao: o numero e outro, e a aprovacao vale para um numero (e um hash). Se a
     * peca estava aprovada, a aprovacao cai pelo caminho normal do model.
     *
     * @throws VersionConflict versao esperada velha
     * @throws ApprovalConflict estado nao permite
     * @throws RestoreRefused versao inexistente, identica a atual, ou midia que sumiu
     */
    public static function restore(Content $content, int $deVersao, int $esperada, User $user): ContentVersion
    {
        return DB::transaction(function () use ($content, $deVersao, $esperada, $user) {
            self::exigir($content, $esperada);
            $content->refresh();

            if (! in_array($content->status, ['idea', 'production', 'review', 'approved'], true)) {
                throw new ApprovalConflict($content->status === 'scheduled'
                    ? 'A peça está agendada: desagende antes de restaurar.'
                    : "A peça está em '{$content->status}': não dá para restaurar versão nela.");
            }

            $alvo = ContentVersion::where('content_id', $content->id)->where('version', $deVersao)->first()
                ?? throw new RestoreRefused("A versão {$deVersao} não existe nesta peça.");

            // O snapshot leva o numero da versao: compara-se so o conteudo.
            $semNumero = fn (array $s) => array_diff_key($s, ['version' => 0]);

            if (Approval::hash($semNumero($alvo->snapshot)) === Approval::hash($semNumero(Approval::snapshot($content)))) {
                throw new RestoreRefused("A versão {$deVersao} já é o conteúdo atual.");
            }

            $s = $alvo->snapshot;
            self::conferirMidias($content, $s);

            return self::como('restore', $user->id, function () use ($content, $s, $deVersao, $esperada, $user) {
                $slidesAtuais = $content->slides()->pluck('assets.id')->map(fn ($id) => (int) $id)->all();
                $slides = array_map('intval', $s['slide_asset_ids'] ?? []);

                if ($slidesAtuais !== $slides) {
                    DB::table('content_slides')->where('content_id', $content->id)->delete();
                    DB::table('content_slides')->insert(array_map(
                        fn (int $id, int $i) => ['content_id' => $content->id, 'asset_id' => $id, 'position' => $i],
                        $slides, array_keys($slides),
                    ));
                }

                $content->fill([
                    'title' => $s['title'],
                    'caption' => $s['caption'] === '' ? null : $s['caption'],
                    'cta' => $s['cta'] === '' ? null : $s['cta'],
                    'hashtags' => $s['hashtags'],
                    'structure' => $s['structure'],
                    'format' => $s['format'],
                    'channel' => $s['channel'],
                    'image_asset_id' => $s['image_asset_id'],
                    'video_asset_id' => $s['video_asset_id'],
                ]);

                // Uma versao so: o save sobe (e grava) se alguma coluna mudou; se so os
                // slides mudaram, markContentChanged faz o mesmo.
                if ($content->isDirty(Content::VERSIONED)) {
                    $content->save();
                } else {
                    $content->markContentChanged();
                }

                ContentRevision::create([
                    'content_id' => $content->id,
                    'user_id' => $user->id,
                    'changes' => ['restore' => ['from' => $esperada, 'to' => (int) $content->version, 'restored_version' => $deVersao]],
                ]);

                return ContentVersion::where('content_id', $content->id)->where('version', $content->version)->firstOrFail();
            }, restoredFrom: $deVersao);
        });
    }

    /**
     * O que mudou entre duas versoes, campo a campo. Midia se compara pelo registro e
     * pelo checksum do arquivo — nunca pelo nome: dois arquivos com o mesmo nome podem
     * ser diferentes, e sem checksum nao se afirma igualdade.
     *
     * @return list<array{field: string, changed: bool, from: mixed, to: mixed, note?: string}>
     */
    public static function compare(ContentVersion $a, ContentVersion $b): array
    {
        $sa = $a->snapshot;
        $sb = $b->snapshot;
        $campos = [];

        foreach (['title', 'caption', 'cta', 'hashtags', 'structure', 'format', 'channel', 'published_caption'] as $campo) {
            $de = $sa[$campo] ?? null;
            $para = $sb[$campo] ?? null;
            $campos[] = ['field' => $campo, 'changed' => $de !== $para, 'from' => $de, 'to' => $para];
        }

        $ma = $a->media ?? [];
        $mb = $b->media ?? [];

        foreach (['image', 'video'] as $tipo) {
            $campos[] = self::compararMidia($tipo, $ma[$tipo] ?? null, $mb[$tipo] ?? null);
        }

        $slidesA = $ma['slides'] ?? [];
        $slidesB = $mb['slides'] ?? [];
        $idsA = array_column($slidesA, 'id');
        $idsB = array_column($slidesB, 'id');
        $slides = ['field' => 'slides', 'changed' => $idsA !== $idsB, 'from' => $slidesA, 'to' => $slidesB];

        if ($idsA !== $idsB) {
            sort($idsA);
            sort($idsB);

            if ($idsA === $idsB) {
                $slides['note'] = 'Mesmas imagens, em outra ordem.';
            }
        }

        $campos[] = $slides;

        return $campos;
    }

    /** @return array{field: string, changed: bool, from: mixed, to: mixed, note?: string} */
    private static function compararMidia(string $tipo, ?array $de, ?array $para): array
    {
        $item = ['field' => $tipo, 'changed' => ($de['id'] ?? null) !== ($para['id'] ?? null), 'from' => $de, 'to' => $para];

        if ($de !== null && $para !== null && $item['changed']) {
            $item['note'] = match (true) {
                ($de['checksum'] ?? null) !== null && ($de['checksum'] ?? null) === ($para['checksum'] ?? null) => 'Arquivos diferentes com o mesmo checksum (conteúdo idêntico).',
                ($de['checksum'] ?? null) === null || ($para['checksum'] ?? null) === null => 'Sem checksum de um dos arquivos: não dá para afirmar se são iguais.',
                default => 'Arquivos diferentes (checksums diferentes).',
            };
        }

        return $item;
    }

    /** @throws RestoreRefused */
    private static function conferirMidias(Content $content, array $s): void
    {
        $esperado = array_filter([
            ...array_map(fn ($id) => [(int) $id, 'image'], $s['slide_asset_ids'] ?? []),
            $s['image_asset_id'] !== null ? [(int) $s['image_asset_id'], 'image'] : null,
            $s['video_asset_id'] !== null ? [(int) $s['video_asset_id'], 'video'] : null,
        ]);

        foreach ($esperado as [$id, $tipo]) {
            $existe = Asset::withoutGlobalScopes()
                ->where('project_id', $content->project_id)->where('type', $tipo)->whereKey($id)->exists();

            if (! $existe) {
                throw new RestoreRefused('Uma mídia desta versão foi removida da biblioteca: não dá para restaurá-la.');
            }
        }
    }

    /** Metadados das midias da versao. Guardados aqui: sobrevivem ao asset ser apagado. */
    private static function midias(array $snapshot): array
    {
        $ids = array_filter([$snapshot['image_asset_id'], $snapshot['video_asset_id'], ...$snapshot['slide_asset_ids']]);
        $assets = $ids === [] ? collect() : Asset::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');

        $meta = fn ($id) => $id === null ? null : (($a = $assets->get($id)) === null ? ['id' => (int) $id, 'missing' => true] : [
            'id' => $a->id,
            'type' => $a->type,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size_bytes' => $a->size_bytes,
            'width' => $a->width,
            'height' => $a->height,
            'duration_ms' => $a->duration_ms,
            'checksum' => $a->checksum,
        ]);

        return [
            'image' => $meta($snapshot['image_asset_id']),
            'video' => $meta($snapshot['video_asset_id']),
            'slides' => array_map($meta, $snapshot['slide_asset_ids']),
        ];
    }
}
