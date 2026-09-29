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
  competitors: Array<{ name: string; url: string | null }> | null
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
  /** CP-03: o que o responsável revisa antes de planejar. Null em estratégias antigas. */
  guidelines?: StrategyGuidelines | null
  status: 'draft' | 'active' | 'archived'
  ai_run_id: number | null
}

export interface StrategyGuidelines {
  objectives: string[]
  themes: string[]
  formats: ContentFormat[]
  weekly_frequency: number
  /** Porcentagens que somam 100. Comercial é 0 sem produto ou serviço real. */
  content_mix: { educational: number; institutional: number; commercial: number }
}

/**
 * CP-03: o ROTEIRO da peça por formato — texto para produzir, não a arte nem o
 * vídeo. Null em peças manuais ou anteriores ao CP-03.
 */
export interface ContentStructure {
  visual: string
  slides?: { heading: string; body: string }[]
  screens?: { text: string; visual: string; interaction: string }[]
  hook?: string
  scenes?: { description: string; on_screen_text: string; narration: string }[]
  production_notes?: string
}

/** Estado editorial explícito, derivado de status + último veredito (servidor). */
export type EditorialState =
  | 'draft'
  | 'in_review'
  | 'needs_revision'
  | 'ready_for_approval'
  | 'approved'
  | 'scheduled'
  | 'published'
  | 'archived'

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
  /** CP-03: o roteiro do formato (slides, telas, cenas). */
  structure?: ContentStructure | null
  /** CP-03: o estado editorial explícito. */
  editorial_state?: EditorialState
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
  /** Quem aprovou e quando (ADR-13). So reviewer+ aprova. */
  approved_by?: number | null
  approved_at?: string | null
  approver?: { id: number; name: string } | null
  image_asset_id?: number | null
  /** A imagem que vai ao ar. Sem ela, o Instagram nao publica. */
  image?: Asset | null
  /** A publicacao mais recente no Instagram. */
  latest_publication?: Publication | null
  /** O plano da semana de onde a peca saiu, e o horario que ele sugeriu (UTC). */
  content_plan_id?: number | null
  planned_for?: string | null
  /** A peca de onde esta foi reaproveitada (repurposer). */
  repurposed_from_id?: number | null
  /** Carrossel: as imagens, na ordem (2 a 10). */
  slides?: Asset[]
  /** Reel: o video. A `image` e a capa. */
  video_asset_id?: number | null
  video?: Asset | null
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

/** Uma imagem da biblioteca. `url` e publica: e o que a Meta busca ao publicar. */
export interface Asset {
  id: number
  project_id: number
  original_name: string | null
  mime: string
  size_bytes: number
  width: number | null
  height: number | null
  /** `video` so para Reels (Etapa 3); o resto e `image`. Ausente = imagem (fixtures antigos). */
  type?: 'image' | 'video'
  duration_ms?: number | null
  url: string
  /** Quantas pecas usam esta imagem. */
  contents_count?: number
  created_at: string
}

export interface InstagramAccount {
  id: number
  project_id: number
  ig_user_id: string
  username: string
  account_type: string
  status: 'active' | 'expired' | 'disconnected'
  token_expires_at: string | null
  /** Dias ate o token vencer; negativo = venceu. A tela avisa abaixo de 7. */
  expires_in_days: number | null
  /** A marca autorizou ler as metricas dos posts (escopo opcional). */
  insights_enabled?: boolean
  last_error: string | null
  connected_at: string
  connector?: { id: number; name: string } | null
}

export type PublicationStatus =
  | 'pending'
  | 'publishing'
  | 'published'
  | 'failed'
  | 'unknown'
  | 'cancelled'

export interface PublicationAttempt {
  id: number
  number: number
  step: string
  outcome: string
  http_status: number | null
  meta_code: number | null
  meta_subcode: number | null
  message: string | null
  created_at: string
}

/** O que o sistema fez em nome de quem aprovou (ADR-13), com o snapshot aprovado. */
export interface Publication {
  id: number
  content_id: number
  caption: string
  image_url: string | null
  account_username: string | null
  approved_by: number | null
  approved_at: string | null
  scheduled_for: string
  status: PublicationStatus
  media_id: string | null
  permalink: string | null
  published_at: string | null
  attempts: number
  next_attempt_at: string | null
  error_kind: string | null
  last_error: string | null
  content?: { id: number; title: string; status: ContentStatus }
  approver?: { id: number; name: string } | null
  attempts_log?: PublicationAttempt[]
}

/** Um gesto humano que nao deixa rastro em outro lugar (ActivityLog no backend). */
export interface ActivityEntry {
  id: number
  /** instagram.connected | instagram.disconnected | publication.resolved | asset.deleted */
  action: string
  subject_type: string
  subject_id: number
  meta: Record<string, string>
  created_at: string
  user: { id: number; name: string } | null
}

/** O consumo de IA do mes no workspace (GET /workspaces/{id}/usage). */
export interface Usage {
  month: string
  spent_cents: number
  limit_cents: number
  limit_source: 'default' | 'workspace'
  by_agent: { agent: string; runs: number; cost_cents: number }[]
  by_project: { project_id: number | null; name: string | null; runs: number; cost_cents: number }[]
}

/** Um horario do plano da semana. Data e hora LOCAIS, no fuso do projeto. */
export interface PlanSlot {
  date: string
  time: string
  pillar: string
  format: ContentFormat
  channel: ContentChannel
  theme: string
  /** CP-03: o que a peça quer causar e a chamada sugerida. Ausentes em planos antigos. */
  objective?: string
  cta?: string
  rationale: string
}

/** O plano de uma semana, proposto pelo planner (GET /projects/{id}/week-plan). */
export interface WeekPlan {
  id: number
  period_start: string
  period_end: string
  posts_count: number
  distribution: { summary: string; slots: PlanSlot[] }
  /** Quantas pecas ja foram escritas a partir dele. */
  contents_count: number
  created_at: string
}

/** Numeros que a Meta devolveu para UM post (so os presentes). */
export type PostMetrics = Partial<Record<'reach' | 'views' | 'likes' | 'comments' | 'saved' | 'shares' | 'total_interactions' | 'ig_reels_avg_watch_time', number>>

export interface ResultGroup {
  name: string
  posts: number
  avg_reach: number
  avg_interactions: number
  engagement_rate: number | null
}

/** Os resultados REAIS do projeto (GET /projects/{id}/results). */
export interface Results {
  days: number
  published: number
  measured: number
  totals: Required<Omit<PostMetrics, 'ig_reels_avg_watch_time'>>
  engagement_rate: number | null
  by_pillar: ResultGroup[]
  by_format: ResultGroup[]
  posts: {
    publication_id: number
    content_id: number
    title: string | null
    pillar: string | null
    format: string | null
    published_at: string | null
    permalink: string | null
    state: 'measured' | 'pending' | 'unavailable'
    metrics: PostMetrics | null
    error: string | null
    collected_at: string | null
  }[]
}
