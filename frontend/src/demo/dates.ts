/**
 * Date helpers for the browser demo. The data set was exported on `export_date`; every date in it is
 * moved forward by whole days on load, so "today" in the demo is the visitor's today.
 */

const DAY_MS = 86_400_000
const DATE = /^\d{4}-\d{2}-\d{2}$/
const DATE_TIME = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/

/** The visitor's local calendar date, `YYYY-MM-DD`. */
export function localToday(now = new Date()): string {
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const d = String(now.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/** Whole days from `from` to `to` (both `YYYY-MM-DD`). */
export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / DAY_MS)
}

export function addDays(date: string, days: number): string {
  return new Date(Date.parse(`${date}T00:00:00Z`) + days * DAY_MS).toISOString().slice(0, 10)
}

/** ISO 8601 UTC with a `Z` suffix and no fraction, like the API (`toIso8601ZuluString`). */
export function isoNow(): string {
  return toZulu(new Date())
}

export function toZulu(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

/** Accepts `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS` or ISO; returns the API's Zulu timestamp. */
export function normalizeDateTime(value: string): string {
  const iso = DATE.test(value)
    ? `${value}T00:00:00Z`
    : value.includes('T')
      ? value
      : value.replace(' ', 'T') + 'Z'
  const parsed = Date.parse(iso)
  return Number.isNaN(parsed) ? value : toZulu(new Date(parsed))
}

function shiftString(value: string, days: number): string {
  if (DATE.test(value)) return addDays(value, days)
  if (DATE_TIME.test(value)) {
    const iso = value.includes('T') ? value : value.replace(' ', 'T') + 'Z'
    const parsed = Date.parse(iso)
    if (Number.isNaN(parsed)) return value
    // Calendar days in the visitor's time zone can run ahead of the export's UTC clock: a moved
    // timestamp that would land in the future goes back one day, so nothing happens "in 5 hours".
    let moved = parsed + days * DAY_MS
    if (days > 0 && moved > Date.now()) moved -= DAY_MS
    const shifted = new Date(moved).toISOString()
    // Keep the original style: "Y-m-d H:i:s", Zulu without fraction, or with microseconds.
    if (!value.includes('T')) return shifted.slice(0, 19).replace('T', ' ')
    if (/\.\d{6}Z$/.test(value)) return shifted.replace(/\.\d{3}Z$/, '.000000Z')
    return shifted.replace(/\.\d{3}Z$/, 'Z')
  }
  return value
}

/** Returns a deep copy of `value` with every date and timestamp string moved by `days`. */
export function shiftDates<T>(value: T, days: number): T {
  if (days === 0) return value
  const walk = (node: unknown): unknown => {
    if (typeof node === 'string') return shiftString(node, days)
    if (Array.isArray(node)) return node.map(walk)
    if (node && typeof node === 'object') {
      const out: Record<string, unknown> = {}
      for (const [key, child] of Object.entries(node)) out[key] = walk(child)
      return out
    }
    return node
  }
  return walk(value) as T
}
