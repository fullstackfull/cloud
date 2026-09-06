/**
 * Typed client for the Lynomia API.
 *
 * The portals authenticate with Sanctum's cookie session, so every request is
 * credentialed and state-changing requests carry the XSRF token. Nothing here
 * ever stores a token in localStorage: a bearer token in web storage is
 * readable by any script that gets injected into the page, which is precisely
 * what the cookie flow avoids.
 */

export interface ApiErrorBody {
  code: string
  message: string
  details?: Record<string, unknown>
  request_id?: string
}

/** Field-level validation messages, keyed by field name. */
export type ValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly code: string
  readonly status: number
  readonly details: Record<string, unknown>
  readonly requestId: string | undefined

  constructor(status: number, body: ApiErrorBody) {
    super(body.message)
    this.name = 'ApiError'
    this.code = body.code
    this.status = status
    this.details = body.details ?? {}
    this.requestId = body.request_id
  }

  /** Validation messages, when this error carries them. */
  get fields(): ValidationErrors | null {
    const fields = this.details['fields']
    return typeof fields === 'object' && fields !== null ? (fields as ValidationErrors) : null
  }

  get isRateLimited(): boolean {
    return this.status === 429
  }

  get isUnauthenticated(): boolean {
    return this.status === 401
  }
}

/** Raised when the request never reached the server at all. */
export class NetworkError extends Error {
  constructor(cause: unknown) {
    super('The server could not be reached.')
    this.name = 'NetworkError'
    this.cause = cause
  }
}

const BASE_URL = (import.meta.env['VITE_API_BASE']) ?? '/api/v1'

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match?.[1] !== undefined ? decodeURIComponent(match[1]) : null
}

let csrfReady: Promise<void> | null = null

/**
 * Fetches the CSRF cookie once per page load.
 *
 * Concurrent callers share one in-flight request: several components mounting
 * at the same time must not each trigger their own round trip.
 */
async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN') !== null) return

  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    .then(() => undefined)
    .finally(() => {
      csrfReady = null
    })

  await csrfReady
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
  locale?: string
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const mutating = method !== 'GET'

  if (mutating) {
    await ensureCsrfCookie()
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }

  if (options.locale !== undefined) {
    headers['Accept-Language'] = options.locale
  }

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  if (mutating) {
    const token = readCookie('XSRF-TOKEN')
    if (token !== null) headers['X-XSRF-TOKEN'] = token
  }

  let response: Response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method,
      headers,
      credentials: 'include',
      ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
      ...(options.signal !== undefined ? { signal: options.signal } : {}),
    })
  } catch (cause) {
    // A network failure is not an API error and must not be reported as one:
    // the user's remedy is different.
    throw new NetworkError(cause)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  const payload: unknown = text === '' ? null : safeParse(text)

  if (!response.ok) {
    const error = extractError(payload)
    throw new ApiError(
      response.status,
      error ?? { code: `http.${response.status}`, message: response.statusText },
    )
  }

  return payload as T
}

function safeParse(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

function extractError(payload: unknown): ApiErrorBody | null {
  if (typeof payload !== 'object' || payload === null) return null
  const error = (payload as { error?: unknown }).error
  if (typeof error !== 'object' || error === null) return null

  const { code, message } = error as Partial<ApiErrorBody>
  if (typeof code !== 'string' || typeof message !== 'string') return null

  return error as ApiErrorBody
}

export const api = {
  get: <T>(path: string, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'GET' }),
  post: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'POST', body }),
  patch: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'PATCH', body }),
  put: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'PUT', body }),
  delete: <T>(path: string, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'DELETE' }),
}
