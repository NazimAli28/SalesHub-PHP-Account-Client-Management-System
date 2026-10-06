/**
 * List engine of the browser demo: the same `filter[...]`, `sort` and `page[...]` semantics as the
 * backend's IndexQuery classes (spatie/laravel-query-builder) and Laravel's paginator output.
 */
import { badRequest, validationError, type Json } from './http'

type Value = string | number | boolean | null | undefined

/** `(row, values, raw)`: `values` is the comma-split filter value, `raw` the original string. */
export type FilterFn<T> = (row: T, values: string[], raw: string) => boolean

export interface ListSpec<T> {
  filters: Record<string, FilterFn<T>>
  /** Columns searched by `filter[search]` (case-insensitive contains). */
  search?: (row: T) => Value[]
  sorts: Record<string, (row: T) => Value>
  defaultSort: string
}

const DATE = /^\d{4}-\d{2}-\d{2}$/

function normalize(value: Value): string {
  if (value === true) return '1'
  if (value === false) return '0'
  if (value === null || value === undefined) return ''
  return String(value)
}

function asBoolString(value: string): string {
  const lower = value.toLowerCase()
  if (lower === 'true') return '1'
  if (lower === 'false') return '0'
  return value
}

/** `AllowedFilter::exact`: any of the comma-separated values. */
export function exact<T>(get: (row: T) => Value): FilterFn<T> {
  return (row, values) => values.map(asBoolString).includes(normalize(get(row)))
}

/** A boolean callback filter: `filter[x]=1|true|0|false`. */
export function flag<T>(test: (row: T) => boolean): FilterFn<T> {
  return (row, _values, raw) => test(row) === truthy(raw)
}

/** Like `flag`, but only narrows when true (`filter[open]=1`, `filter[reviewable]=1`). */
export function onlyWhenTrue<T>(test: (row: T) => boolean): FilterFn<T> {
  return (row, _values, raw) => !truthy(raw) || test(row)
}

export function truthy(raw: string): boolean {
  return ['1', 'true', 'on', 'yes'].includes(raw.toLowerCase())
}

/** `DateFilter('>=')` / `('<=')` on the date part of a date or timestamp. */
export function dateFrom<T>(get: (row: T) => string | null | undefined): FilterFn<T> {
  return (row, _values, raw) => {
    const value = get(row)
    return typeof value === 'string' && value.slice(0, 10) >= raw
  }
}

export function dateTo<T>(get: (row: T) => string | null | undefined): FilterFn<T> {
  return (row, _values, raw) => {
    const value = get(row)
    return typeof value === 'string' && value.slice(0, 10) <= raw
  }
}

export interface ListQuery {
  filters: Record<string, string>
  sort: string | null
  page: number
  size: number
}

export function parseListQuery(url: URL): ListQuery {
  const filters: Record<string, string> = {}
  let sort: string | null = null
  let page = 1
  let size = 25
  for (const [key, value] of url.searchParams) {
    const filter = /^filter\[(.+)\]$/.exec(key)
    if (filter?.[1]) filters[filter[1]] = value
    else if (key === 'sort') sort = value
    else if (key === 'page[number]' || key === 'page') page = Math.max(1, Number(value) || 1)
    else if (key === 'page[size]') size = Number(value) || 25
  }
  return { filters, sort, page, size: Math.max(1, Math.min(100, Math.floor(size))) }
}

/** Applies filters, search and sort; 400 for names the endpoint does not allow (like spatie). */
export function query<T>(rows: T[], url: URL, spec: ListSpec<T>): T[] {
  const { filters, sort } = parseListQuery(url)
  const allowed = [...Object.keys(spec.filters), ...(spec.search ? ['search'] : [])]
  const unknown = Object.keys(filters).filter((name) => !allowed.includes(name))
  if (unknown.length > 0) {
    throw badRequest(
      `Requested filter(s) \`${unknown.join(', ')}\` are not allowed. Allowed filter(s) are \`${allowed.join(', ')}\`.`,
    )
  }

  const errors: Record<string, string[]> = {}
  for (const [name, raw] of Object.entries(filters)) {
    if (
      (name.endsWith('_from') || name.endsWith('_to') || name === 'from' || name === 'to') &&
      raw !== '' &&
      !DATE.test(raw)
    ) {
      errors[`filter.${name}`] = [
        `The ${name.replace(/_/g, ' ')} filter must be a date (YYYY-MM-DD).`,
      ]
    }
    if (name === 'search' && raw.length > 100) {
      errors['filter.search'] = ['The search filter may not be greater than 100 characters.']
    }
  }
  if (Object.keys(errors).length > 0) throw validationError(errors)

  let result = rows
  for (const [name, raw] of Object.entries(filters)) {
    if (raw === '') continue
    if (name === 'search') {
      const term = raw.trim().toLowerCase()
      if (term === '' || !spec.search) continue
      const search = spec.search
      result = result.filter((row) =>
        search(row).some((value) => normalize(value).toLowerCase().includes(term)),
      )
      continue
    }
    const filter = spec.filters[name]
    const values = raw
      .split(',')
      .map((v) => v.trim())
      .filter((v) => v !== '')
    if (filter) result = result.filter((row) => filter(row, values, raw))
  }

  const sortFields = (sort ?? spec.defaultSort)
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
  const badSorts = sortFields.filter((field) => !(field.replace(/^-/, '') in spec.sorts))
  if (badSorts.length > 0) {
    throw badRequest(
      `Requested sort(s) \`${badSorts.join(', ')}\` is not allowed. Allowed sort(s) are \`${Object.keys(spec.sorts).join(', ')}\`.`,
    )
  }
  // The endpoints' default sorts end with a unique key; keep the result stable the same way.
  if (!sortFields.some((f) => f.replace(/^-/, '') === 'id') && 'id' in spec.sorts)
    sortFields.push('id')

  return sortRows(result, sortFields, spec.sorts)
}

export function sortRows<T>(
  rows: T[],
  fields: string[],
  getters: Record<string, (row: T) => Value>,
): T[] {
  const compiled = fields.map((field) => {
    const desc = field.startsWith('-')
    const get = getters[field.replace(/^-/, '')]!
    return { desc, get }
  })
  return [...rows].sort((a, b) => {
    for (const { desc, get } of compiled) {
      const order = compare(get(a), get(b))
      if (order !== 0) return desc ? -order : order
    }
    return 0
  })
}

function compare(a: Value, b: Value): number {
  const aNull = a === null || a === undefined
  const bNull = b === null || b === undefined
  // SQL: NULLs sort first ascending.
  if (aNull || bNull) return aNull && bNull ? 0 : aNull ? -1 : 1
  if (typeof a === 'number' && typeof b === 'number') return a - b
  if (typeof a === 'boolean' || typeof b === 'boolean') return Number(a) - Number(b)
  return String(a).localeCompare(String(b), 'en', { sensitivity: 'base' })
}

/** Laravel's LengthAwarePaginator JSON (`data`, `links`, `meta`). */
export function paginate<T>(rows: T[], url: URL, present: (row: T) => unknown): Json {
  const { page, size } = parseListQuery(url)
  const total = rows.length
  const lastPage = Math.max(1, Math.ceil(total / size))
  const start = (page - 1) * size
  const slice = rows.slice(start, start + size)
  const path = `${url.origin}${url.pathname}`

  const pageUrl = (n: number) => {
    const params = new URLSearchParams(url.search)
    params.delete('page')
    params.delete('page[number]')
    params.set('page[size]', String(size))
    params.set('page[number]', String(n))
    return `${path}?${params.toString()}`
  }

  const links = [
    { url: page > 1 ? pageUrl(page - 1) : null, label: '&laquo; Previous', active: false },
    ...Array.from({ length: lastPage }, (_, i) => ({
      url: pageUrl(i + 1),
      label: String(i + 1),
      active: i + 1 === page,
    })),
    { url: page < lastPage ? pageUrl(page + 1) : null, label: 'Next &raquo;', active: false },
  ]

  return {
    data: slice.map(present),
    links: {
      first: pageUrl(1),
      last: pageUrl(lastPage),
      prev: page > 1 ? pageUrl(page - 1) : null,
      next: page < lastPage ? pageUrl(page + 1) : null,
    },
    meta: {
      current_page: page,
      from: slice.length > 0 ? start + 1 : null,
      last_page: lastPage,
      links,
      path,
      per_page: size,
      to: slice.length > 0 ? start + slice.length : null,
      total,
    },
  }
}
