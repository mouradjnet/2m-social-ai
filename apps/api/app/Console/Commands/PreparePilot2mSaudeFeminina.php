<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monta o projeto do piloto (@2msaudefeminina) num workspace existente: perfil da
 * marca com as regras de conteudo de saude e beleza, estrategia com as categorias
 * como pilares, e um primeiro mes de IDEIAS.
 *
 * Idempotente e conservador: rodar de novo nao duplica nada e nao sobrescreve o que
 * alguem ja editou na tela. Nao conecta conta, nao aprova, nao agenda e nao publica —
 * isso e sempre gesto humano (ADR-13).
 *
 * O que nao se sabe da marca (servicos, site, equipe) vai marcado "A CONFIRMAR": um
 * perfil inventado ensinaria a IA a escrever sobre um negocio que nao existe.
 */
#[Signature('pilot:2m-saude-feminina {workspace : id ou slug do workspace} {--owner= : email de quem responde pelo projeto (membro do workspace)}')]
#[Description('Prepara o projeto piloto 2M Saude Feminina: perfil, estrategia e ideias do primeiro mes')]
class PreparePilot2mSaudeFeminina extends Command
{
    public const PROJECT_NAME = '2M Saúde Feminina';

    public function handle(): int
    {
        $arg = (string) $this->argument('workspace');
        $workspace = Workspace::query()
            ->where(fn ($q) => ctype_digit($arg) ? $q->whereKey((int) $arg) : $q->where('slug', $arg))
            ->first();

        if ($workspace === null) {
            $this->error("Workspace '{$arg}' não encontrado.");

            return self::FAILURE;
        }

        $owner = $this->option('owner')
            ? User::where('email', strtolower((string) $this->option('owner')))->first()
            : User::find($workspace->owner_id);

        if ($owner === null || $workspace->roleFor($owner) === null) {
            $this->error('O responsável precisa ser membro do workspace.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($workspace, $owner) {
            $project = Project::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => self::PROJECT_NAME],
                [
                    'company' => '2M Saúde Feminina',
                    'segment' => 'Saúde da mulher',
                    'description' => 'Conteúdo educativo de saúde e bem-estar feminino para o Instagram @2msaudefeminina.',
                    'owner_user_id' => $owner->id,
                    'status' => 'active',
                    'timezone' => 'America/Sao_Paulo',
                    'color' => '#b23a6f',
                ],
            );
            $this->line(($project->wasRecentlyCreated ? 'Criado' : 'Já existia').": projeto #{$project->id} {$project->name}");

            $this->perfil($project);
            $this->estrategia($project);
            $this->ideias($project, $owner);
        });

        $this->newLine();
        $this->warn('Revise no app os campos marcados "A CONFIRMAR" no Perfil da Marca antes de gerar conteúdo.');
        $this->line('Próximo passo: docs/PILOTO-2M-SAUDE-FEMININA.md');

        return self::SUCCESS;
    }

    /** So preenche o que esta vazio: o que alguem ja escreveu na tela prevalece. */
    private function perfil(Project $project): void
    {
        $perfil = $project->brandProfile()->firstOrCreate([]);
        $alvo = self::perfilDaMarca();
        $vazios = array_filter($alvo, fn ($valor, $campo) => blank($perfil->{$campo}), ARRAY_FILTER_USE_BOTH);

        $perfil->update($vazios);
        $this->line('Perfil da marca: '.count($vazios).' campo(s) preenchido(s), '.(count($alvo) - count($vazios)).' mantido(s).');
    }

    private function estrategia(Project $project): void
    {
        if (Strategy::withoutGlobalScopes()->where('project_id', $project->id)->where('status', 'active')->exists()) {
            $this->line('Estratégia: já há uma ativa, mantida.');

            return;
        }

        Strategy::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'status' => 'active',
            ...self::estrategiaEditorial(),
        ]);
        $this->line('Estratégia: criada e ativa.');
    }

    /** Ideias, e so ideias: escrever, revisar, aprovar e agendar continua no fluxo. */
    private function ideias(Project $project, User $owner): void
    {
        if (Content::withoutGlobalScopes()->where('project_id', $project->id)->exists()) {
            $this->line('Calendário inicial: o projeto já tem peças, nada criado.');

            return;
        }

        foreach (self::primeiroMes() as [$pilar, $titulo, $resumo]) {
            Content::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'title' => $titulo,
                'summary' => $resumo,
                'hashtags' => [],
                'format' => 'post',
                'channel' => 'instagram',
                'pillar' => $pilar,
                'status' => 'idea',
                'source' => 'manual',
                'created_by' => $owner->id,
            ]);
        }

        $this->line('Calendário inicial: '.count(self::primeiroMes()).' ideias criadas em "Ideia".');
    }

    /** @return array<string, mixed> */
    public static function perfilDaMarca(): array
    {
        return [
            'brand_name' => '2M Saúde Feminina',
            'description' => 'Perfil de educação em saúde da mulher: informação clara e responsável sobre ciclo, prevenção, '
                .'fases da vida e autocuidado. A CONFIRMAR: quem assina o conteúdo (profissional e registro no conselho) e se há atendimento.',
            'audience' => 'Mulheres de 25 a 55 anos que querem entender o próprio corpo, cuidar da saúde com prevenção e '
                .'decidir junto com a médica ou o médico — sem medo e sem promessas.',
            'persona' => 'Ana, 38 anos, trabalha fora, adia os exames de rotina por falta de tempo, lê sobre saúde no '
                .'celular à noite e desconfia de quem promete solução rápida.',
            'tone_of_voice' => implode("\n", [
                'Acolhedor, claro e responsável. Fala COM a mulher, nunca SOBRE ela; sem julgamento e sem alarmismo.',
                'REGRAS DE SAÚDE (inegociáveis):',
                '- Informar e orientar, nunca diagnosticar nem prescrever: nada de dose, nome de remédio para usar, ou "faça X e resolva".',
                '- Não prometer resultado, cura ou prazo. Toda condição tem avaliação individual.',
                '- Sempre que falar de sintoma, orientar a procurar avaliação profissional.',
                '- Não usar medo como gatilho ("você pode estar com câncer e não sabe").',
                '- Informação baseada em consenso médico e fontes reconhecidas (Ministério da Saúde, FEBRASGO, INCA, OMS). Na dúvida, não publicar.',
                '- Não expor paciente, caso clínico identificável ou foto de paciente.',
                'REGRAS DE BELEZA E AUTOCUIDADO:',
                '- Autocuidado é saúde, não padrão estético: nada de "corpo perfeito", comparação ou vergonha do corpo.',
                '- Sem "antes e depois" e sem promessa de resultado estético.',
                '- Produto ou procedimento citado não é recomendação de uso sem avaliação.',
            ]),
            'differentiators' => 'Informação responsável, sem sensacionalismo, com linguagem simples. A CONFIRMAR: diferenciais do serviço.',
            'products' => [],
            'services' => ['A CONFIRMAR'],
            'competitors' => [],
            'required_words' => ['procure avaliação profissional', 'prevenção', 'autocuidado'],
            // Expressoes, nao palavras soltas: o revisor trata QUALQUER aparicao como
            // violacao dura, e "garantido" sozinho pegaria "sigilo garantido".
            'forbidden_words' => [
                'cura garantida', 'resultado garantido', '100% eficaz', 'milagre', 'milagroso',
                'sem efeitos colaterais', 'tratamento definitivo', 'emagreça rápido', 'corpo perfeito',
                'antes e depois', 'dispensa consulta', 'não precisa de médico',
            ],
            'colors' => ['#b23a6f', '#f6e7ee', '#2b2d42'],
            'instagram' => 'https://www.instagram.com/2msaudefeminina/',
        ];
    }

    /** @return array{title: string, summary: string, editorial_line: string, pillars: list<array{name: string, weight: int, description: string}>} */
    public static function estrategiaEditorial(): array
    {
        return [
            'title' => 'Piloto 2M Saúde Feminina — primeiros 90 dias',
            'summary' => 'Construir confiança com educação em saúde feminina: 3 posts por semana, sempre com orientação a procurar avaliação profissional.',
            'editorial_line' => 'Saúde da mulher explicada com clareza e responsabilidade: informar para a mulher decidir melhor '
                .'junto com quem a atende. Nunca diagnóstico, nunca promessa, nunca medo.',
            // As categorias de conteudo. O copywriter distribui o lote pelos pesos.
            'pillars' => [
                ['name' => 'Educação em saúde', 'weight' => 35, 'description' => 'Ciclo menstrual, hormônios, fases da vida (puberdade, gestação, menopausa), explicados sem jargão.'],
                ['name' => 'Prevenção e exames', 'weight' => 25, 'description' => 'Check-up, papanicolau, mamografia, vacinas: quando, por quê e como se preparar.'],
                ['name' => 'Bem-estar e autocuidado', 'weight' => 20, 'description' => 'Sono, alimentação, movimento, saúde emocional e rotina de autocuidado — sem padrão estético.'],
                ['name' => 'Mitos e verdades', 'weight' => 15, 'description' => 'Desfazer crenças comuns com base em consenso médico e fonte citada.'],
                ['name' => 'Bastidores e confiança', 'weight' => 5, 'description' => 'Quem produz o conteúdo e como. A CONFIRMAR o que pode ser mostrado.'],
            ],
        ];
    }

    /**
     * O primeiro mes: 12 ideias (3 por semana), na proporcao dos pilares.
     *
     * @return list<array{string, string, string}>
     */
    public static function primeiroMes(): array
    {
        return [
            ['Educação em saúde', 'O que o seu ciclo menstrual diz sobre você', 'As fases do ciclo e o que é esperado em cada uma; quando uma mudança merece avaliação.'],
            ['Prevenção e exames', 'Papanicolau: quando fazer e como se preparar', 'Periodicidade recomendada, preparo e por que não adiar.'],
            ['Bem-estar e autocuidado', 'Sono e hormônios: uma relação de mão dupla', 'Como o sono influencia o ciclo e o humor; hábitos simples de higiene do sono.'],
            ['Mitos e verdades', 'Mito ou verdade: cólica forte é normal?', 'Diferenciar desconforto comum de sinal de alerta que pede avaliação.'],
            ['Educação em saúde', 'Menopausa não é doença: o que muda no corpo', 'Sintomas mais comuns, a variação entre mulheres e quando conversar com a médica.'],
            ['Prevenção e exames', 'Autoexame das mamas não substitui a mamografia', 'O papel de cada um e a idade de rastreamento segundo as recomendações oficiais.'],
            ['Bem-estar e autocuidado', 'Autocuidado não é luxo: 5 minutos por dia', 'Pequenos hábitos sustentáveis de cuidado com corpo e mente.'],
            ['Educação em saúde', 'TPM ou algo mais? Entendendo os sintomas', 'O que caracteriza a TPM e quando os sintomas pedem investigação.'],
            ['Mitos e verdades', 'Mito ou verdade: anticoncepcional engorda?', 'O que as evidências dizem e por que a escolha é individual e com orientação.'],
            ['Prevenção e exames', 'Vacina de HPV: quem pode tomar', 'Público recomendado e por que a vacina é prevenção.'],
            ['Educação em saúde', 'Corrimento: quando é normal e quando procurar ajuda', 'Variações esperadas ao longo do ciclo e sinais que pedem consulta.'],
            ['Bastidores e confiança', 'Por que falamos de saúde com responsabilidade', 'O compromisso do perfil: informar sem diagnosticar, sem prometer e citando fontes.'],
        ];
    }
}
