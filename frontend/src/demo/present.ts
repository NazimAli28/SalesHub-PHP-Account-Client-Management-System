/**
 * Turns stored rows back into the API's Resource shapes (relations, counts, money totals,
 * pending_change), mirroring backend/app/Http/Resources.
 */
import {
  can,
  canReview,
  clientVisible,
  leadVisible,
  orderVisible,
  paymentVisible,
} from './permissions'
import { config, db, find, today } from './store'
import type {
  ActivityRow,
  ApprovalRow,
  ClientRow,
  EnumObj,
  ItemRow,
  LeadRow,
  MoneyObj,
  NoteRow,
  OrderRow,
  PaymentRow,
  PlatformAccountRow,
  ServiceRow,
  SocialAccountRow,
  TeamRow,
  UserRow,
  WorkstationRow,
} from './types'

type Json = Record<string, unknown>

const formatters = new Map<string, Intl.NumberFormat>()

export function money(cents: number | null | undefined, currency = 'USD'): MoneyObj | null {
  if (cents === null || cents === undefined) return null
  let formatter = formatters.get(currency)
  if (!formatter) {
    formatter = new Intl.NumberFormat('en-US', { style: 'currency', currency })
    formatters.set(currency, formatter)
  }
  return { amount_cents: cents, currency, formatted: formatter.format(cents / 100) }
}

export function enumOf(name: string, value: string | null | undefined): EnumObj | null {
  if (value === null || value === undefined || value === '') return null
  const label = config().enums[name]?.[value] ?? value
  return { value, label }
}

export function enumValues(name: string): string[] {
  return Object.keys(config().enums[name] ?? {})
}

// ---------------------------------------------------------------------------
// Summaries
// ---------------------------------------------------------------------------

export function userSummary(id: number | null | undefined): Json | null {
  const user = find(db().users, id)
  return user ? { id: user.id, name: user.name, username: user.username } : null
}

export function clientSummary(id: number | null | undefined): Json | null {
  const client = find(db().clients, id)
  return client
    ? {
        id: client.id,
        discord_username: client.discord_username,
        name: client.name,
        status: client.status,
      }
    : null
}

export function platformAccountSummary(id: number | null | undefined): Json | null {
  const account = find(db().platform_accounts, id)
  return account
    ? {
        id: account.id,
        email: account.email,
        discord_username: account.discord_username,
        standing: account.standing,
      }
    : null
}

export function serviceSummary(service: ServiceRow): Json {
  return {
    id: service.id,
    name: service.name,
    slug: service.slug,
    category: service.category,
    base_price: service.base_price,
  }
}

export function teamSummary(id: number | null | undefined): Json | null {
  const team = find(db().teams, id)
  return team ? { id: team.id, name: team.name, floor: team.floor, shift: team.shift } : null
}

export function workstationSummary(id: number | null | undefined): Json | null {
  const station = find(db().workstations, id)
  if (!station) return null
  const team = find(db().teams, station.team_id)
  return {
    id: station.id,
    code: station.code,
    label: station.label,
    team_id: station.team_id,
    team: team ? { id: team.id, name: team.name } : null,
  }
}

// ---------------------------------------------------------------------------
// Pending change (FormatsApiValues::pendingChange)
// ---------------------------------------------------------------------------

export function pendingApprovalFor(type: string, id: number): ApprovalRow | undefined {
  return db().approvals.find(
    (a) => a.status.value === 'pending' && a.approvable?.type === type && a.approvable.id === id,
  )
}

export function pendingChange(type: string, id: number): Json | null {
  const pending = pendingApprovalFor(type, id)
  if (!pending) return null
  return {
    id: pending.id,
    action: pending.action,
    fields: pending.fields,
    requested_by: userSummary(pending.requested_by_id),
    requested_at: pending.created_at,
  }
}

// ---------------------------------------------------------------------------
// Payments and orders: derived amounts
// ---------------------------------------------------------------------------

export function isOverdue(payment: PaymentRow): boolean {
  return payment.status.value === 'scheduled' && payment.due_date < today()
}

export function orderPayments(orderId: number): PaymentRow[] {
  return db().payments.filter((p) => p.order_id === orderId)
}

export function paidCents(orderId: number): number {
  return orderPayments(orderId)
    .filter((p) => p.status.value === 'paid')
    .reduce((sum, p) => sum + p.amount.amount_cents, 0)
}

function orderTotals(order: OrderRow): Json {
  const paid = paidCents(order.id)
  return {
    amount_paid: money(paid, order.currency),
    balance: money(Math.max(0, order.total.amount_cents - paid), order.currency),
    overdue_payments_count: orderPayments(order.id).filter(isOverdue).length,
  }
}

export function orderSummary(order: OrderRow, withClient = false): Json {
  return {
    id: order.id,
    order_number: order.order_number,
    type: order.type,
    status: order.status,
    client_id: order.client_id,
    ...(withClient ? { client: clientSummary(order.client_id) } : {}),
    ordered_on: order.ordered_on,
    total: order.total,
    ...orderTotals(order),
  }
}

export function paymentSummary(payment: PaymentRow): Json {
  return {
    id: payment.id,
    order_id: payment.order_id,
    order_number: find(db().orders, payment.order_id)?.order_number ?? null,
    sequence: payment.sequence,
    amount: payment.amount,
    due_date: payment.due_date,
    status: payment.status,
    is_overdue: isOverdue(payment),
  }
}

// ---------------------------------------------------------------------------
// Full resources
// ---------------------------------------------------------------------------

export function presentUser(user: UserRow): Json {
  const team = find(db().teams, user.team_id)
  const station = find(db().workstations, user.workstation_id)
  return {
    ...user,
    team: team
      ? { id: team.id, name: team.name, floor: team.floor, shift: team.shift.value }
      : null,
    workstation: station ? { id: station.id, code: station.code } : null,
  }
}

export function presentTeam(team: TeamRow): Json {
  const members = db().users.filter((u) => u.team_id === team.id)
  const stations = db().workstations.filter((w) => w.team_id === team.id)
  return {
    ...team,
    team_lead: userSummary(team.team_lead_id),
    members_count: members.length,
    workstations_count: stations.length,
    members: members.map((u) => userSummary(u.id)),
    workstations: stations.map(presentWorkstation),
  }
}

export function presentWorkstation(station: WorkstationRow): Json {
  const users = db().users.filter((u) => u.workstation_id === station.id)
  return {
    ...station,
    team: teamSummary(station.team_id),
    users_count: users.length,
    platform_accounts_count: db().platform_accounts.filter((a) => a.workstation_id === station.id)
      .length,
    users: users.map((u) => userSummary(u.id)),
  }
}

export function presentService(service: ServiceRow): Json {
  return { ...service }
}

export function presentLead(lead: LeadRow): Json {
  const { service_ids: serviceIds, currency, ...rest } = lead
  void currency
  return {
    ...rest,
    client: clientSummary(lead.client_id),
    owner: userSummary(lead.owner_id),
    closer: userSummary(lead.closer_id),
    platform_account: platformAccountSummary(lead.platform_account_id),
    services: db()
      .services.filter((s) => serviceIds.includes(s.id))
      .map(serviceSummary),
    pending_change: pendingChange('lead', lead.id),
  }
}

export function lifetimeValueCents(clientId: number): number {
  const orderIds = new Set(
    db()
      .orders.filter((o) => o.client_id === clientId)
      .map((o) => o.id),
  )
  return db()
    .payments.filter((p) => orderIds.has(p.order_id) && p.status.value === 'paid')
    .reduce((sum, p) => sum + p.amount.amount_cents, 0)
}

export function presentClient(client: ClientRow): Json {
  return {
    ...client,
    lifetime_value: money(lifetimeValueCents(client.id)),
    owner: userSummary(client.owner_id),
    pending_change: pendingChange('client', client.id),
  }
}

const OPEN_ORDER = ['pending_payment', 'in_progress', 'delivered']

export function presentClientDetail(client: ClientRow, user: UserRow): Json {
  const leads = db()
    .leads.filter((l) => l.client_id === client.id && leadVisible(user, l))
    .sort((a, b) => b.contacted_on.localeCompare(a.contacted_on) || b.id - a.id)
  const orders = db()
    .orders.filter((o) => o.client_id === client.id && orderVisible(user, o))
    .sort((a, b) => b.ordered_on.localeCompare(a.ordered_on) || b.id - a.id)
  const orderIds = new Set(
    db()
      .orders.filter((o) => o.client_id === client.id)
      .map((o) => o.id),
  )
  const payments = db().payments.filter((p) => orderIds.has(p.order_id) && paymentVisible(user, p))
  const byDue = (a: PaymentRow, b: PaymentRow) => a.due_date.localeCompare(b.due_date)
  const upcoming = payments
    .filter((p) => p.status.value === 'scheduled' && p.due_date >= today())
    .sort(byDue)
    .slice(0, 20)
  const overdue = payments.filter(isOverdue).sort(byDue).slice(0, 50)

  return {
    ...presentClient(client),
    leads: leads.map(presentLead),
    orders: orders.map(presentOrder),
    counts: {
      leads: leads.length,
      orders: orders.length,
      open_orders: orders.filter((o) => OPEN_ORDER.includes(o.status.value)).length,
      overdue_payments: payments.filter(isOverdue).length,
    },
    upcoming_payments: upcoming.map(paymentSummary),
    overdue_payments: overdue.map(paymentSummary),
  }
}

export function presentItem(item: ItemRow): Json {
  const service = find(db().services, item.service_id)
  return { ...item, service: service ? serviceSummary(service) : null }
}

export function presentPayment(payment: PaymentRow): Json {
  const order = find(db().orders, payment.order_id)
  return {
    ...payment,
    is_overdue: isOverdue(payment),
    order: order ? orderSummary(order, true) : null,
    recorded_by: userSummary(payment.recorded_by_id),
    pending_change: pendingChange('payment', payment.id),
  }
}

export function presentOrder(order: OrderRow): Json {
  const parent = find(db().orders, order.parent_order_id)
  return {
    ...order,
    ...orderTotals(order),
    client: clientSummary(order.client_id),
    owner: userSummary(order.owner_id),
    closer: userSummary(order.closer_id),
    platform_account: platformAccountSummary(order.platform_account_id),
    parent: parent ? orderSummary(parent) : null,
    items: db()
      .order_items.filter((i) => i.order_id === order.id)
      .map(presentItem),
    payments: orderPayments(order.id)
      .sort((a, b) => a.sequence - b.sequence)
      .map((p) => ({
        ...p,
        is_overdue: isOverdue(p),
        pending_change: pendingChange('payment', p.id),
      })),
    pending_change: pendingChange('order', order.id),
  }
}

export function presentPlatformAccount(account: PlatformAccountRow, withSocial = false): Json {
  const social = db().social_accounts.filter((s) => s.platform_account_id === account.id)
  return {
    ...account,
    workstation: workstationSummary(account.workstation_id),
    social_accounts_count: social.length,
    ...(withSocial ? { social_accounts: social.map((s) => presentSocialAccount(s, false)) } : {}),
    pending_change: pendingChange('platform_account', account.id),
  }
}

export function presentSocialAccount(account: SocialAccountRow, withParent = true): Json {
  return {
    ...account,
    ...(withParent
      ? { platform_account: platformAccountSummary(account.platform_account_id) }
      : {}),
    pending_change: pendingChange('social_account', account.id),
  }
}

export function presentNote(note: NoteRow, user: UserRow): Json {
  const { author_id: authorId, ...rest } = note
  const client = find(db().clients, note.client_id)
  const canSeeClient = client !== undefined && clientVisible(user, client)
  return {
    ...rest,
    author: userSummary(authorId),
    can_edit: canSeeClient && authorId === user.id,
    can_delete: canSeeClient && (authorId === user.id || can(user, 'clients.update')),
  }
}

// ---------------------------------------------------------------------------
// Approvals (ApprovalRequestResource + ApprovalDiff)
// ---------------------------------------------------------------------------

function diffRows(
  fields: Record<string, unknown>,
  before: (field: string) => unknown,
  after: (field: string, proposed: unknown) => unknown,
): Json[] {
  return Object.entries(fields).map(([field, value]) => ({
    field,
    before: before(field),
    after: after(field, value),
  }))
}

export function approvalDiff(approval: ApprovalRow): Json[] {
  const payload = (approval.payload ?? {}) as Record<string, Record<string, unknown> | undefined>
  const before = (approval.before ?? {}) as Record<string, unknown>
  const beforeRelations = (before.relations ?? {}) as Record<string, unknown>
  const applied =
    approval.status.value === 'approved'
      ? ((approval.after ?? {}) as Record<string, unknown>)
      : null
  const appliedRelations = (applied?.relations ?? {}) as Record<string, unknown>

  switch (approval.action.value) {
    case 'update':
      return diffRows(
        { ...(payload.changes ?? {}), ...(payload.relations ?? {}) },
        (field) => before[field] ?? beforeRelations[field] ?? null,
        (field, proposed) =>
          applied === null ? proposed : (applied[field] ?? appliedRelations[field] ?? proposed),
      )
    case 'delete': {
      const fields = { ...before }
      delete fields.updated_at
      delete fields.relations
      return diffRows(
        fields,
        (field) => before[field] ?? null,
        () => null,
      )
    }
    case 'create':
      return diffRows(
        { ...(payload.attributes ?? {}), ...(payload.relations ?? {}) },
        () => null,
        (_field, proposed) => proposed,
      )
    default:
      return diffRows(
        payload as Record<string, unknown>,
        () => null,
        (_field, proposed) => proposed,
      )
  }
}

export function presentApproval(
  approval: ApprovalRow,
  user: UserRow | null = null,
  withAbilities = false,
): Json {
  const { requested_by_id: requesterId, reviewed_by_id: reviewerId, ...rest } = approval
  return {
    ...rest,
    diff: approvalDiff(approval),
    requester: userSummary(requesterId),
    reviewer: userSummary(reviewerId),
    ...(withAbilities && user
      ? {
          can: {
            review: approval.status.value === 'pending' && canReview(user, approval),
            cancel: approval.requested_by_id === user.id && approval.status.value === 'pending',
          },
        }
      : {}),
  }
}

// ---------------------------------------------------------------------------
// Activity (ActivityResource)
// ---------------------------------------------------------------------------

export function presentActivity(activity: ActivityRow): Json {
  return { ...activity, causer: userSummary(activity.causer_id) }
}
