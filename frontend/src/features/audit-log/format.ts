/** Placeholder the API puts in place of secret-looking values (ActivityResource::scrub). */
export const REDACTED_MARKER = '[redacted]'
export const REDACTED_LABEL = '•••• (redacted)'

/** `security.credentials_revealed` -> `Credentials revealed`; `login_failed` -> `Login failed`. */
export function humanizeEvent(event: string | null | undefined): string {
  if (!event) return 'Activity'
  const name = event.includes('.') ? event.slice(event.lastIndexOf('.') + 1) : event
  const words = name.replace(/[_-]+/g, ' ').trim()
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : 'Activity'
}

/** `platform_account` -> `Platform account`. */
export function humanizeKey(key: string): string {
  const words = key.replace(/[_.-]+/g, ' ').trim()
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : key
}

/** Any logged value as short display text (`null` -> an em dash, objects -> JSON). */
export function displayValue(value: unknown): string {
  if (value === REDACTED_MARKER) return REDACTED_LABEL
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'string') return value
  if (typeof value === 'number') return String(value)
  if (Array.isArray(value) && value.every((item) => typeof item !== 'object')) {
    return value.map((item) => displayValue(item)).join(', ') || '—'
  }
  return JSON.stringify(value)
}

export function isRedacted(value: unknown): boolean {
  return value === REDACTED_MARKER
}

export interface ChangeRow {
  attribute: string
  old: unknown
  next: unknown
  hasOld: boolean
  hasNext: boolean
}

type Bag = Record<string, unknown>

function asBag(value: unknown): Bag {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Bag) : {}
}

/**
 * Changed attributes of an entry as old -> new rows. Newer activity-log versions keep them in
 * `attribute_changes`, older ones in `properties`; both use `{ attributes, old }`.
 */
export function changeRows(entry: {
  attribute_changes: unknown
  properties: unknown
}): ChangeRow[] {
  const source = (bag: Bag) => ({ next: asBag(bag.attributes), old: asBag(bag.old) })
  const primary = source(asBag(entry.attribute_changes))
  const { next, old } =
    Object.keys(primary.next).length + Object.keys(primary.old).length > 0
      ? primary
      : source(asBag(entry.properties))

  const keys = [...new Set([...Object.keys(next), ...Object.keys(old)])]
  return keys.map((attribute) => ({
    attribute,
    old: old[attribute],
    next: next[attribute],
    hasOld: attribute in old,
    hasNext: attribute in next,
  }))
}

/** Other logged details (everything in `properties` except the attribute diff). */
export function extraDetails(properties: unknown): [string, unknown][] {
  return Object.entries(asBag(properties)).filter(([key]) => key !== 'attributes' && key !== 'old')
}
