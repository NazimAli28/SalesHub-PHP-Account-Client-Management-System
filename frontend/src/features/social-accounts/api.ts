import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Paginated } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { ComboboxOption } from '@/components/form'
import type { SocialAccountFormValues, SocialAccountPayload } from './schemas'
import type { PlatformAccountRef, SocialAccount } from './types'

interface FormMutationOptions {
  form?: UseFormReturn<SocialAccountFormValues>
  onSuccess?: () => void
}

export const socialAccountKeys = createQueryKeys('social-accounts')

export const SOCIAL_ACCOUNT_LIST_CONFIG = {
  filterKeys: ['platform', 'in_use', 'platform_account'],
  defaultSort: '-created_at',
} as const satisfies ListParamsConfig

const LIST_INCLUDES = ['platformAccount'] as const

/** Platform account lists and details show social account counts/lists: refresh them too. */
const PLATFORM_ACCOUNTS_KEY = ['platform-accounts']

export function useSocialAccounts(params: ListParams) {
  return useQuery({
    queryKey: socialAccountKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<SocialAccount>>('/social-accounts', {
        query: toApiQuery(params, { include: LIST_INCLUDES }),
        signal,
      }),
    placeholderData: keepPreviousData,
  })
}

export function useCreateSocialAccount({ form, onSuccess }: FormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: SocialAccountPayload) =>
      api.send<SocialAccount>('POST', '/social-accounts', payload),
    successMessage: 'Social account created',
    invalidate: [socialAccountKeys.all, PLATFORM_ACCOUNTS_KEY],
    form,
    onSuccess,
  })
}

export function useUpdateSocialAccount({ form, onSuccess }: FormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<SocialAccountPayload> }) =>
      api.send<SocialAccount>('PATCH', `/social-accounts/${id}`, payload),
    successMessage: 'Social account updated',
    invalidate: [socialAccountKeys.all, PLATFORM_ACCOUNTS_KEY],
    form,
    onSuccess,
  })
}

export function useDeleteSocialAccount() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/social-accounts/${id}`),
    successMessage: 'Social account deleted',
    invalidate: [socialAccountKeys.all, PLATFORM_ACCOUNTS_KEY],
  })
}

/** Platform account picker (the API only returns accounts the user may see). */
export async function fetchPlatformAccountOptions(
  search: string,
  signal: AbortSignal,
): Promise<ComboboxOption[]> {
  const response = await api.get<Paginated<PlatformAccountRef>>('/platform-accounts', {
    query: { 'filter[search]': search, 'page[size]': 20, sort: 'email' },
    signal,
  })
  return response.data.map((account) => ({
    value: account.id,
    label: account.email,
    description: account.discord_username ? `@${account.discord_username}` : undefined,
  }))
}

/** Platform account filter facet: the first 100 visible accounts, searchable in the popover. */
export function usePlatformAccountFilterOptions() {
  return useQuery({
    queryKey: ['platform-accounts', 'options', 'filter'],
    queryFn: ({ signal }) =>
      api.get<Paginated<PlatformAccountRef>>('/platform-accounts', {
        query: { 'page[size]': 100, sort: 'email' },
        signal,
      }),
    select: (response) =>
      response.data.map((account) => ({ value: String(account.id), label: account.email })),
    staleTime: 5 * 60_000,
  })
}
