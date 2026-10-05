import { expect, type Page } from '@playwright/test'

/**
 * Calls the API with the page's session. Sanctum treats a request as "from the SPA" (and so
 * accepts the session cookie) only when it carries the SPA's Origin, as a browser would send.
 */
export async function apiRequest(
  page: Page,
  method: 'GET' | 'POST',
  path: string,
): Promise<{ status: number; body: unknown }> {
  const origin = new URL(page.url()).origin
  const cookies = await page.context().cookies()
  const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const response = await page.request.fetch(`${origin}/api${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      Origin: origin,
      Referer: `${origin}/`,
      'X-XSRF-TOKEN': xsrf,
    },
  })
  const text = await response.text()
  return { status: response.status(), body: text ? JSON.parse(text) : null }
}

/** Id of the first record the signed-in user can list for a resource (e.g. "clients"). */
export async function firstId(page: Page, resource: string): Promise<number> {
  const { status, body } = await apiRequest(page, 'GET', `/${resource}?page[size]=1`)
  expect(status, `GET /api/${resource}`).toBe(200)
  return (body as { data: { id: number }[] }).data[0].id
}
