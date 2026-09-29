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
                    'segment' => 'Autocuidado, beleza e bem-estar feminino',
                    'description' => 'Loja online de produtos femininos e conteúdo educativo para o Instagram @2msaudefeminina.',
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

    /**
     * CP-03: a identidade definida para o piloto. Loja online SEM catalogo confirmado:
     * a oferta fica "A CONFIRMAR" e a IA comeca por conteudo educativo e institucional.
     *
     * @return array<string, mixed>
     */
    public static function perfilDaMarca(): array
    {
        return [
            'brand_name' => '2M Saúde Feminina',
            'description' => 'Loja online de produtos femininos e conteúdos educativos sobre autocuidado, beleza e bem-estar. '
                .'A CONFIRMAR: catálogo de produtos (ainda não há produto confirmado) e quem assina os conteúdos de saúde.',
            'audience' => 'Mulheres de diferentes idades, em todo o Brasil, que querem cuidar de si com informação confiável '
                .'sobre autocuidado, beleza e bem-estar — sem pressão estética e sem promessas.',
            'persona' => 'Ana, 38 anos, trabalha fora, tem pouco tempo para si, lê sobre bem-estar no celular à noite e '
                .'desconfia de quem promete solução rápida. A CONFIRMAR com seguidoras reais.',
            'tone_of_voice' => implode("\n", [
                'Feminino, acolhedor, inspirador e responsável. Fala COM a mulher, nunca SOBRE ela; sem julgamento e sem alarmismo.',
                'REGRAS DE SAÚDE (inegociáveis):',
                '- Informar e orientar, nunca diagnosticar nem prescrever: nada de dose, nome de remédio para usar, ou "faça X e resolva".',
                '- Não anunciar serviço médico nem consulta: a marca não presta atendimento de saúde.',
                '- Não prometer resultado, cura ou prazo. Sempre que falar de sintoma, orientar a procurar avaliação profissional.',
                '- Não usar medo como gatilho.',
                '- Afirmação de saúde só com base em fonte reconhecida (Ministério da Saúde, FEBRASGO, INCA, OMS). Na dúvida, não publicar.',
                'REGRAS DE BELEZA E AUTOCUIDADO:',
                '- Autocuidado é saúde, não padrão estético: nada de "corpo perfeito", comparação ou vergonha do corpo.',
                '- Sem "antes e depois" e sem promessa de resultado estético.',
                'REGRAS COMERCIAIS (enquanto não houver catálogo confirmado):',
                '- Não inventar produto, preço, promoção, cupom ou frete.',
                '- Não apresentar depoimento simulado como real; depoimento só real e autorizado.',
            ]),
            'differentiators' => 'Informação responsável, sem sensacionalismo, em linguagem simples. A CONFIRMAR: diferenciais da loja (curadoria, entrega, atendimento).',
            'products' => ['A CONFIRMAR'],
            'services' => [],
            'competitors' => [],
            'required_words' => ['autocuidado', 'bem-estar', 'procure avaliação profissional'],
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

    /** @return array{title: string, summary: string, editorial_line: string, pillars: list<array{name: string, weight: int, description: string}>, guidelines: array<string, mixed>} */
    public static function estrategiaEditorial(): array
    {
        return [
            'title' => 'Piloto 2M Saúde Feminina — primeiros 90 dias',
            'summary' => 'Construir audiência e confiança com conteúdo educativo e institucional sobre autocuidado, beleza e bem-estar. '
                .'Sem conteúdo de venda até o catálogo ser confirmado.',
            'editorial_line' => 'Autocuidado, beleza e bem-estar explicados com leveza e responsabilidade: inspirar a mulher a cuidar de si, '
                .'com informação confiável. Nunca diagnóstico, nunca promessa, nunca pressão estética.',
            // As categorias de conteudo. O copywriter distribui o lote pelos pesos.
            'pillars' => [
                ['name' => 'Autocuidado e bem-estar', 'weight' => 30, 'description' => 'Sono, rotina, movimento, saúde emocional e pequenos hábitos de cuidado — sem padrão estético.'],
                ['name' => 'Beleza com responsabilidade', 'weight' => 20, 'description' => 'Cuidados com pele e cabelo explicados sem promessa de resultado e sem fotos comparativas de resultado.'],
                ['name' => 'Saúde da mulher', 'weight' => 25, 'description' => 'Ciclo, prevenção e fases da vida, com fonte reconhecida e orientação a procurar avaliação profissional.'],
                ['name' => 'Mitos e verdades', 'weight' => 15, 'description' => 'Desfazer crenças comuns com base em fonte reconhecida.'],
                ['name' => 'Bastidores da marca', 'weight' => 10, 'description' => 'Quem somos, por que a loja existe e como escolhemos falar. A CONFIRMAR o que pode ser mostrado.'],
            ],
            // CP-03: o que o responsavel revisa antes de planejar. Comercial 0 enquanto
            // nao houver produto confirmado (o StrategistAgent recusaria outra coisa).
            'guidelines' => [
                'objectives' => [
                    'Fazer a audiência crescer com conteúdo útil',
                    'Gerar engajamento (salvar, comentar, compartilhar)',
                    'Fortalecer a marca como fonte confiável',
                    'Preparar a base para vendas futuras, quando houver catálogo',
                ],
                'themes' => [
                    'Rotina de autocuidado em 5 minutos', 'Sono e bem-estar', 'Cuidados com a pele sem promessas',
                    'Ciclo menstrual explicado', 'Prevenção e exames de rotina', 'Mitos de beleza', 'Quem é a 2M Saúde Feminina',
                ],
                'formats' => ['post', 'carousel', 'reel', 'story'],
                'weekly_frequency' => 3,
                'content_mix' => ['educational' => 70, 'institutional' => 30, 'commercial' => 0],
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
            ['Autocuidado e bem-estar', 'Autocuidado não é luxo: 5 minutos por dia', 'Pequenos hábitos sustentáveis de cuidado com corpo e mente.'],
            ['Saúde da mulher', 'O que o seu ciclo menstrual diz sobre você', 'As fases do ciclo e o que é esperado; quando uma mudança merece avaliação profissional.'],
            ['Beleza com responsabilidade', 'Pele saudável começa pelo básico', 'Limpeza, hidratação e protetor solar explicados sem promessa de resultado.'],
            ['Mitos e verdades', 'Mito ou verdade: beber água "limpa" a pele?', 'O que se sabe de fato e o que é exagero, com fonte.'],
            ['Autocuidado e bem-estar', 'Sono e bem-estar: uma relação de mão dupla', 'Como o sono influencia humor e disposição; hábitos simples de higiene do sono.'],
            ['Saúde da mulher', 'Papanicolau: quando fazer e como se preparar', 'Periodicidade recomendada pelas fontes oficiais e por que não adiar.'],
            ['Bastidores da marca', 'Quem é a 2M Saúde Feminina', 'Por que a loja existe e como escolhemos falar com você: informação antes de venda.'],
            ['Beleza com responsabilidade', 'Cabelo e estresse: o que a ciência já sabe', 'O que é comprovado, o que é mito, e quando procurar avaliação profissional.'],
            ['Autocuidado e bem-estar', 'Movimento sem cobrança', 'Atividade física como cuidado, não como punição ou padrão estético.'],
            ['Mitos e verdades', 'Mito ou verdade: cólica forte é normal?', 'Diferenciar desconforto comum de sinal de alerta que pede avaliação.'],
            ['Saúde da mulher', 'Autoexame das mamas não substitui a mamografia', 'O papel de cada um e a idade de rastreamento segundo as recomendações oficiais.'],
            ['Autocuidado e bem-estar', 'Um momento só seu na rotina', 'Como reservar tempo para si sem culpa, em qualquer fase da vida.'],
        ];
    }
}
