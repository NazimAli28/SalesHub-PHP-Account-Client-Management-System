/**
 * Minimal typed HTTP client for the SalesHub API (Laravel + Sanctum SPA cookie auth).
 *
 * - Same-origin cookies: `credentials: 'include'`, `Accept: application/json`.
 * - CSRF: mutations copy the `XSRF-TOKEN` cookie into `X-XSRF-TOKEN`. The cookie is fetched from
 *   `/sanctum/csrf-cookie` when missing, and once more (with one retry) after a 419.
 * - Errors: every non-2xx response rejects with an `ApiError` (see ./errors.ts).
 * - 202 Accepted: `api.send()` reports it as `{ kind: 'queued' }`, so screens can say
 *   "Sent for approval" instead of "Saved".
 * - 401: registered listeners run (the app clears auth state and redirects to /login).
 */
import { ApiError, fallbackMessage, type FieldErrors } from './errors'
import type { ApprovalRequest } from './types'

const API_ORIGIN = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '')
export const API_PREFIX = `${API_ORIGIN}/api`
const CSRF_COOKIE_URL = `${API_ORIGIN}/sanctum/csrf-cookie`
const XSRF_COOKIE = 'XSRF-TOKEN'

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export type QueryValue = string | number | boolean | null | undefined
export type Query = URLSearchParams | Record<string, QueryValue | QueryValue[]>

export interface RequestOptions {
  query?: Query
  body?: unknown
  signal?: AbortSignal
  headers?: Record<string, string>
  /**
   * Skip the global 401 handler. Used by `/auth/me` (a 401 there just means "signed out") and
   * by the login call itself.
   */
  skipUnauthorizedHandler?: boolean
}

export interface RawResponse<T> {
  status: number
  data: T
}

/** Result of a write that may need approval (docs/api/conventions.md, "Writes that may need approval"). */
export type WriteResult<T> =
  | { kind: 'applied'; status: number; data: T }
  | { kind: 'queued'; status: 202; approval: ApprovalRequest }

// ---------------------------------------------------------------------------
// 401 listeners
// ---------------------------------------------------------------------------

type UnauthorizedListener = (error: ApiError) => void
const unauthorizedListeners = new Set<UnauthorizedListener>()

/** Register a callback for 401 responses. Returns an unsubscribe function. */
export function onUnauthorized(listener: UnauthorizedListener): () => void {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

export function readCookie(name: string): string | null {
  const prefix = `${name}=`
  for (const part of document.cookie.split(';')) {
    const cookie = part.trim()
    if (cookie.startsWith(prefix)) return decodeURIComponent(cookie.slice(prefix.length))
  }
  return null
}

let csrfRequest: Promise<void> | null = null

/**
 * Makes sure the `XSRF-TOKEN` cookie exists. Concurrent callers share one request.
 * Pass `force` to refresh it (after a 419, or right before login).
 */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie(XSRF_COOKIE)) return Promise.resolve()
  if (!csrfRequest) {
    csrfRequest = fetch(CSRF_COOKIE_URL, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
      .then((response) => {
        if (!response.ok)
          throw new ApiError({ status: response.status, message: fallbackMessage(response.status) })
      })
      .catch((error: unknown) => {
        if (error instanceof ApiError) throw error
        throw new ApiError({ status: 0, message: fallbackMessage(0) })
      })
      .finally(() => {
        csrfRequest = null
      })
  }
  return csrfRequest
}

// ---------------------------------------------------------------------------
// Core request
// ---------------------------------------------------------------------------

export function buildUrl(path: string, query?: Query): string {
  const url = `${API_PREFIX}${path.startsWith('/') ? path : `/${path}`}`
  if (!query) return url
  const params = query instanceof URLSearchParams ? query : toSearchParams(query)
  const search = params.toString()
  return search ? `${url}?${search}` : url
}

function toSearchParams(query: Record<string, QueryValue | QueryValue[]>): URLSearchParams {
  const params = new URLSearchParams()
  for (const [key, raw] of Object.entries(query)) {
    const values = (Array.isArray(raw) ? raw : [raw]).filter(
      (value): value is string | number | boolean =>
        value !== null && value !== undefined && value !== '',
    )
    if (values.length === 0) continue
    // The API takes comma-separated lists (`filter[stage]=new,engaged`).
    params.set(key, values.map(String).join(','))
  }
  return params
}

async function parseBody(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined
  const text = await response.text()
  if (!text) return undefined
  try {
    return JSON.parse(text) as unknown
  } catch {
    return text
  }
}

function toApiError(response: Response, body: unknown): ApiError {
  const payload = (typeof body === 'object' && body !== null ? body : {}) as {
    message?: unknown
    code?: unknown
    errors?: unknown
    retry_after?: unknown
  }
  const header = Number(response.headers.get('Retry-After'))
  const retryAfter =
    typeof payload.retry_after === 'number'
      ? payload.retry_after
      : Number.isFinite(header) && header > 0
        ? header
        : undefined

  return new ApiError({
    status: response.status,
    message:
      typeof payload.message === 'string' && payload.message
        ? payload.message
        : fallbackMessage(response.status),
    code: typeof payload.code === 'string' ? payload.code : undefined,
    errors: isFieldErrors(payload.errors) ? payload.errors : undefined,
    retryAfter,
    body,
  })
}

function isFieldErrors(value: unknown): value is FieldErrors {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

async function request<T>(
  method: Method,
  path: string,
  options: RequestOptions = {},
  isRetry = false,
): Promise<RawResponse<T>> {
  const isMutation = method !== 'GET'
  if (isMutation) await ensureCsrfCookie()

  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  if (isMutation) {
    const token = readCookie(XSRF_COOKIE)
    if (token) headers['X-XSRF-TOKEN'] = token
  }

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      credentials: 'include',
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new ApiError({ status: 0, message: fallbackMessage(0) })
  }

  // 419: the CSRF token expired (e.g. the session rotated). Refresh it and retry once.
  if (response.status === 419 && !isRetry) {
    await ensureCsrfCookie(true)
    return request<T>(method, path, options, true)
  }

  const body = await parseBody(response)

  if (!response.ok) {
    const error = toApiError(response, body)
    if (error.status === 401 && !options.skipUnauthorizedHandler) {
      unauthorizedListeners.forEach((listener) => listener(error))
    }
    throw error
  }

  return { status: response.status, data: body as T }
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

export const api = {
  /** GET, returns the parsed JSON body as-is (e.g. `Envelope<Lead>` or `Paginated<Lead>`). */
  async get<T>(path: string, options?: Omit<RequestOptions, 'body'>): Promise<T> {
    return (await request<T>('GET', path, options)).data
  },
  async post<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T> {
    return (await request<T>('POST', path, { ...options, body })).data
  },
  async put<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T> {
    return (await request<T>('PUT', path, { ...options, body })).data
  },
  async patch<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T> {
    return (await request<T>('PATCH', path, { ...options, body })).data
  },
  async delete<T = void>(path: string, options?: RequestOptions): Promise<T> {
    return (await request<T>('DELETE', path, options)).data
  },
  /**
   * A write that can be queued for approval (update/delete of approvable records).
   * Resolves to `{ kind: 'queued', approval }` on 202, `{ kind: 'applied', data }` otherwise.
   * `data` is the unwrapped resource (or `undefined` for 204).
   */
  async send<T>(
    method: Exclude<Method, 'GET'>,
    path: string,
    body?: unknown,
    options?: RequestOptions,
  ): Promise<WriteResult<T>> {
    const response = await request<{ data?: unknown } | undefined>(method, path, {
      ...options,
      body,
    })
    if (response.status === 202) {
      return { kind: 'queued', status: 202, approval: response.data?.data as ApprovalRequest }
    }
    return { kind: 'applied', status: response.status, data: response.data?.data as T }
  },
}

export function isQueued<T>(
  result: unknown,
): result is Extract<WriteResult<T>, { kind: 'queued' }> {
  return (
    typeof result === 'object' &&
    result !== null &&
    (result as { kind?: unknown }).kind === 'queued'
  )
}
