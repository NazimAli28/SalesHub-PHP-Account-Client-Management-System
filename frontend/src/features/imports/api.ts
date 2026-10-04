/**
 * Imports data layer: query keys, queries and mutations for the CSV import wizard.
 */
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, buildUrl, ensureCsrfCookie, readCookie } from '@/api/client'
import { ApiError, fallbackMessage, type FieldErrors } from '@/api/errors'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, Paginated } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type {
  ColumnMapping,
  ImportRecord,
  ImportTypeKey,
  PreviewResult,
  UploadedImport,
} from './types'

export const importKeys = createQueryKeys('imports')

const RUNNING = new Set(['queued', 'processing'])
export const POLL_INTERVAL_MS = 1000

/** POST /imports as multipart (the shared client only sends JSON). */
export async function uploadImport(type: ImportTypeKey, file: File): Promise<UploadedImport> {
  await ensureCsrfCookie()
  const body = new FormData()
  body.set('type', type)
  body.set('file', file)
  const token = readCookie('XSRF-TOKEN')

  let response: Response
  try {
    response = await fetch(buildUrl('/imports'), {
      method: 'POST',
      body,
      credentials: 'include',
      headers: { Accept: 'application/json', ...(token ? { 'X-XSRF-TOKEN': token } : {}) },
    })
  } catch {
    throw new ApiError({ status: 0, message: fallbackMessage(0) })
  }

  const payload = (await response.json().catch(() => ({}))) as {
    data?: UploadedImport
    message?: string
    errors?: FieldErrors
  }
  if (!response.ok || !payload.data) {
    throw new ApiError({
      status: response.status,
      message: payload.errors?.file?.[0] ?? payload.message ?? fallbackMessage(response.status),
      errors: payload.errors,
    })
  }
  return payload.data
}

export function useUploadImport(options: { onSuccess?: (data: UploadedImport) => void } = {}) {
  return useApiMutation({
    mutationFn: ({ type, file }: { type: ImportTypeKey; file: File }) => uploadImport(type, file),
    invalidate: [importKeys.lists()],
    onSuccess: options.onSuccess,
  })
}

/** Validates the first rows with the real create rules; writes nothing. */
export function usePreviewImport(id: number, mapping: ColumnMapping) {
  return useQuery({
    queryKey: [...importKeys.detail(id), 'preview', mapping] as const,
    queryFn: ({ signal }) =>
      api.post<Envelope<PreviewResult>>(`/imports/${id}/preview`, { mapping }, { signal }),
    select: (response) => response.data,
    gcTime: 0,
    staleTime: 0,
    retry: false,
  })
}

export function useStartImport(options: { onSuccess?: (data: ImportRecord) => void } = {}) {
  return useApiMutation({
    mutationFn: async ({ id, mapping }: { id: number; mapping: ColumnMapping }) =>
      (await api.post<Envelope<ImportRecord>>(`/imports/${id}/start`, { mapping })).data,
    invalidate: [importKeys.all],
    onSuccess: options.onSuccess,
  })
}

/** One import; polls every second while it is queued or processing. */
export function useImport(id: number | null) {
  return useQuery({
    queryKey: importKeys.detail(id ?? 0),
    enabled: id !== null,
    queryFn: ({ signal }) => api.get<Envelope<ImportRecord>>(`/imports/${id}`, { signal }),
    select: (response) => response.data,
    refetchInterval: (query) => {
      const status = query.state.data?.data.status.value
      return status && RUNNING.has(status) ? POLL_INTERVAL_MS : false
    },
  })
}

export function useImports() {
  return useQuery({
    queryKey: importKeys.list({}),
    queryFn: ({ signal }) =>
      api.get<Paginated<ImportRecord>>('/imports', { query: { 'page[size]': 5 }, signal }),
    placeholderData: keepPreviousData,
  })
}
