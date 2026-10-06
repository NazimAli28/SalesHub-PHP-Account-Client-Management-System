/**
 * Request plumbing of the browser demo: route registration (with latency, the sign-in check and
 * error handling), JSON helpers and the API's error bodies (docs/api/conventions.md).
 */
import { delay, http, HttpResponse, type HttpHandler } from 'msw'
import { currentUser, persist } from './store'
import type { UserRow } from './types'

export type Json = Record<string, unknown>

/** Thrown inside a handler to answer with an error body. */
export class HttpError extends Error {
  readonly status: number
  readonly body: Json

  constructor(status: number, body: Json) {
    super(typeof body.message === 'string' ? body.message : `HTTP ${status}`)
    this.status = status
    this.body = body
  }
}

export const unauthorized = () => new HttpError(401, { message: 'Unauthenticated.' })
export const forbidden = (message = 'This action is unauthorized.') =>
  new HttpError(403, { message })
export const notFound = () => new HttpError(404, { message: 'No query results for the record.' })
export const badRequest = (message: string) => new HttpError(400, { message })
export const conflict = (message: string, code: string) => new HttpError(409, { message, code })
export const demoMode = (reason: string) =>
  new HttpError(403, {
    message: `The public demo shares its accounts, so ${reason}.`,
    code: 'demo_mode',
  })

export function validationError(errors: Record<string, string[]>): HttpError {
  const messages = Object.values(errors).flat()
  const extra = messages.length - 1
  const message =
    extra > 0
      ? `${messages[0]} (and ${extra} more error${extra === 1 ? '' : 's'})`
      : (messages[0] ?? 'The given data was invalid.')
  return new HttpError(422, { message, errors })
}

export function json(data: unknown, status = 200): Response {
  return HttpResponse.json(data as Json, { status })
}

export function envelope(data: unknown, status = 200): Response {
  return HttpResponse.json({ data } as Json, { status })
}

export function noContent(): Response {
  return new HttpResponse(null, { status: 204 })
}

export async function readBody(request: Request): Promise<Json> {
  try {
    const text = await request.text()
    if (!text) return {}
    const parsed = JSON.parse(text) as unknown
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Json) : {}
  } catch {
    return {}
  }
}

export function idParam(value: string | readonly string[] | undefined): number {
  const id = Number(Array.isArray(value) ? value[0] : value)
  if (!Number.isInteger(id) || id <= 0) throw notFound()
  return id
}

export interface Context {
  request: Request
  url: URL
  params: Record<string, string | readonly string[] | undefined>
  /** The signed-in user (routes registered with `auth: false` get `null`). */
  user: UserRow
  body: () => Promise<Json>
}

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete'
type Resolver = (ctx: Context) => Response | Promise<Response>

/** Small artificial latency, so loading states are visible but the demo stays snappy. */
function latency(): Promise<void> {
  return delay(150 + Math.floor(Math.random() * 150))
}

/**
 * Registers an API route under `*\/api` (any origin or base path). Signed-in routes answer 401
 * without a session; writes are saved to sessionStorage afterwards.
 */
export function route(
  method: Method,
  path: string,
  resolver: Resolver,
  options: { auth?: boolean; prefix?: string } = {},
): HttpHandler {
  const pattern = `*${options.prefix ?? '/api'}${path}`
  return http[method](pattern, async ({ request, params }) => {
    await latency()
    try {
      const user = currentUser()
      if (options.auth !== false && !user) throw unauthorized()
      let cached: Json | null = null
      const response = await resolver({
        request,
        url: new URL(request.url),
        params: params as Context['params'],
        user: user as UserRow,
        body: async () => (cached ??= await readBody(request)),
      })
      if (method !== 'get') persist()
      return response
    } catch (error) {
      if (error instanceof HttpError) return json(error.body, error.status)
      console.error('[demo api]', error)
      return json({ message: 'Server Error' }, 500)
    }
  })
}
