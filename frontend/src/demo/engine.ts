/**
 * Shared write logic of the browser demo: applying changes to stored rows, the activity log, the
 * notifications bell and the maker-checker approval engine (backend/app/Approvals and
 * app/Actions/Approvals).
 */
import { normalizeDateTime, isoNow } from './dates'
import { conflict, envelope, forbidden, validationError, type Json } from './http'
import { canOn, canReview, reviewersFor, type ApprovableType } from './permissions'
import { enumOf, money, pendingApprovalFor, presentApproval } from './present'
import { db, nextId } from './store'
import type { ActivityRow, ApprovalRow, EnumObj, MoneyObj, Row, UserRow } from './types'

// ---------------------------------------------------------------------------
// Field specs: request keys <-> Resource keys
// ---------------------------------------------------------------------------

export interface FieldSpec {
  /** Request key => enum name, e.g. `stage` => `LeadStage`. */
  enums?: Record<string, string>
  /** Request key => Resource money key, e.g. `estimated_value_cents` => `estimated_value`. */
  money?: Record<string, string>
  /** Timestamps (stored as Zulu ISO strings). */
  datetimes?: string[]
  /** Secret columns: never stored, only their `has_*` flag. */
  secrets?: Record<string, string>
}

function currencyOf(row: Row): string {
  return typeof row.currency === 'string' ? row.currency : 'USD'
}

/** The raw (database) value of a field, as approval snapshots and the activity log store it. */
export function rawValue(row: Row, field: string, spec: FieldSpec): unknown {
  const moneyKey = spec.money?.[field]
  if (moneyKey) return (row[moneyKey] as MoneyObj | null)?.amount_cents ?? null
  if (spec.enums?.[field]) return (row[field] as EnumObj | null)?.value ?? null
  if (spec.secrets?.[field]) return row[spec.secrets[field]] ? '[hidden]' : null
  return row[field] ?? null
}

/** Writes request values into a stored row (enums and money in the Resource shape). */
export function applyFields(row: Row, changes: Json, spec: FieldSpec): void {
  if (typeof changes.currency === 'string') row.currency = changes.currency
  for (const [field, value] of Object.entries(changes)) {
    const moneyKey = spec.money?.[field]
    const enumName = spec.enums?.[field]
    const secretFlag = spec.secrets?.[field]
    if (moneyKey) row[moneyKey] = money(value === null ? null : Number(value), currencyOf(row))
    else if (enumName) row[field] = enumOf(enumName, value as string | null)
    else if (secretFlag) row[secretFlag] = value !== null && value !== ''
    else if (spec.datetimes?.includes(field))
      row[field] = typeof value === 'string' ? normalizeDateTime(value) : null
    else row[field] = value
  }
  // Money keys follow a currency change.
  if (typeof changes.currency === 'string' && spec.money) {
    for (const key of Object.values(spec.money)) {
      const current = row[key] as MoneyObj | null
      if (current) row[key] = money(current.amount_cents, changes.currency)
    }
  }
  row.updated_at = isoNow()
}

/** Only the fields whose value differs from the stored one (Eloquent's dirty check). */
export function realChanges(row: Row, changes: Json, spec: FieldSpec): Json {
  const out: Json = {}
  for (const [field, value] of Object.entries(changes)) {
    const current = rawValue(row, field, spec)
    const same =
      current === value ||
      (current !== null && value !== null && String(current) === String(value)) ||
      (current === null && (value === undefined || value === null))
    if (!same || spec.secrets?.[field]) out[field] = value
  }
  return out
}

// ---------------------------------------------------------------------------
// Activity log and notifications
// ---------------------------------------------------------------------------

const LABEL_FIELDS = [
  'name',
  'username',
  'code',
  'order_number',
  'discord_username',
  'slug',
  'title',
]

export function subjectLabel(row: Row): string | null {
  for (const field of LABEL_FIELDS) {
    const value = row[field]
    if (typeof value === 'string' && value !== '') return value
  }
  return null
}

export function logActivity(entry: {
  logName: string
  event: string
  description?: string
  causer: UserRow | null
  subject?: { type: string; id: number; label?: string | null } | null
  properties?: Json
  attributes?: Json
  old?: Json
}): void {
  const changes: Json = {}
  if (entry.attributes && Object.keys(entry.attributes).length > 0)
    changes.attributes = entry.attributes
  if (entry.old && Object.keys(entry.old).length > 0) changes.old = entry.old
  const activity: ActivityRow = {
    id: nextId(db().activities),
    log_name: entry.logName,
    event: entry.event,
    description: entry.description ?? entry.event,
    causer_id: entry.causer?.id ?? null,
    subject: entry.subject
      ? { type: entry.subject.type, id: entry.subject.id, label: entry.subject.label ?? null }
      : null,
    properties: entry.properties ?? [],
    attribute_changes: Object.keys(changes).length > 0 ? changes : [],
    created_at: isoNow(),
  }
  db().activities.push(activity)
}

/** `LogsModelActivity`: created / updated / deleted entries with the changed attributes. */
export function logModel(
  event: 'created' | 'updated' | 'deleted',
  type: string,
  row: Row,
  causer: UserRow | null,
  attributes: Json = {},
  old: Json = {},
  approvalId?: number,
): void {
  if (event === 'updated' && Object.keys(attributes).length === 0) return
  logActivity({
    logName: 'model',
    event,
    causer,
    subject: { type, id: row.id, label: subjectLabel(row) },
    attributes,
    old,
    properties: approvalId ? { approval_request_id: approvalId } : undefined,
  })
}

export function notify(userId: number, type: string, data: Json): void {
  db().notifications.push({
    id: crypto.randomUUID(),
    type,
    data,
    is_read: false,
    read_at: null,
    created_at: isoNow(),
    user_id: userId,
  })
}

// ---------------------------------------------------------------------------
// Approvals
// ---------------------------------------------------------------------------

/** How the approval engine applies an approved change to one record type. */
export interface Applier {
  spec: FieldSpec
  find: (id: number) => Row | undefined
  update: (
    row: Row,
    changes: Json,
    relations: Record<string, number[]>,
    actor: UserRow,
    approvalId: number,
  ) => void
  remove: (row: Row, actor: UserRow, approvalId: number) => void
  relations?: (row: Row) => Record<string, number[]>
}

const appliers = new Map<string, Applier>()

export function registerApplier(type: ApprovableType, applier: Applier): void {
  appliers.set(type, applier)
}

export const ALREADY_PENDING = 'This record already has a pending change.'

function snapshot(row: Row, applier: Applier, keys: string[], relations: string[]): Json {
  const out: Json = {}
  for (const key of keys) out[key] = rawValue(row, key, applier.spec)
  if (relations.length > 0) {
    const current = applier.relations?.(row) ?? {}
    out.relations = Object.fromEntries(relations.map((name) => [name, current[name] ?? []]))
  }
  out.updated_at = row.updated_at ?? null
  return out
}

function createApproval(requester: UserRow, attributes: Partial<ApprovalRow>): ApprovalRow {
  const now = isoNow()
  const approval: ApprovalRow = {
    id: nextId(db().approvals),
    action: enumOf('ApprovalAction', 'update')!,
    status: enumOf('ApprovalStatus', 'pending')!,
    approvable: null,
    fields: [],
    payload: {},
    before: null,
    after: null,
    reason: null,
    reviewed_at: null,
    review_comment: null,
    applied_at: null,
    failure_message: null,
    created_at: now,
    updated_at: now,
    requested_by_id: requester.id,
    reviewed_by_id: null,
    ...attributes,
  }
  db().approvals.push(approval)
  logActivity({
    logName: 'approval',
    event: 'submitted',
    causer: requester,
    subject: { type: 'approval_request', id: approval.id },
    properties: {
      action: approval.action.value,
      approvable_type: approval.approvable?.type ?? null,
      approvable_id: approval.approvable?.id ?? null,
    },
  })
  for (const reviewer of reviewersFor(approval)) {
    notify(reviewer.id, 'ApprovalSubmitted', {
      approval_request_id: approval.id,
      action: approval.action.value,
      status: 'pending',
      approvable_type: approval.approvable?.type ?? null,
      approvable_id: approval.approvable?.id ?? null,
      message: `${requester.name} submitted a change request for review.`,
    })
  }
  return approval
}

function ensureNotPending(type: string, id: number): void {
  if (pendingApprovalFor(type, id)) throw validationError({ approval: [ALREADY_PENDING] })
}

export function submitUpdate(
  requester: UserRow,
  type: ApprovableType,
  row: Row,
  changes: Json,
  relations: Record<string, number[]> = {},
  reason: string | null = null,
): ApprovalRow {
  const applier = appliers.get(type)!
  const real = realChanges(row, changes, applier.spec)
  const current = applier.relations?.(row) ?? {}
  const realRelations: Record<string, number[]> = {}
  for (const [name, ids] of Object.entries(relations)) {
    const next = [...new Set(ids.map(Number))].sort((a, b) => a - b)
    if (JSON.stringify(next) !== JSON.stringify(current[name] ?? [])) realRelations[name] = next
  }
  if (Object.keys(real).length === 0 && Object.keys(realRelations).length === 0) {
    throw validationError({ changes: ['There are no changes to submit.'] })
  }
  ensureNotPending(type, row.id)
  const payload: Json = { changes: real }
  if (Object.keys(realRelations).length > 0) payload.relations = realRelations
  return createApproval(requester, {
    action: enumOf('ApprovalAction', 'update')!,
    approvable: { type, id: row.id },
    fields: [...Object.keys(real), ...Object.keys(realRelations)],
    payload,
    before: snapshot(row, applier, Object.keys(real), Object.keys(realRelations)),
    reason,
  })
}

export function submitDelete(
  requester: UserRow,
  type: ApprovableType,
  row: Row,
  reason: string | null = null,
): ApprovalRow {
  const applier = appliers.get(type)!
  ensureNotPending(type, row.id)
  const keys = Object.keys(row).filter((key) => {
    const value = row[key]
    return (
      key !== 'id' &&
      key !== 'updated_at' &&
      (value === null || typeof value !== 'object' || 'value' in value || 'amount_cents' in value)
    )
  })
  return createApproval(requester, {
    action: enumOf('ApprovalAction', 'delete')!,
    approvable: { type, id: row.id },
    fields: [],
    payload: {},
    before: { id: row.id, ...snapshot(row, applier, keys, []) },
    reason,
  })
}

export function submitAccountRequest(
  requester: UserRow,
  workstationId: number,
  quantity: number,
  note: string | null,
  reason: string | null,
): ApprovalRow {
  return createApproval(requester, {
    action: enumOf('ApprovalAction', 'request_accounts')!,
    approvable: null,
    fields: [],
    payload: { workstation_id: workstationId, quantity, note },
    reason,
  })
}

/**
 * The write rule of every approvable module: apply when the user may change the record, queue an
 * approval request (202) when they may request a change, else 403.
 */
export function updateOrRequest(
  user: UserRow,
  type: ApprovableType,
  row: Row,
  ability: 'update' | 'delete',
  apply: () => Response,
  queue: () => ApprovalRow,
): Response {
  if (canOn(user, ability, type, row)) return apply()
  if (canOn(user, 'request-change', type, row)) return queued(queue())
  throw forbidden()
}

/** 202 Accepted with the queued ApprovalRequest. */
export function queued(approval: ApprovalRow): Response {
  return envelope(presentApproval(approval), 202)
}

export function mayChangeOrRequest(
  user: UserRow,
  type: ApprovableType,
  row: Row,
  ability: 'update' | 'delete',
): boolean {
  return canOn(user, ability, type, row) || canOn(user, 'request-change', type, row)
}

function decided(approval: ApprovalRow, reviewer: UserRow): void {
  logActivity({
    logName: 'approval',
    event: approval.status.value,
    causer: reviewer,
    subject: { type: 'approval_request', id: approval.id },
    properties: {
      action: approval.action.value,
      approvable_type: approval.approvable?.type ?? null,
      approvable_id: approval.approvable?.id ?? null,
    },
  })
  const status = approval.status.value
  notify(approval.requested_by_id, 'ApprovalDecided', {
    approval_request_id: approval.id,
    action: approval.action.value,
    status,
    approvable_type: approval.approvable?.type ?? null,
    approvable_id: approval.approvable?.id ?? null,
    reviewed_by: reviewer.name,
    review_comment: approval.review_comment,
    message:
      status === 'approved'
        ? 'Your change request was approved.'
        : status === 'rejected'
          ? 'Your change request was rejected.'
          : `Your change request could not be applied: ${approval.failure_message ?? ''}`,
  })
}

const STALE = 'Record changed since request was submitted'
const MISSING = 'The record no longer exists.'

export function approve(
  approval: ApprovalRow,
  reviewer: UserRow,
  comment: string | null,
): ApprovalRow {
  if (approval.status.value !== 'pending') {
    throw conflict('This request has already been decided.', 'approval_already_decided')
  }
  if (!canReview(reviewer, approval)) throw forbidden()

  const now = isoNow()
  approval.reviewed_by_id = reviewer.id
  approval.reviewed_at = now
  approval.review_comment = comment
  approval.updated_at = now

  const action = approval.action.value
  const type = approval.approvable?.type ?? ''
  const applier = appliers.get(type)
  const record =
    applier && approval.approvable?.id ? applier.find(approval.approvable.id) : undefined
  const before = (approval.before ?? {}) as Json

  if (action === 'update' || action === 'delete') {
    if (!applier || !record || (before.updated_at ?? null) !== (record.updated_at ?? null)) {
      approval.status = enumOf('ApprovalStatus', 'failed')!
      approval.failure_message = record ? STALE : MISSING
      decided(approval, reviewer)
      throw conflict(approval.failure_message, 'approval_failed')
    }
    // Like the API, the activity entries name the reviewer (the signed-in user) as the causer.
    const payload = (approval.payload ?? {}) as {
      changes?: Json
      relations?: Record<string, number[]>
    }
    if (action === 'update') {
      applier.update(record, payload.changes ?? {}, payload.relations ?? {}, reviewer, approval.id)
      approval.after = snapshot(
        record,
        applier,
        Object.keys(payload.changes ?? {}),
        Object.keys(payload.relations ?? {}),
      )
    } else {
      applier.remove(record, reviewer, approval.id)
    }
  }

  approval.status = enumOf('ApprovalStatus', 'approved')!
  approval.applied_at = now
  decided(approval, reviewer)
  return approval
}

export function reject(approval: ApprovalRow, reviewer: UserRow, comment: string): ApprovalRow {
  if (approval.status.value !== 'pending') {
    throw conflict('This request has already been decided.', 'approval_already_decided')
  }
  if (approval.requested_by_id === reviewer.id) throw forbidden()
  const now = isoNow()
  Object.assign(approval, {
    status: enumOf('ApprovalStatus', 'rejected'),
    reviewed_by_id: reviewer.id,
    reviewed_at: now,
    review_comment: comment,
    updated_at: now,
  })
  decided(approval, reviewer)
  return approval
}

export function cancel(approval: ApprovalRow, requester: UserRow): ApprovalRow {
  approval.status = enumOf('ApprovalStatus', 'cancelled')!
  approval.updated_at = isoNow()
  logActivity({
    logName: 'approval',
    event: 'cancelled',
    causer: requester,
    subject: { type: 'approval_request', id: approval.id },
    properties: {
      action: approval.action.value,
      approvable_type: approval.approvable?.type ?? null,
      approvable_id: approval.approvable?.id ?? null,
    },
  })
  return approval
}
