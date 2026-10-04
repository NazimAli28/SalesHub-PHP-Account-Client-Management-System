import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import {
  readListParams,
  writeListParams,
  type ListParams,
  type ListParamsConfig,
} from '@/api/list-params'

export interface DataTableParams {
  /** Current list state read from the URL. Pass it to your list query hook. */
  params: ListParams
  setPage: (page: number) => void
  setPageSize: (pageSize: number) => void
  setSort: (sort: string) => void
  setSearch: (search: string) => void
  /** Replace one filter's values (an empty array clears it). */
  setFilter: (key: string, values: string[]) => void
  /** Clears search and all filters (keeps sort and page size). */
  resetFilters: () => void
  hasActiveFilters: boolean
  config: ListParamsConfig
}

/**
 * Keeps a list's page, page size, sort, search and filters in the URL, so the state survives a
 * reload and can be shared as a link. Back/forward walk through page changes; typing in search
 * replaces the history entry instead of adding one per keystroke.
 *
 *   const table = useDataTableParams({ filterKeys: ['stage', 'owner'], defaultSort: '-contacted_on' })
 *   const leads = useLeads(table.params)
 */
export function useDataTableParams(config: ListParamsConfig): DataTableParams {
  const [searchParams, setSearchParams] = useSearchParams()
  // Callers usually pass an inline object; key the memo on its contents instead of identity.
  const configKey = JSON.stringify(config)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const stableConfig = useMemo(() => config, [configKey])

  const params = useMemo(
    () => readListParams(searchParams, stableConfig),
    [searchParams, stableConfig],
  )

  const update = useCallback(
    (patch: Partial<ListParams>, options: { replace?: boolean } = {}) => {
      setSearchParams((current) => writeListParams(current, patch, stableConfig), {
        replace: options.replace,
        preventScrollReset: true,
      })
    },
    [setSearchParams, stableConfig],
  )

  return useMemo<DataTableParams>(
    () => ({
      params,
      config: stableConfig,
      setPage: (page) => update({ page }),
      setPageSize: (pageSize) => update({ pageSize }),
      setSort: (sort) => update({ sort }),
      setSearch: (search) => update({ search }, { replace: true }),
      setFilter: (key, values) => update({ filters: { ...params.filters, [key]: values } }),
      resetFilters: () => update({ search: '', filters: {} }),
      hasActiveFilters: params.search !== '' || Object.keys(params.filters).length > 0,
    }),
    [params, stableConfig, update],
  )
}
