/** Orders, order items and payments (routes/api/orders.php, payments.php). */
import { isoNow } from '../dates'
import {
  applyFields,
  logModel,
  mayChangeOrRequest,
  rawValue,
  realChanges,
  registerApplier,
  submitDelete,
  submitUpdate,
  updateOrRequest,
  type FieldSpec,
} from '../engine'
import {
  envelope,
  json,
  noContent,
  notFound,
  route,
  validationError,
  type Context,
  type Json,
} from '../http'
import {
  can,
  canOn,
  canView,
  canViewAny,
  clientVisible,
  leadVisible,
  orderVisible,
  paymentVisible,
} from '../permissions'
import { enumOf, isOverdue, money, presentOrder, presentPayment } from '../present'
import { dateFrom, dateTo, exact, flag, paginate, query, type ListSpec } from '../query'
import { db, find, nextId, remove, today } from '../store'
import type { ItemRow, OrderRow, PaymentRow, UserRow } from '../types'
import { Validator } from '../validate'
import {
  activeService,
  authorize,
  reasonOf,
  record,
  visiblePlatformAccount,
  activeVisibleUser,
} from './common'

export const ORDER_SPEC: FieldSpec = {
  enums: { status: 'OrderStatus', type: 'OrderType' },
  money: { discount_cents: 'discount', subtotal_cents: 'subtotal', total_cents: 'total' },
  datetimes: ['delivered_at'],
}

export const PAYMENT_SPEC: FieldSpec = {
  enums: { status: 'PaymentStatus', method: 'PaymentMethod' },
  money: { amount_cents: 'amount' },
  datetimes: ['paid_at'],
}

const CLOSED = ['cancelled', 'refunded']

// ---------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------

const ORDER_LIST: ListSpec<OrderRow> = {
  filters: {
    status: exact((o) => o.status.value),
    type: exact((o) => o.type.value),
    owner: exact((o) => o.owner_id),
    team: exact((o) => o.team_id),
    client: exact((o) => o.client_id),
    ordered_from: dateFrom((o) => o.ordered_on),
    ordered_to: dateTo((o) => o.ordered_on),
    has_overdue: flag((o) => db().payments.some((p) => p.order_id === o.id && isOverdue(p))),
  },
  search: (o) => {
    const client = find(db().clients, o.client_id)
    return [o.order_number, client?.name, client?.discord_username]
  },
  sorts: {
    ordered_on: (o) => o.ordered_on,
    total_cents: (o) => o.total.amount_cents,
    order_number: (o) => o.order_number,
    created_at: (o) => o.created_at,
    id: (o) => o.id,
  },
  defaultSort: '-ordered_on,-id',
}

function items(orderId: number): ItemRow[] {
  return db().order_items.filter((i) => i.order_id === orderId)
}

/** Payments that count against the total (not void). */
function committedCents(order: OrderRow, except?: PaymentRow): number {
  return db()
    .payments.filter(
      (p) => p.order_id === order.id && p.status.value !== 'void' && p.id !== except?.id,
    )
    .reduce((sum, p) => sum + p.amount.amount_cents, 0)
}

/** RecalculateOrderTotals. */
function recalculate(order: OrderRow): void {
  const subtotal = items(order.id).reduce((sum, i) => sum + i.line_total.amount_cents, 0)
  const discount = Math.min(order.discount.amount_cents, subtotal)
  order.subtotal = money(subtotal, order.currency)!
  order.discount = money(discount, order.currency)!
  order.total = money(Math.max(0, subtotal - discount), order.currency)!
}

function ensureTotalCoversPayments(order: OrderRow, field: string): void {
  if (CLOSED.includes(order.status.value)) return
  if (committedCents(order) > order.total.amount_cents) {
    throw validationError({
      [field]: ['The new order total would be lower than the payments already scheduled or paid.'],
    })
  }
}

function nextOrderNumber(orderedOn: string): string {
  const prefix = `SH-${orderedOn.slice(0, 4)}-`
  const last = db()
    .orders.map((o) => o.order_number)
    .filter((n) => n.startsWith(prefix))
    .sort()
    .pop()
  const sequence = last ? Number(last.slice(prefix.length)) + 1 : 1
  return `${prefix}${String(sequence).padStart(5, '0')}`
}

function makeItem(order: OrderRow, data: Json): ItemRow {
  const service = find(db().services, Number(data.service_id))!
  const quantity = data.quantity === undefined || data.quantity === null ? 1 : Number(data.quantity)
  const unit =
    data.unit_price_cents === undefined || data.unit_price_cents === null
      ? service.base_price.amount_cents
      : Number(data.unit_price_cents)
  return {
    id: nextId(db().order_items),
    order_id: order.id,
    service_id: service.id,
    description: (data.description as string | null | undefined) ?? null,
    quantity,
    unit_price: money(unit, order.currency)!,
    line_total: money(unit * quantity, order.currency)!,
  }
}

export function updateOrder(
  order: OrderRow,
  changes: Json,
  actor: UserRow,
  approvalId?: number,
): void {
  const real = realChanges(order, changes, ORDER_SPEC)
  const old: Json = {}
  for (const field of Object.keys(real)) old[field] = rawValue(order, field, ORDER_SPEC)
  applyFields(order, real, ORDER_SPEC)
  recalculate(order)
  logModel('updated', 'order', order, actor, real, old, approvalId)
}

function deleteOrder(order: OrderRow, actor: UserRow, approvalId?: number): void {
  remove(db().orders, order.id)
  logModel('deleted', 'order', order, actor, {}, { order_number: order.order_number }, approvalId)
}

registerApplier('order', {
  spec: ORDER_SPEC,
  find: (id) => find(db().orders, id),
  update: (row, changes, _r, actor, approvalId) =>
    updateOrder(row as OrderRow, changes, actor, approvalId),
  remove: (row, actor, approvalId) => deleteOrder(row as OrderRow, actor, approvalId),
})

function orderRules(v: Validator, user: UserRow): void {
  v.integer('closer_id').exists('closer_id', (id) => find(db().users, id)?.is_active === true)
  v.integer('platform_account_id').exists('platform_account_id', visiblePlatformAccount(user))
  v.enum('status', 'OrderStatus')
  v.integer('discount_cents', 0, 100_000_000)
  v.date('ordered_on').notFuture('ordered_on', today())
  v.string('notes', 5000)
}

function itemRules(v: Validator, prefix = ''): void {
  v.integer(`${prefix}service_id`).exists(`${prefix}service_id`, activeService)
  v.integer(`${prefix}quantity`, 1, 1000)
  v.integer(`${prefix}unit_price_cents`, 0, 100_000_000)
  v.string(`${prefix}description`, 255)
}

function orderOf(ctx: Context, param = 'order'): OrderRow {
  return record(db().orders, ctx, param)
}

function writableOrder(order: OrderRow): void {
  if (CLOSED.includes(order.status.value)) {
    throw validationError({ order: ['Items cannot be changed on a cancelled or refunded order.'] })
  }
}

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------

const PAYMENT_LIST: ListSpec<PaymentRow> = {
  filters: {
    status: exact((p) => p.status.value),
    order: exact((p) => p.order_id),
    overdue: flag(isOverdue),
    client: exact((p) => find(db().orders, p.order_id)?.client_id),
    owner: exact((p) => find(db().orders, p.order_id)?.owner_id),
    team: exact((p) => find(db().orders, p.order_id)?.team_id),
    due_from: dateFrom((p) => p.due_date),
    due_to: dateTo((p) => p.due_date),
    paid_from: dateFrom((p) => p.paid_at),
    paid_to: dateTo((p) => p.paid_at),
  },
  search: (p) => {
    const order = find(db().orders, p.order_id)
    const client = find(db().clients, order?.client_id)
    return [p.reference, order?.order_number, client?.name, client?.discord_username]
  },
  sorts: {
    due_date: (p) => p.due_date,
    amount_cents: (p) => p.amount.amount_cents,
    paid_at: (p) => p.paid_at,
    sequence: (p) => p.sequence,
    created_at: (p) => p.created_at,
    id: (p) => p.id,
  },
  defaultSort: 'due_date,id',
}

function ensureFits(order: OrderRow, amount: number, currency: string, except?: PaymentRow): void {
  if (currency !== order.currency) {
    throw validationError({
      currency: [`Payments must use the order currency (${order.currency}).`],
    })
  }
  const committed = committedCents(order, except)
  if (committed + amount > order.total.amount_cents) {
    const room = Math.max(0, order.total.amount_cents - committed)
    throw validationError({
      amount_cents: [
        `The scheduled payments would exceed the order total; at most ${money(room, order.currency)!.formatted} can still be scheduled.`,
      ],
    })
  }
}

export function updatePayment(
  payment: PaymentRow,
  changes: Json,
  actor: UserRow,
  approvalId?: number,
): void {
  const real = realChanges(payment, changes, PAYMENT_SPEC)
  const old: Json = {}
  for (const field of Object.keys(real)) old[field] = rawValue(payment, field, PAYMENT_SPEC)
  applyFields(payment, real, PAYMENT_SPEC)
  const logged = { ...real }
  delete logged.recorded_by_id
  logModel('updated', 'payment', payment, actor, logged, old, approvalId)
}

function deletePayment(payment: PaymentRow, actor: UserRow, approvalId?: number): void {
  remove(db().payments, payment.id)
  logModel('deleted', 'payment', payment, actor, {}, { sequence: payment.sequence }, approvalId)
}

registerApplier('payment', {
  spec: PAYMENT_SPEC,
  find: (id) => find(db().payments, id),
  update: (row, changes, _r, actor, approvalId) =>
    updatePayment(row as PaymentRow, changes, actor, approvalId),
  remove: (row, actor, approvalId) => deletePayment(row as PaymentRow, actor, approvalId),
})

function paymentRules(v: Validator): void {
  v.integer('amount_cents', 1, 100_000_000)
  v.regex('currency', /^[A-Z]{3}$/)
  v.date('due_date')
  v.enum('method', 'PaymentMethod')
  v.string('reference', 100).string('notes', 2000)
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

export const orderHandlers = [
  route('get', '/orders', ({ user, url }) => {
    authorize(canViewAny(user, 'orders'))
    const rows = db().orders.filter((o) => orderVisible(user, o))
    return json(paginate(query(rows, url, ORDER_LIST), url, presentOrder))
  }),

  route('post', '/orders', async ({ user, body }) => {
    authorize(can(user, 'orders.create'))
    const v = new Validator(await body())
    v.required('client_id')
      .integer('client_id')
      .exists('client_id', (id) => {
        const client = find(db().clients, id)
        return client !== undefined && clientVisible(user, client)
      })
    v.integer('owner_id').exists('owner_id', activeVisibleUser(user))
    v.integer('parent_order_id').exists('parent_order_id', (id) => {
      const order = find(db().orders, id)
      return order !== undefined && orderVisible(user, order)
    })
    v.integer('lead_id').exists('lead_id', (id) => {
      const lead = find(db().leads, id)
      return lead !== undefined && leadVisible(user, lead)
    })
    v.regex('currency', /^[A-Z]{3}$/)
    orderRules(v, user)
    const rawItems = v.value('items')
    if (!Array.isArray(rawItems) || rawItems.length === 0)
      v.add('items', 'The items field is required.')
    else if (rawItems.length > 50)
      v.add('items', 'The items field must not have more than 50 items.')
    else
      rawItems.forEach((item, index) => {
        const iv = new Validator((item ?? {}) as Json)
        iv.required('service_id')
        itemRules(iv)
        for (const [field, messages] of Object.entries(iv.errors)) {
          for (const message of messages) v.add(`items.${index}.${field}`, message)
        }
      })
    v.validate()

    const data = v.data
    const clientId = Number(data.client_id)
    const lead = find(db().leads, data.lead_id as number | undefined)
    const parent = find(db().orders, data.parent_order_id as number | undefined)
    if (parent && parent.client_id !== clientId) {
      throw validationError({
        parent_order_id: ['The parent order must belong to the same client.'],
      })
    }
    if (lead && lead.client_id !== clientId) {
      throw validationError({ lead_id: ['The lead must belong to the same client.'] })
    }
    if (lead && lead.order_id !== null)
      throw validationError({ lead_id: ['The lead already has an order.'] })

    const ownerId = (data.owner_id as number | null | undefined) ?? user.id
    const currency = (data.currency as string | undefined) ?? 'USD'
    const orderedOn = (data.ordered_on as string | undefined) ?? today()
    const now = isoNow()
    const order: OrderRow = {
      id: nextId(db().orders),
      order_number: nextOrderNumber(orderedOn),
      type: enumOf('OrderType', parent ? 'upsell' : 'fresh')!,
      status: enumOf('OrderStatus', (data.status as string | undefined) ?? 'pending_payment')!,
      currency,
      subtotal: money(0, currency)!,
      discount: money(Number(data.discount_cents ?? 0), currency)!,
      total: money(0, currency)!,
      ordered_on: orderedOn,
      delivered_at: null,
      notes: (data.notes as string | null | undefined) ?? null,
      client_id: clientId,
      owner_id: ownerId,
      closer_id: (data.closer_id as number | null | undefined) ?? null,
      team_id: find(db().users, ownerId)?.team_id ?? null,
      platform_account_id: (data.platform_account_id as number | null | undefined) ?? null,
      parent_order_id: parent?.id ?? null,
      created_at: now,
      updated_at: now,
    }
    const newItems = (rawItems as Json[]).map((item) => makeItem(order, item))
    const subtotal = newItems.reduce((sum, i) => sum + i.line_total.amount_cents, 0)
    if (order.discount.amount_cents > subtotal) {
      throw validationError({ discount_cents: ['The discount cannot exceed the order subtotal.'] })
    }
    db().orders.push(order)
    for (const item of newItems) {
      item.id = 0
      item.id = nextId(db().order_items)
      db().order_items.push(item)
    }
    recalculate(order)
    logModel('created', 'order', order, user, {
      order_number: order.order_number,
      status: order.status.value,
    })
    if (lead) {
      lead.stage = enumOf('LeadStage', 'won')!
      lead.stage_changed_at = now
      lead.order_id = order.id
      lead.next_follow_up_on = null
      lead.updated_at = now
      logModel('updated', 'lead', lead, user, { stage: 'won', order_id: order.id })
    }
    return envelope(presentOrder(order), 201)
  }),

  route('get', '/orders/:order', (ctx) => {
    const order = orderOf(ctx)
    authorize(canView(ctx.user, 'order', order))
    return envelope(presentOrder(order))
  }),

  route('patch', '/orders/:order', async (ctx) => {
    const { user } = ctx
    const order = orderOf(ctx)
    authorize(mayChangeOrRequest(user, 'order', order, 'update'))
    const v = new Validator(await ctx.body())
    for (const field of ['client_id', 'owner_id', 'currency', 'type', 'parent_order_id', 'team_id'])
      v.prohibited(field)
    v.prohibited('items', 'Use /api/orders/{order}/items to add, change or remove items.')
    v.prohibited('subtotal_cents', 'Totals are calculated from the items and the discount.')
    v.prohibited('total_cents', 'Totals are calculated from the items and the discount.')
    orderRules(v, user)
    const reason = reasonOf(v)
    v.validate()
    if (
      v.has('discount_cents') &&
      Number(v.value('discount_cents')) > order.subtotal.amount_cents
    ) {
      throw validationError({ discount_cents: ['The discount cannot exceed the order subtotal.'] })
    }
    const changes = v.only([
      'closer_id',
      'platform_account_id',
      'status',
      'discount_cents',
      'ordered_on',
      'delivered_at',
      'notes',
    ])
    // UpdateOrder::prepare: delivering stamps delivered_at.
    const status = (changes.status as string | undefined) ?? order.status.value
    if (status === 'delivered' && order.delivered_at === null && !('delivered_at' in changes)) {
      changes.delivered_at = isoNow()
    }
    return updateOrRequest(
      user,
      'order',
      order,
      'update',
      () => {
        const discount = v.has('discount_cents')
          ? Number(v.value('discount_cents'))
          : order.discount.amount_cents
        const total = Math.max(
          0,
          order.subtotal.amount_cents - Math.min(discount, order.subtotal.amount_cents),
        )
        if (!CLOSED.includes(status) && committedCents(order) > total) {
          throw validationError({
            discount_cents: [
              'The new order total would be lower than the payments already scheduled or paid.',
            ],
          })
        }
        updateOrder(order, changes, user)
        return envelope(presentOrder(order))
      },
      () => submitUpdate(user, 'order', order, changes, {}, reason),
    )
  }),

  route('delete', '/orders/:order', async (ctx) => {
    const { user } = ctx
    const order = orderOf(ctx)
    authorize(mayChangeOrRequest(user, 'order', order, 'delete'))
    const v = new Validator(await ctx.body())
    const reason = reasonOf(v)
    v.validate()
    return updateOrRequest(
      user,
      'order',
      order,
      'delete',
      () => {
        deleteOrder(order, user)
        return noContent()
      },
      () => submitDelete(user, 'order', order, reason),
    )
  }),

  // Items: direct writes for users who may update the order (no approvals).
  route('post', '/orders/:order/items', async (ctx) => {
    const order = orderOf(ctx)
    authorize(canOn(ctx.user, 'update', 'order', order))
    const v = new Validator(await ctx.body())
    v.required('service_id')
    itemRules(v)
    v.validate()
    writableOrder(order)
    const item = makeItem(order, v.data)
    db().order_items.push(item)
    recalculate(order)
    ensureTotalCoversPayments(order, 'item')
    order.updated_at = isoNow()
    return envelope(presentOrder(order), 201)
  }),

  route('patch', '/orders/:order/items/:item', async (ctx) => {
    const order = orderOf(ctx)
    const item = record(db().order_items, ctx, 'item')
    if (item.order_id !== order.id) throw notFound()
    authorize(canOn(ctx.user, 'update', 'order', order))
    const v = new Validator(await ctx.body())
    v.requiredIfPresent('unit_price_cents')
    itemRules(v)
    v.validate()
    writableOrder(order)
    const merged = makeItem(order, {
      service_id: v.has('service_id') ? v.value('service_id') : item.service_id,
      quantity: v.has('quantity') ? v.value('quantity') : item.quantity,
      unit_price_cents: v.has('unit_price_cents')
        ? v.value('unit_price_cents')
        : item.unit_price.amount_cents,
      description: v.has('description') ? v.value('description') : item.description,
    })
    const previous = { ...item }
    Object.assign(item, { ...merged, id: item.id })
    recalculate(order)
    try {
      ensureTotalCoversPayments(order, 'item')
    } catch (error) {
      Object.assign(item, previous)
      recalculate(order)
      throw error
    }
    order.updated_at = isoNow()
    return envelope(presentOrder(order))
  }),

  route('delete', '/orders/:order/items/:item', (ctx) => {
    const order = orderOf(ctx)
    const item = record(db().order_items, ctx, 'item')
    if (item.order_id !== order.id) throw notFound()
    authorize(canOn(ctx.user, 'update', 'order', order))
    writableOrder(order)
    if (items(order.id).length <= 1) {
      throw validationError({ item: ['An order needs at least one item.'] })
    }
    remove(db().order_items, item.id)
    recalculate(order)
    try {
      ensureTotalCoversPayments(order, 'item')
    } catch (error) {
      db().order_items.push(item)
      recalculate(order)
      throw error
    }
    order.updated_at = isoNow()
    return envelope(presentOrder(order))
  }),

  // Payments
  route('get', '/orders/:order/payments', (ctx) => {
    const order = orderOf(ctx)
    authorize(canView(ctx.user, 'order', order))
    const rows = db().payments.filter((p) => p.order_id === order.id && paymentVisible(ctx.user, p))
    return json(paginate(query(rows, ctx.url, PAYMENT_LIST), ctx.url, presentPayment))
  }),

  route('post', '/orders/:order/payments', async (ctx) => {
    const order = orderOf(ctx)
    authorize(can(ctx.user, 'payments.create') && orderVisible(ctx.user, order))
    const v = new Validator(await ctx.body())
    v.required('amount_cents').required('due_date')
    paymentRules(v)
    v.validate()
    const currency = (v.value('currency') as string | undefined) ?? order.currency
    ensureFits(order, Number(v.value('amount_cents')), currency)
    const now = isoNow()
    const sequence =
      db()
        .payments.filter((p) => p.order_id === order.id)
        .reduce((max, p) => Math.max(max, p.sequence), 0) + 1
    const payment: PaymentRow = {
      id: nextId(db().payments),
      order_id: order.id,
      sequence,
      amount: money(Number(v.value('amount_cents')), currency)!,
      currency,
      due_date: String(v.value('due_date')),
      status: enumOf('PaymentStatus', 'scheduled')!,
      paid_at: null,
      method: enumOf('PaymentMethod', v.value('method') as string | null | undefined),
      reference: (v.value('reference') as string | null | undefined) ?? null,
      notes: (v.value('notes') as string | null | undefined) ?? null,
      recorded_by_id: null,
      created_at: now,
      updated_at: now,
    }
    db().payments.push(payment)
    logModel('created', 'payment', payment, ctx.user, {
      sequence,
      amount_cents: payment.amount.amount_cents,
      status: 'scheduled',
    })
    return envelope(presentPayment(payment), 201)
  }),

  route('get', '/payments', ({ user, url }) => {
    authorize(canViewAny(user, 'orders'))
    const rows = db().payments.filter((p) => paymentVisible(user, p))
    return json(paginate(query(rows, url, PAYMENT_LIST), url, presentPayment))
  }),

  route('get', '/payments/:payment', (ctx) => {
    const payment = record(db().payments, ctx, 'payment')
    authorize(canView(ctx.user, 'payment', payment))
    return envelope(presentPayment(payment))
  }),

  route('patch', '/payments/:payment', async (ctx) => {
    const { user } = ctx
    const payment = record(db().payments, ctx, 'payment')
    authorize(mayChangeOrRequest(user, 'payment', payment, 'update'))
    const v = new Validator(await ctx.body())
    for (const field of ['order_id', 'sequence', 'recorded_by_id']) v.prohibited(field)
    v.prohibited('currency', 'Payments always use the order currency.')
    v.prohibited(
      'paid_at',
      'Use POST /api/payments/{payment}/mark-paid to record when a payment was made.',
    )
    paymentRules(v)
    v.in(
      'status',
      ['scheduled', 'void'],
      'Use POST /api/payments/{payment}/mark-paid to record a payment; status can only be set to scheduled or void here.',
    )
    const reason = reasonOf(v)
    v.validate()
    const changes = v.only(['amount_cents', 'due_date', 'status', 'method', 'reference', 'notes'])
    const status = (changes.status as string | undefined) ?? payment.status.value
    if (status !== 'paid' && payment.paid_at !== null) {
      changes.paid_at = null
      changes.recorded_by_id = null
    }
    const order = find(db().orders, payment.order_id)!
    if (status !== 'void') {
      ensureFits(
        order,
        Number(changes.amount_cents ?? payment.amount.amount_cents),
        payment.currency,
        payment,
      )
    }
    return updateOrRequest(
      user,
      'payment',
      payment,
      'update',
      () => {
        updatePayment(payment, changes, user)
        return envelope(presentPayment(payment))
      },
      () => submitUpdate(user, 'payment', payment, changes, {}, reason),
    )
  }),

  route('delete', '/payments/:payment', async (ctx) => {
    const { user } = ctx
    const payment = record(db().payments, ctx, 'payment')
    authorize(mayChangeOrRequest(user, 'payment', payment, 'delete'))
    const v = new Validator(await ctx.body())
    const reason = reasonOf(v)
    v.validate()
    return updateOrRequest(
      user,
      'payment',
      payment,
      'delete',
      () => {
        deletePayment(payment, user)
        return noContent()
      },
      () => submitDelete(user, 'payment', payment, reason),
    )
  }),

  route('post', '/payments/:payment/mark-paid', async (ctx) => {
    const { user } = ctx
    const payment = record(db().payments, ctx, 'payment')
    authorize(mayChangeOrRequest(user, 'payment', payment, 'update'))
    const v = new Validator(await ctx.body())
    if (v.filled('paid_at') && Number.isNaN(Date.parse(String(v.value('paid_at'))))) {
      v.add('paid_at', 'The paid at field must be a valid date.')
    } else if (v.filled('paid_at') && Date.parse(String(v.value('paid_at'))) > Date.now()) {
      v.add('paid_at', 'The paid at field must be a date before or equal to now.')
    }
    v.enum('method', 'PaymentMethod').string('reference', 100).string('notes', 2000)
    const reason = reasonOf(v)
    if (payment.status.value !== 'scheduled') {
      v.add('status', 'Only a scheduled payment can be marked as paid.')
    }
    v.validate()
    const paidAt = v.filled('paid_at') ? new Date(String(v.value('paid_at'))) : new Date()
    const changes: Json = {
      ...v.only(['method', 'reference', 'notes']),
      status: 'paid',
      paid_at: paidAt.toISOString().slice(0, 19).replace('T', ' '),
      recorded_by_id: user.id,
    }
    return updateOrRequest(
      user,
      'payment',
      payment,
      'update',
      () => {
        updatePayment(payment, changes, user)
        return envelope(presentPayment(payment))
      },
      () => submitUpdate(user, 'payment', payment, changes, {}, reason),
    )
  }),
]
