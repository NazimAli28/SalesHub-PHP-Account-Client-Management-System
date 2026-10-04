/** Field errors as Laravel returns them on 422: `{ "field": ["message", ...] }`. */
export type FieldErrors = Record<string, string[]>

interface ApiErrorInit {
  status: number
  message: string
  code?: string
  errors?: FieldErrors
  retryAfter?: number
  body?: unknown
}

/**
 * Every failed API call rejects with an ApiError, so screens only handle one error shape.
 * `status` 0 means the request never reached the server (offline, DNS, CORS).
 */
export class ApiError extends Error {
  readonly status: number
  /** Machine-readable reason sent by some endpoints, e.g. `account_inactive`, `too_many_attempts`. */
  readonly code?: string
  /** Field errors (422 only). Keys are API field names, dotted for nested fields. */
  readonly errors: FieldErrors
  /** Seconds until a rate limit resets (429 only). */
  readonly retryAfter?: number
  readonly body?: unknown

  constructor(init: ApiErrorInit) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code
    this.errors = init.errors ?? {}
    this.retryAfter = init.retryAfter
    this.body = init.body
  }

  get isValidation(): boolean {
    return this.status === 422
  }
  get isUnauthenticated(): boolean {
    return this.status === 401
  }
  get isForbidden(): boolean {
    return this.status === 403
  }
  get isNotFound(): boolean {
    return this.status === 404
  }
  get isConflict(): boolean {
    return this.status === 409
  }
  get isRateLimited(): boolean {
    return this.status === 429
  }
  get isNetworkError(): boolean {
    return this.status === 0
  }

  /** First message for a field, handy for inline errors. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

const FALLBACK_MESSAGES: Record<number, string> = {
  0: 'Could not reach the server. Check your connection and try again.',
  400: 'The request was not understood.',
  401: 'Your session has ended. Please sign in again.',
  403: "You don't have permission to do that.",
  404: 'We could not find that record.',
  409: 'This record changed in the meantime. Refresh and try again.',
  419: 'Your session expired. Please try again.',
  422: 'Some fields need your attention.',
  429: 'Too many requests. Please wait a moment and try again.',
  500: 'Something went wrong on our side. Please try again.',
}

export function fallbackMessage(status: number): string {
  return FALLBACK_MESSAGES[status] ?? FALLBACK_MESSAGES[500]!
}

/** Turns anything thrown into a short, human-readable message for toasts and error states. */
export function errorMessage(error: unknown): string {
  if (isApiError(error)) return error.message || fallbackMessage(error.status)
  if (error instanceof Error && error.message) return error.message
  return fallbackMessage(500)
}
