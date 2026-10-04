/**
 * List state <-> URL <-> API query.
 *
 * The browser URL uses short, readable keys so links can be shared:
 *   /leads?page=2&size=50&sort=-contacted_on&q=pixel&stage=new,engaged&owner=4
 * The API uses the spatie/laravel-query-builder format (docs/api/conventions.md):
 *   /api/leads?page[number]=2&page[size]=50&sort=-contacted_on&filter[search]=pixel&filter[stage]=new,engaged&filter[owner]=4
 * Defaults are left out of the URL to keep it clean.
 */
import type { QueryValue } from './client'

export const PAGE_SIZES = [10, 25, 50, 100] as const
export const DEFAULT_PAGE_SIZE = 25

export interface ListParams {
  page: number
  pageSize: number
  /** Comma-separated sort fields, `-` prefix for descending. Empty = endpoint default. */
  sort: string
  /** Free-text search, sent as `filter[search]`. */
  search: string
  /** Exact filters, sent as `filter[key]=a,b`. Only non-empty filters are present. */
  filters: Record<string, string[]>
}

export interface ListParamsConfig {
  /** URL/API filter names this list understands, e.g. `['stage', 'owner']`. Others are ignored. */
  filterKeys: readonly string[]
  /** The endpoint's default sort, shown as the active sort when the URL has none. */
  defaultSort?: string
  defaultPageSize?: number
}

const PAGE = 'page'
const SIZE = 'size'
const SORT = 'sort'
const SEARCH = 'q'
const RESERVED = new Set([PAGE, SIZE, SORT, SEARCH])

function positiveInt(value: string | null, fallback: number): number {
  const number = Number(value)
  return Number.isInteger(number) && number > 0 ? number : fallback
}

export function readListParams(search: URLSearchParams, config: ListParamsConfig): ListParams {
  const defaultSize = config.defaultPageSize ?? DEFAULT_PAGE_SIZE
  const size = positiveInt(search.get(SIZE), defaultSize)
  const filters: Record<string, string[]> = {}
  for (const key of config.filterKeys) {
    if (RESERVED.has(key)) throw new Error(`"${key}" is reserved and cannot be a filter key`)
    const values = (search.get(key) ?? '').split(',').filter(Boolean)
    if (values.length > 0) filters[key] = values
  }
  return {
    page: positiveInt(search.get(PAGE), 1),
    pageSize: (PAGE_SIZES as readonly number[]).includes(size) ? size : defaultSize,
    sort: search.get(SORT) ?? config.defaultSort ?? '',
    search: search.get(SEARCH) ?? '',
    filters,
  }
}

/**
 * Applies a partial change to the URL search params. Any change other than `page` resets the
 * page to 1, because the old page number is meaningless for a new filter or sort.
 */
export function writeListParams(
  current: URLSearchParams,
  patch: Partial<ListParams>,
  config: ListParamsConfig,
): URLSearchParams {
  const next = new URLSearchParams(current)
  const set = (key: string, value: string, defaultValue = '') => {
    if (value === '' || value === defaultValue) next.delete(key)
    else next.set(key, value)
  }

  if (patch.pageSize !== undefined) {
    set(SIZE, String(patch.pageSize), String(config.defaultPageSize ?? DEFAULT_PAGE_SIZE))
  }
  if (patch.sort !== undefined) set(SORT, patch.sort, config.defaultSort ?? '')
  if (patch.search !== undefined) set(SEARCH, patch.search.trim())
  if (patch.filters !== undefined) {
    for (const key of config.filterKeys) set(key, (patch.filters[key] ?? []).join(','))
  }

  const onlyPageChanged = Object.keys(patch).every((key) => key === 'page')
  if (patch.page !== undefined && onlyPageChanged) set(PAGE, String(patch.page), '1')
  else next.delete(PAGE)

  return next
}

export interface ApiQueryExtras {
  /** Relations to embed, e.g. `['client', 'owner']` (sent as `include=client,owner`). */
  include?: readonly string[]
}

/** Converts list state into the API's query parameters (pass to `api.get(path, { query })`). */
export function toApiQuery(
  params: ListParams,
  extras: ApiQueryExtras = {},
): Record<string, QueryValue | QueryValue[]> {
  const query: Record<string, QueryValue | QueryValue[]> = {
    'page[number]': params.page,
    'page[size]': params.pageSize,
  }
  if (params.sort) query.sort = params.sort
  if (params.search.trim()) query['filter[search]'] = params.search.trim()
  for (const [key, values] of Object.entries(params.filters)) {
    if (values.length > 0) query[`filter[${key}]`] = values
  }
  if (extras.include?.length) query.include = extras.include.join(',')
  return query
}

/** `'-contacted_on'` -> `{ id: 'contacted_on', desc: true }` (first field only). */
export function parseSort(sort: string): { id: string; desc: boolean } | null {
  const first = sort.split(',')[0]?.trim()
  if (!first) return null
  return first.startsWith('-') ? { id: first.slice(1), desc: true } : { id: first, desc: false }
}

export function formatSort(sort: { id: string; desc: boolean } | null): string {
  if (!sort) return ''
  return `${sort.desc ? '-' : ''}${sort.id}`
}
