/** Helpers shared by the module handlers. */
import { forbidden, idParam, notFound, type Context, type Json } from '../http'
import { platformAccountVisible, userVisible } from '../permissions'
import { db, find } from '../store'
import type { Row, UserRow } from '../types'
import { Validator } from '../validate'

/** The record named by a route parameter, or 404. */
export function record<T extends Row>(rows: T[], ctx: Context, param: string): T {
  const row = find(rows, idParam(ctx.params[param]))
  if (!row) throw notFound()
  return row
}

export function authorize(allowed: boolean): void {
  if (!allowed) throw forbidden()
}

/** The optional `reason` (max 500) stored on an approval request. */
export function reasonOf(v: Validator): string | null {
  v.string('reason', 500)
  const reason = v.value('reason')
  return typeof reason === 'string' && reason !== '' ? reason : null
}

/** `VisibleTo(User::class)` with the active-user constraint most forms use. */
export function activeVisibleUser(actor: UserRow) {
  return (id: number) => {
    const target = find(db().users, id)
    return target !== undefined && target.is_active && userVisible(actor, target)
  }
}

export function visiblePlatformAccount(actor: UserRow) {
  return (id: number) => {
    const account = find(db().platform_accounts, id)
    return account !== undefined && platformAccountVisible(actor, account)
  }
}

export function activeService(id: number): boolean {
  return find(db().services, id)?.is_active === true
}

export function activeWorkstation(id: number): boolean {
  return find(db().workstations, id)?.is_active === true
}

export function str(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

export function int(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

export function boolOf(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true'
}

export function pick(data: Json, fields: string[]): Json {
  const out: Json = {}
  for (const field of fields) {
    if (Object.prototype.hasOwnProperty.call(data, field)) out[field] = data[field]
  }
  return out
}
