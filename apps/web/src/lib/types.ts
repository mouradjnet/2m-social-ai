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
  website: string | null
  instagram: string | null
  linkedin: string | null
}

/** Quem decide o que esta completo e o servidor. O Stepper so desenha. */
export interface BrandProfileCompletion {
  identity: boolean
  audience: boolean
  positioning: boolean
  social: boolean
  percent: number
}

export interface BrandProfileResponse {
  data: BrandProfileData
  completion: BrandProfileCompletion
}
