<?php

namespace App\Domain\Export;

use App\Models\Content;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * A ENTREGA. Ate aqui o produto agendava e mostrava as pecas numa tela — e o cliente
 * copiava tudo na mao, uma a uma. Sem API das redes sociais (decisao de produto), o
 * zip E a entrega: e o que separa uma demo de um produto.
 *
 * Formato, e por que: um `.md` por peca (pronto para copiar e colar, com a legenda
 * inteira, quebras de linha e emoji intactos) mais um `calendario.csv` para a visao
 * geral e para quem quer importar numa planilha. Ninguem publica a partir de uma
 * celula de Excel: legenda com quebra de linha vira um inferno la dentro.
 */
class ContentZip
{
    /** So o que JA passou pelo humano. Rascunho e trabalho em curso: exportar `review`
     *  entregaria ao cliente ate peca REPROVADA pelo revisor. */
    private const STATUSES = ['approved', 'scheduled'];

    /** @return Collection<int, Content> */
    public static function pecas(Project $project): Collection
    {
        return $project->contents()
            ->whereIn('status', self::STATUSES)
            ->orderByRaw('scheduled_for asc nulls last')
            ->orderBy('id')
            ->get();
    }

    /** Escreve o zip no caminho dado e devolve o nome com que ele deve ser baixado. */
    public static function escrever(Project $project, string $caminho): string
    {
        $pecas = self::pecas($project);

        $zip = new ZipArchive;

        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Nao foi possivel criar o zip em {$caminho}.");
        }

        $zip->addFromString('calendario.csv', self::csv($project, $pecas));

        // Nomes repetidos se sobrescreveriam dentro do zip (duas pecas no mesmo dia e
        // canal, com titulo parecido): o indice garante um arquivo por peca.
        foreach ($pecas->values() as $i => $peca) {
            $zip->addFromString(self::nomeDoArquivo($project, $peca, $i + 1), self::markdown($project, $peca));
        }

        $zip->close();

        return Str::slug($project->name).'-conteudo-'.now($project->timezone)->format('Y-m-d').'.zip';
    }

    private static function nomeDoArquivo(Project $project, Content $peca, int $ordem): string
    {
        // A data primeiro: em qualquer gerenciador de arquivos, a ordem alfabetica vira
        // a ordem do calendario. No FUSO DO PROJETO: uma peca das 21:30 e 00:30 UTC do
        // dia seguinte, e o arquivo nasceria com a data errada — desordenando justamente
        // o que esse nome existe para ordenar.
        $quando = $peca->scheduled_for?->timezone($project->timezone)->format('Y-m-d') ?? 'sem-data';

        return sprintf(
            'pecas/%02d-%s-%s-%s.md',
            $ordem,
            $quando,
            $peca->channel,
            Str::slug(Str::limit($peca->title, 40, '')),
        );
    }

    /** @param  Collection<int, Content>  $pecas */
    private static function csv(Project $project, Collection $pecas): string
    {
        $linhas = fopen('php://temp', 'r+');

        // BOM: sem ele o Excel abre "transparência" como "transparÃªncia".
        fwrite($linhas, "\u{FEFF}");

        fputcsv($linhas, ['Data', 'Hora', 'Canal', 'Formato', 'Pilar', 'Titulo', 'Status']);

        foreach ($pecas as $peca) {
            // No fuso do PROJETO: este e o calendario que o cliente abre para saber a
            // que horas publicar, e o banco guarda UTC (config/database.php fixa a
            // conexao pgsql em UTC).
            $quando = $peca->scheduled_for?->timezone($project->timezone);

            fputcsv($linhas, [
                $quando?->format('d/m/Y') ?? '',
                $quando?->format('H:i') ?? '',
                $peca->channel,
                $peca->format,
                $peca->pillar ?? '',
                $peca->title,
                $peca->status === 'scheduled' ? 'Agendado' : 'Aprovado',
            ]);
        }

        rewind($linhas);
        $csv = stream_get_contents($linhas);
        fclose($linhas);

        return $csv;
    }

    private static function markdown(Project $project, Content $peca): string
    {
        $quando = $peca->scheduled_for
            ? $peca->scheduled_for->timezone($project->timezone)->format('d/m/Y \à\s H:i')
            : 'sem data definida';

        $hashtags = implode(' ', $peca->hashtags ?? []);

        $texto = "# {$peca->title}\n\n";
        $texto .= "- **Canal:** {$peca->channel}\n";
        $texto .= "- **Formato:** {$peca->format}\n";
        $texto .= '- **Pilar:** '.($peca->pillar ?? '—')."\n";
        $texto .= "- **Publicar em:** {$quando}\n\n";
        $texto .= "## Legenda\n\n{$peca->caption}\n\n";
        $texto .= "## CTA\n\n{$peca->cta}\n\n";
        $texto .= "## Hashtags\n\n{$hashtags}\n";

        // O prompt sai em ingles porque e o texto que se cola no gerador de imagem —
        // traduzi-lo aqui seria estragar a unica coisa que ele precisa ser.
        if ($peca->image_prompt !== null) {
            $texto .= "\n## Prompt de imagem (cole no gerador)\n\n{$peca->image_prompt}\n";
        }

        return $texto;
    }
}
