import { http, HttpResponse } from 'msw'
import { server } from '@/test/server'
import { XSRF_TOKEN } from '@/test/handlers'
import { api, ensureCsrfCookie, isQueued, onUnauthorized } from './client'
import { ApiError } from './errors'

describe('api client', () => {
  it('sends credentials-friendly JSON headers and no XSRF header on GET', async () => {
    let headers: Headers | undefined
    server.use(
      http.get('*/api/ping', ({ request }) => {
        headers = request.headers
        return HttpResponse.json({ data: 'pong' })
      }),
    )

    await expect(api.get('/ping')).resolves.toEqual({ data: 'pong' })
    expect(headers?.get('accept')).toBe('application/json')
    expect(headers?.get('x-xsrf-token')).toBeNull()
  })

  it('fetches the CSRF cookie before a mutation and sends it as X-XSRF-TOKEN', async () => {
    let token: string | null = null
    server.use(
      http.post('*/api/things', ({ request }) => {
        token = request.headers.get('x-xsrf-token')
        return HttpResponse.json({ data: { id: 1 } }, { status: 201 })
      }),
    )

    await api.post('/things', { name: 'Logo pack' })
    expect(token).toBe(XSRF_TOKEN)
  })

  it('URL-decodes the cookie value', async () => {
    document.cookie = `XSRF-TOKEN=${encodeURIComponent('abc=123/+')}; path=/`
    let token: string | null = null
    server.use(
      http.delete('*/api/things/1', ({ request }) => {
        token = request.headers.get('x-xsrf-token')
        return new HttpResponse(null, { status: 204 })
      }),
    )

    await api.delete('/things/1')
    expect(token).toBe('abc=123/+')
  })

  it('refreshes the CSRF cookie and retries once after a 419', async () => {
    document.cookie = 'XSRF-TOKEN=expired; path=/'
    const seenTokens: (string | null)[] = []
    let csrfCalls = 0
    server.use(
      http.get('*/sanctum/csrf-cookie', () => {
        csrfCalls += 1
        document.cookie = `XSRF-TOKEN=fresh; path=/`
        return new HttpResponse(null, { status: 204 })
      }),
      http.patch('*/api/things/1', ({ request }) => {
        const token = request.headers.get('x-xsrf-token')
        seenTokens.push(token)
        return token === 'fresh'
          ? HttpResponse.json({ data: { id: 1 } })
          : HttpResponse.json({ message: 'CSRF token mismatch.' }, { status: 419 })
      }),
    )

    await expect(api.patch('/things/1', {})).resolves.toEqual({ data: { id: 1 } })
    expect(seenTokens).toEqual(['expired', 'fresh'])
    expect(csrfCalls).toBe(1)
  })

  it('gives up after one retry when the 419 persists', async () => {
    server.use(
      http.post('*/api/things', () =>
        HttpResponse.json({ message: 'CSRF token mismatch.' }, { status: 419 }),
      ),
    )
    await expect(api.post('/things')).rejects.toMatchObject({ status: 419 })
  })

  it('maps 422 responses to an ApiError with field errors', async () => {
    server.use(
      http.post('*/api/leads', () =>
        HttpResponse.json(
          {
            message: 'The client id field is required.',
            errors: { client_id: ['The client id field is required.'] },
          },
          { status: 422 },
        ),
      ),
    )

    const error = await api.post('/leads', {}).catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({
      status: 422,
      isValidation: true,
      message: 'The client id field is required.',
    })
    expect((error as ApiError).fieldError('client_id')).toBe('The client id field is required.')
  })

  it('reads code and retry_after from a 429', async () => {
    server.use(
      http.post('*/api/auth/login', () =>
        HttpResponse.json(
          { message: 'Too many login attempts.', code: 'too_many_attempts', retry_after: 47 },
          { status: 429, headers: { 'Retry-After': '47' } },
        ),
      ),
    )
    await expect(api.post('/auth/login', {})).rejects.toMatchObject({
      status: 429,
      code: 'too_many_attempts',
      retryAfter: 47,
    })
  })

  it('uses a friendly fallback message when the body has none', async () => {
    server.use(http.get('*/api/broken', () => new HttpResponse('oops', { status: 500 })))
    await expect(api.get('/broken')).rejects.toMatchObject({
      status: 500,
      message: 'Something went wrong on our side. Please try again.',
    })
  })

  it('reports a 202 as queued for approval', async () => {
    const approval = { id: 12, status: { value: 'pending', label: 'Pending' } }
    server.use(
      http.patch('*/api/leads/5', () => HttpResponse.json({ data: approval }, { status: 202 })),
    )

    const result = await api.send('PATCH', '/leads/5', { stage: 'quoted' })
    expect(isQueued(result)).toBe(true)
    expect(result).toEqual({ kind: 'queued', status: 202, approval })
  })

  it('reports a 200 as applied with the unwrapped resource', async () => {
    server.use(http.patch('*/api/leads/5', () => HttpResponse.json({ data: { id: 5 } })))
    await expect(api.send('PATCH', '/leads/5', {})).resolves.toEqual({
      kind: 'applied',
      status: 200,
      data: { id: 5 },
    })
  })

  it('notifies 401 listeners unless the call opts out', async () => {
    server.use(
      http.get('*/api/leads', () =>
        HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
      ),
    )
    const listener = vi.fn()
    const unsubscribe = onUnauthorized(listener)

    await expect(api.get('/leads')).rejects.toMatchObject({ status: 401 })
    expect(listener).toHaveBeenCalledTimes(1)

    await expect(api.get('/leads', { skipUnauthorizedHandler: true })).rejects.toMatchObject({
      status: 401,
    })
    expect(listener).toHaveBeenCalledTimes(1)
    unsubscribe()
  })

  it('shares one CSRF request between concurrent callers', async () => {
    let csrfCalls = 0
    server.use(
      http.get('*/sanctum/csrf-cookie', () => {
        csrfCalls += 1
        document.cookie = 'XSRF-TOKEN=shared; path=/'
        return new HttpResponse(null, { status: 204 })
      }),
    )
    await Promise.all([ensureCsrfCookie(), ensureCsrfCookie(), ensureCsrfCookie()])
    expect(csrfCalls).toBe(1)
  })

  it('turns network failures into status 0', async () => {
    server.use(http.get('*/api/ping', () => HttpResponse.error()))
    await expect(api.get('/ping')).rejects.toMatchObject({ status: 0, isNetworkError: true })
  })
})
