export interface WorkspaceSummary {
  id: number
  name: string
  slug: string
  role: 'owner' | 'admin' | 'editor' | 'reviewer' | 'viewer'
}

/** Convite pendente. O token vale como o link inteiro: quem o tem, tem o convite. */
export interface Invitation {
  id: number
  email: string
  role: WorkspaceSummary['role']
  token: string
  expires_at: string
}

export interface Me {
  id: number
  name: string
  email: string
  workspaces: WorkspaceSummary[]
}

export interface Project {
  id: number
  workspace_id: number
  name: string
  company: string | null
  segment: string | null
  description: string | null
  status: 'active' | 'paused' | 'archived'
  color: string | null
}

export interface BrandProfileData {
  id: number
  project_id: number
  brand_name: string | null
  description: string | null
  audience: string | null
  persona: string | null
  tone_of_voice: string | null
  differentiators: string | null
  products: string[] | null
  services: string[] | null
  competitors: string[] | null
  required_words: string[] | null
  forbidden_words: string[] | null
  /** Hex, ex: ['#006c49']. Quem le e o designer, no prompt de imagem. */
  colors: string[] | null
  website: string | null
  instagram: string | null
  linkedin: string | null
}

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

export interface BrandProfileResponse {
  data: BrandProfileData
  completion: BrandProfileCompletion
}

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

export type ContentStatus =
  | 'idea'
  | 'production'
  | 'review'
  | 'approved'
  | 'scheduled'
  | 'published'
  | 'archived'

export type ContentFormat = 'post' | 'carousel' | 'reel' | 'story' | 'video' | 'article' | 'thread'

export type ContentChannel = 'instagram' | 'facebook' | 'linkedin' | 'tiktok' | 'youtube' | 'blog'

export interface Violation {
  rule: string
  excerpt: string
  suggestion: string
}

/** Veredito do reviewer. `fail` sempre tem violacao; `pass`, nunca (o servidor exige). */
export interface ContentReview {
  id: number
  verdict: 'pass' | 'fail'
  summary: string
  violations: Violation[]
  created_at: string
}

/** Sugestao do agente seo. `applied_at` diz se ela ja virou a peca. */
export interface ContentSeo {
  id: number
  title: string
  keywords: string[]
  hashtags: string[]
  applied_at: string | null
  created_at: string
}

export interface Content {
  id: number
  project_id: number
  title: string
  caption: string | null
  cta: string | null
  hashtags: string[]
  format: ContentFormat
  channel: ContentChannel
  status: ContentStatus
  /** ISO 8601. Quem preenche e o agente social_media; desagendar volta a null. */
  scheduled_for: string | null
  /** Em ingles: e o texto que se cola no gerador de imagem. Quem escreve e o designer. */
  image_prompt: string | null
  /** A ultima revisao (as reviews sao append-only). Null se nunca foi revisada. */
  latest_review: ContentReview | null
  /** A ultima sugestao de SEO. Null se o agente nunca rodou nesta peca. */
  latest_seo: ContentSeo | null
  source: 'manual' | 'ai' | 'research'
  origin_ai_run_id: number | null
  updated_at: string
  /**
   * A última vez que o TEXTO mudou (reescrita, SEO aplicado). É como se sabe que um
   * veredito ficou velho — `updated_at` não serve, porque também muda quando a peça
   * anda no fluxo ou é arquivada. Null se o texto nunca mudou.
   */
  latest_text_revision: { id: number; created_at: string } | null
}

export interface PillarAdherence {
  nome: string
  peso_pedido: number
  peso_real: number
  pecas: number
  /** Em pontos percentuais. Positivo = entregou mais do que a estrategia pediu. */
  desvio: number
}

/** Os numeros do projeto, calculados pelo servidor. Nunca por um LLM. */
export interface ProjectMetrics {
  volume: { total: number; por_status: Record<string, number> }
  aderencia: {
    pilares: PillarAdherence[]
    pecas_com_pilar: number
    sem_pilar: number
  } | null
  cadencia: { agendadas_30_dias: number; dias_com_peca: number; maior_lacuna_dias: number }
  qualidade: {
    revisadas: number
    aprovadas: number
    reprovadas: number
    violacoes: number
    regras_mais_violadas: Array<{ regra: string; vezes: number }>
  }
  mix: { por_canal: Record<string, number>; por_formato: Record<string, number> }
}

export interface Insight {
  title: string
  detail: string
  action: string
}

export interface AnalyticsReport {
  id: number
  score: number
  summary: string
  insights: Insight[]
  /** O snapshot dos numeros que geraram esta leitura. */
  metrics: ProjectMetrics
  created_at: string
}

export interface AnalyticsResponse {
  data: AnalyticsReport | null
  /** Os numeros de agora — a tela mostra o calendario antes de a IA opinar. */
  metrics: ProjectMetrics
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
