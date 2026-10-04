/** Client notes and activity timeline (Client 360). Notes are additive: no approval flow. */
import { useInfiniteQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Envelope, IsoDateTime, Paginated, UserSummary } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'

export interface ClientNote {
  id: number
  client_id: number
  body: string
  is_pinned: boolean
  author: UserSummary | null
  /** Only the author edits the text. */
  can_edit: boolean
  /** The author, or a user with `clients.update`. */
  can_delete: boolean
  created_at: IsoDateTime
  updated_at: IsoDateTime
}

export interface TimelineItem {
  /** `note-5` or `activity-12`. */
  id: string
  kind: 'note' | 'activity'
  at: IsoDateTime
  actor: { id: number; name: string } | null
  summary: string
  /** SPA path of the related record. */
  link: string | null
}

export const NOTE_MAX_LENGTH = 2000
const PAGE_SIZE = 25

export const clientNoteKeys = {
  notes: (clientId: number) => ['clients', 'notes', clientId] as const,
  timeline: (clientId: number) => ['clients', 'timeline', clientId] as const,
}

function nextPage<T>(last: Paginated<T>): number | undefined {
  return last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined
}

export function useClientNotes(clientId: number) {
  return useInfiniteQuery({
    queryKey: clientNoteKeys.notes(clientId),
    queryFn: ({ pageParam, signal }) =>
      api.get<Paginated<ClientNote>>(`/clients/${clientId}/notes`, {
        query: { 'page[number]': pageParam, 'page[size]': PAGE_SIZE },
        signal,
      }),
    initialPageParam: 1,
    getNextPageParam: nextPage,
  })
}

export function useClientTimeline(clientId: number, { enabled = true } = {}) {
  return useInfiniteQuery({
    queryKey: clientNoteKeys.timeline(clientId),
    queryFn: ({ pageParam, signal }) =>
      api.get<Paginated<TimelineItem>>(`/clients/${clientId}/timeline`, {
        query: { 'page[number]': pageParam, 'page[size]': PAGE_SIZE },
        signal,
      }),
    initialPageParam: 1,
    getNextPageParam: nextPage,
    enabled,
  })
}

/** Notes also appear in the timeline, so both lists refresh after every write. */
const refreshed = (clientId: number) => [
  clientNoteKeys.notes(clientId),
  clientNoteKeys.timeline(clientId),
]

export function useAddClientNote(clientId: number) {
  return useApiMutation({
    mutationFn: (payload: { body: string; is_pinned?: boolean }) =>
      api.post<Envelope<ClientNote>>(`/clients/${clientId}/notes`, payload).then((r) => r.data),
    successMessage: 'Note added',
    invalidate: refreshed(clientId),
  })
}

export function useUpdateClientNote(clientId: number) {
  return useApiMutation({
    mutationFn: ({ id, ...payload }: { id: number; body?: string; is_pinned?: boolean }) =>
      api
        .patch<Envelope<ClientNote>>(`/clients/${clientId}/notes/${id}`, payload)
        .then((r) => r.data),
    invalidate: refreshed(clientId),
  })
}

export function useDeleteClientNote(clientId: number) {
  return useApiMutation({
    mutationFn: (id: number) => api.delete(`/clients/${clientId}/notes/${id}`),
    successMessage: 'Note deleted',
    invalidate: refreshed(clientId),
  })
}
