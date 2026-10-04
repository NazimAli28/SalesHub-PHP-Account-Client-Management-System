import type { ImportRecord } from './types'
import { buildUrl, type Query } from '@/api/client'
import { toast } from 'sonner'
import { ApiError, errorMessage, fallbackMessage } from '@/api/errors'

/** The `filename="..."` of a Content-Disposition header. */
export function filenameFromDisposition(header: string | null, fallback: string): string {
  const match = header?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  return match?.[1] ? decodeURIComponent(match[1]) : fallback
}

/**
 * Downloads a file from the API with the session cookie (a plain link would work too, but this
 * reports errors as toasts instead of navigating to a JSON error page).
 */
export async function downloadFile(
  path: string,
  query?: Query,
  fallbackName = 'download.csv',
): Promise<void> {
  let response: Response
  try {
    response = await fetch(buildUrl(path, query), {
      credentials: 'include',
      headers: { Accept: 'text/csv, application/json' },
    })
  } catch {
    throw new ApiError({ status: 0, message: fallbackMessage(0) })
  }

  if (!response.ok) {
    let message = fallbackMessage(response.status)
    try {
      const body = (await response.json()) as { message?: unknown }
      if (typeof body.message === 'string' && body.message) message = body.message
    } catch {
      // Not JSON: keep the fallback message.
    }
    throw new ApiError({ status: response.status, message })
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filenameFromDisposition(response.headers.get('Content-Disposition'), fallbackName)
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export async function downloadImportErrors(record: Pick<ImportRecord, 'id'>) {
  try {
    await downloadFile(`/imports/${record.id}/errors`, undefined, `import-${record.id}-errors.csv`)
  } catch (error) {
    toast.error(errorMessage(error))
  }
}
