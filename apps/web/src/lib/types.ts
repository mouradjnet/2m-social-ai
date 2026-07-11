export interface WorkspaceSummary {
  id: number
  name: string
  slug: string
  role: 'owner' | 'admin' | 'editor' | 'reviewer' | 'viewer'
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
  source: 'manual' | 'ai' | 'research'
  origin_ai_run_id: number | null
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
