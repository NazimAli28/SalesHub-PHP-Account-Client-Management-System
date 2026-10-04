import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Paginated, User } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { UserFormValues, UserPayload } from './schemas'

export const userKeys = createQueryKeys('users')

export const USER_LIST_CONFIG = {
  filterKeys: ['role', 'team', 'active'],
  defaultSort: 'name',
} as const satisfies ListParamsConfig

export function useUsers(params: ListParams) {
  return useQuery({
    queryKey: userKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<User>>('/users', { query: toApiQuery(params), signal }),
    placeholderData: keepPreviousData,
  })
}

interface UserFormMutationOptions {
  form?: UseFormReturn<UserFormValues>
  onSuccess?: () => void
}

/** Teams and workstations show member counts, so a user change refreshes them too. */
const INVALIDATE = [userKeys.all, ['teams'], ['workstations']]

export function useCreateUser({ form, onSuccess }: UserFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: UserPayload) => api.send<User>('POST', '/users', payload),
    successMessage: 'User created',
    invalidate: INVALIDATE,
    form,
    onSuccess,
  })
}

export function useUpdateUser({ form, onSuccess }: UserFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<UserPayload> }) =>
      api.send<User>('PATCH', `/users/${id}`, payload),
    successMessage: 'User updated',
    invalidate: INVALIDATE,
    form,
    onSuccess,
  })
}

export function useDeleteUser() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/users/${id}`),
    successMessage: 'User deleted',
    invalidate: INVALIDATE,
  })
}

export function useSetUserActive() {
  return useApiMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      api.send<User>('PATCH', `/users/${id}/${active ? 'activate' : 'deactivate'}`),
    successMessage: (_data, { active }) => (active ? 'User activated' : 'User deactivated'),
    invalidate: INVALIDATE,
  })
}
