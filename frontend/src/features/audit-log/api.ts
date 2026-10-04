import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Paginated, Schemas, User } from '@/api/types'
import type { FilterOption } from '@/components/data-table'
import { humanizeEvent, humanizeKey } from './format'

export type Activity = Schemas['ActivityResource']

export const auditKeys = createQueryKeys('audit-log')

export const AUDIT_LIST_CONFIG = {
  filterKeys: ['causer', 'subject_type', 'log_name', 'event', 'from', 'to'],
  defaultSort: '-created_at',
} as const satisfies ListParamsConfig

export function useAuditLog(params: ListParams) {
  return useQuery({
    queryKey: auditKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Activity>>('/audit-log', { query: toApiQuery(params), signal }),
    placeholderData: keepPreviousData,
  })
}

/** Everyone who can appear as a causer (inactive users included: the log is historical). */
export function useCauserOptions({ enabled }: { enabled: boolean }) {
  return useQuery({
    queryKey: ['users', 'options', 'causers'],
    queryFn: ({ signal }) =>
      api.get<Paginated<User>>('/users', {
        query: { 'page[size]': 100, sort: 'name' },
        signal,
      }),
    select: (response): FilterOption[] =>
      response.data.map((user) => ({ value: String(user.id), label: user.name })),
    enabled,
    staleTime: 5 * 60_000,
  })
}

const toOptions = (values: readonly string[], label: (value: string) => string): FilterOption[] =>
  values.map((value) => ({ value, label: label(value) }))

/** Morph aliases from the API's AppServiceProvider::enforceMorphMap. */
export const SUBJECT_TYPE_OPTIONS = toOptions(
  [
    'platform_account',
    'social_account',
    'client',
    'lead',
    'order',
    'order_item',
    'payment',
    'user',
    'team',
    'workstation',
    'service',
    'approval_request',
  ],
  humanizeKey,
)

/** Log names the API writes: model changes, auth, user management, approvals and security. */
export const LOG_NAME_OPTIONS = toOptions(
  ['model', 'auth', 'user', 'approval', 'security'],
  humanizeKey,
)

export const EVENT_OPTIONS = toOptions(
  [
    'created',
    'updated',
    'deleted',
    'login',
    'logout',
    'login_failed',
    'login_blocked_inactive',
    'login_blocked_ip',
    'activated',
    'deactivated',
    'role_changed',
    'password_reset',
    'credentials_revealed',
    'approved',
    'rejected',
    'cancelled',
  ],
  humanizeEvent,
)
