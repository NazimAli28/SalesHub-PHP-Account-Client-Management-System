import { format, formatDistanceToNowStrict, isValid, parseISO } from 'date-fns'
import type { Money } from '@/api/types'

/** Parses `YYYY-MM-DD` as a local calendar date (not UTC midnight, which can shift the day). */
export function parseDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = parseISO(value)
  return isValid(date) ? date : null
}

/** `2026-10-04` -> `Oct 4, 2026`. */
export function formatDate(value: string | null | undefined, pattern = 'MMM d, yyyy'): string {
  const date = parseDate(value)
  return date ? format(date, pattern) : ''
}

/** `2026-10-04T08:12:00Z` -> `Oct 4, 2026, 10:12 AM` in the viewer's time zone. */
export function formatDateTime(value: string | null | undefined): string {
  return formatDate(value, 'MMM d, yyyy, h:mm a')
}

/** `3 hours ago`, `in 2 days`. */
export function formatRelative(value: string | null | undefined, now?: Date): string {
  const date = parseDate(value)
  if (!date) return ''
  if (now && Math.abs(now.getTime() - date.getTime()) < 45_000) return 'just now'
  return formatDistanceToNowStrict(date, { addSuffix: true })
}

/** Date -> `YYYY-MM-DD` for API requests. */
export function toIsoDate(date: Date): string {
  return format(date, 'yyyy-MM-dd')
}

/** Formats integer cents. Prefer the API's `formatted` string when you have a Money object. */
export function formatCents(cents: number | null | undefined, currency = 'USD'): string {
  if (cents === null || cents === undefined) return ''
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100)
}

export function formatMoney(money: Money | null | undefined): string {
  if (!money || money.amount_cents === null) return ''
  return money.formatted || formatCents(money.amount_cents, money.currency)
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-US').format(value)
}

/** `Ayla Mercer` -> `AM`. */
export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  const letters = parts.length > 1 ? [parts[0]![0], parts[parts.length - 1]![0]] : [parts[0]?.[0]]
  return letters.join('').toUpperCase()
}
