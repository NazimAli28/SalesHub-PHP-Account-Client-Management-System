/**
 * In-memory database of the browser demo. Loaded from the exported data set (dates moved to the
 * visitor's today) and kept in sessionStorage, so a page refresh keeps the changes made in this tab.
 * "Reset demo data" (the demo banner) clears the copy and reloads the page.
 */
import { addDays, daysBetween, localToday, shiftDates } from './dates'
import type { DemoData, Overview, Row, Tables, UserRow } from './types'
import { STATIC_DEMO_SESSION_KEY, STATIC_DEMO_STORE_KEY } from '@/lib/static-demo'

interface Persisted {
  exportedAt: string
  today: string
  tables: Tables
}

interface Fixed {
  exportedAt: string
  enums: Record<string, Record<string, string>>
  rolePermissions: Record<string, string[]>
  demoUsernames: string[]
  snapshots: Record<string, Overview>
  snapshotIndex: Record<string, string>
}

let tables: Tables
let fixed: Fixed

const TABLE_NAMES: (keyof Tables)[] = [
  'users',
  'teams',
  'workstations',
  'services',
  'clients',
  'client_notes',
  'leads',
  'orders',
  'order_items',
  'payments',
  'platform_accounts',
  'social_accounts',
  'approvals',
  'notifications',
  'activities',
]

function readStorage<T>(key: string): T | null {
  try {
    const raw = window.sessionStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : null
  } catch {
    return null
  }
}

function writeStorage(key: string, value: unknown): void {
  try {
    if (value === null) window.sessionStorage.removeItem(key)
    else window.sessionStorage.setItem(key, JSON.stringify(value))
  } catch {
    // Storage full or blocked: the demo keeps working, only a refresh loses the changes.
  }
}

export function initStore(data: DemoData): void {
  const today = localToday()
  const shift = daysBetween(data.export_date, today)

  fixed = {
    exportedAt: data.exported_at,
    enums: data.enums,
    rolePermissions: data.role_permissions,
    demoUsernames: data.demo_usernames,
    snapshots: shiftDates(data.analytics.snapshots, shift),
    snapshotIndex: data.analytics.index,
  }

  const saved = readStorage<Persisted>(STATIC_DEMO_STORE_KEY)
  if (saved && saved.exportedAt === data.exported_at && saved.tables) {
    tables = shiftDates(saved.tables, daysBetween(saved.today, today))
    return
  }

  const fresh = {} as Record<keyof Tables, unknown>
  for (const name of TABLE_NAMES) fresh[name] = data[name]
  tables = shiftDates(fresh as unknown as Tables, shift)
}

export function db(): Tables {
  return tables
}

export function config(): Fixed {
  return fixed
}

let persistTimer: ReturnType<typeof setTimeout> | undefined

/** Saves the tables (debounced) after a write. */
export function persist(): void {
  clearTimeout(persistTimer)
  persistTimer = setTimeout(() => {
    writeStorage(STATIC_DEMO_STORE_KEY, {
      exportedAt: fixed.exportedAt,
      today: localToday(),
      tables,
    } satisfies Persisted)
  }, 50)
}

// ---------------------------------------------------------------------------
// Session (the signed-in user)
// ---------------------------------------------------------------------------

export function sessionUserId(): number | null {
  const session = readStorage<{ userId: number }>(STATIC_DEMO_SESSION_KEY)
  return session?.userId ?? null
}

export function setSessionUser(userId: number | null): void {
  writeStorage(STATIC_DEMO_SESSION_KEY, userId === null ? null : { userId })
}

export function currentUser(): UserRow | null {
  const id = sessionUserId()
  if (id === null) return null
  const user = tables.users.find((u) => u.id === id)
  return user && user.is_active ? user : null
}

// ---------------------------------------------------------------------------
// Lookups
// ---------------------------------------------------------------------------

export function find<T extends Row>(rows: T[], id: number | null | undefined): T | undefined {
  if (id === null || id === undefined) return undefined
  return rows.find((row) => row.id === id)
}

export function nextId(rows: Row[]): number {
  return rows.reduce((max, row) => Math.max(max, row.id), 0) + 1
}

export function remove<T extends Row>(rows: T[], id: number): void {
  const index = rows.findIndex((row) => row.id === id)
  if (index >= 0) rows.splice(index, 1)
}

export function today(): string {
  return localToday()
}

export function yesterday(): string {
  return addDays(localToday(), -1)
}
