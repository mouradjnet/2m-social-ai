const TOKEN_KEY = '2m.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  // Campos declarados: `erasableSyntaxOnly` proibe propriedades de construtor.
  readonly status: number
  readonly body: unknown

  constructor(status: number, body: unknown) {
    super(`HTTP ${status}`)
    this.status = status
    this.body = body
  }

  /** Erros de validacao do Laravel: { message, errors: { campo: [msg] } } */
  fieldError(field: string): string | undefined {
    const errors = (this.body as { errors?: Record<string, string[]> })?.errors
    return errors?.[field]?.[0]
  }

  get message422(): string | undefined {
    return (this.body as { message?: string })?.message
  }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getToken()

  const response = await fetch(`/api/v1${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  })

  if (response.status === 204) {
    return undefined as T
  }

  const body = await response.json().catch(() => null)

  if (!response.ok) {
    // Token invalido ou expirado: nao adianta tentar de novo com ele.
    if (response.status === 401) clearToken()
    throw new ApiError(response.status, body)
  }

  return body as T
}
