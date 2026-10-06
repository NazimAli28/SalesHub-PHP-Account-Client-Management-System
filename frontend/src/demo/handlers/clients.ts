/** Clients (routes/api/clients.php), client notes and the Client 360 timeline (client-notes.php). */
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
import { envelope, json, noContent, notFound, route, type Json } from '../http'
import { can, canView, canViewAny, clientVisible, leadVisible, orderVisible } from '../permissions'
import { enumOf, presentClient, presentClientDetail, presentNote } from '../present'
import { dateFrom, dateTo, exact, flag, paginate, query, type ListSpec } from '../query'
import { db, find, nextId, remove } from '../store'
import type { ClientRow, NoteRow, UserRow } from '../types'
import { Validator } from '../validate'
import { activeVisibleUser, authorize, reasonOf, record } from './common'
import { lifetimeValueCents } from '../present'

export const CLIENT_SPEC: FieldSpec = { enums: { status: 'ClientStatus' } }

const OPEN_ORDER = ['pending_payment', 'in_progress', 'delivered']

function hasOpenOrders(client: ClientRow): boolean {
  return db().orders.some((o) => o.client_id === client.id && OPEN_ORDER.includes(o.status.value))
}

export const CLIENT_LIST: ListSpec<ClientRow> = {
  filters: {
    status: exact((c) => c.status.value),
    owner: exact((c) => c.owner_id),
    has_open_orders: flag(hasOpenOrders),
    created_from: dateFrom((c) => c.created_at),
    created_to: dateTo((c) => c.created_at),
  },
  search: (c) => [c.name, c.email, c.discord_username],
  sorts: {
    created_at: (c) => c.created_at,
    name: (c) => c.name,
    discord_username: (c) => c.discord_username,
    expected_upsell_on: (c) => c.expected_upsell_on,
    nurturing_rating: (c) => c.nurturing_rating,
    id: (c) => c.id,
  },
  defaultSort: '-created_at,-id',
}

export function visibleClients(user: UserRow): ClientRow[] {
  return db().clients.filter((client) => clientVisible(user, client))
}

const CLIENT_FIELDS = [
  'discord_username',
  'name',
  'email',
  'payment_name',
  'country',
  'owner_id',
  'status',
  'nurturing_rating',
  'next_upsell_plan',
  'expected_upsell_on',
  'lost_note',
  'notes',
]

function clientRules(v: Validator, user: UserRow, client: ClientRow | null): void {
  v.string('discord_username', 64).unique('discord_username', (name) =>
    db().clients.some(
      (c) => c.id !== client?.id && c.discord_username.toLowerCase() === name.toLowerCase(),
    ),
  )
  v.string('name', 120).email('email').string('email', 255).string('payment_name', 120)
  v.regex('country', /^[A-Z]{2}$/)
  v.integer('owner_id').exists('owner_id', activeVisibleUser(user))
  v.enum('status', 'ClientStatus')
  v.integer('nurturing_rating', 0, 100)
  v.string('next_upsell_plan', 5000).date('expected_upsell_on')
  v.string('lost_note', 2000).string('notes', 5000)
}

/** CreateClient (also used by the lead form's "new client"). */
export function createClientRow(data: Json, actor: UserRow): ClientRow {
  const now = isoNow()
  const client: ClientRow = {
    id: nextId(db().clients),
    discord_username: String(data.discord_username),
    name: (data.name as string | null | undefined) ?? null,
    email: (data.email as string | null | undefined) ?? null,
    payment_name: (data.payment_name as string | null | undefined) ?? null,
    country: (data.country as string | null | undefined) ?? null,
    status: enumOf('ClientStatus', (data.status as string | undefined) ?? 'active')!,
    nurturing_rating: (data.nurturing_rating as number | null | undefined) ?? null,
    next_upsell_plan: (data.next_upsell_plan as string | null | undefined) ?? null,
    expected_upsell_on: (data.expected_upsell_on as string | null | undefined) ?? null,
    lost_note: (data.lost_note as string | null | undefined) ?? null,
    notes: (data.notes as string | null | undefined) ?? null,
    owner_id: (data.owner_id as number | null | undefined) ?? actor.id,
    created_at: now,
    updated_at: now,
  }
  db().clients.push(client)
  logModel('created', 'client', client, actor, {
    discord_username: client.discord_username,
    status: client.status.value,
  })
  return client
}

export function updateClient(
  client: ClientRow,
  changes: Json,
  actor: UserRow,
  approvalId?: number,
): void {
  const real = realChanges(client, changes, CLIENT_SPEC)
  const old: Json = {}
  for (const field of Object.keys(real)) old[field] = rawValue(client, field, CLIENT_SPEC)
  applyFields(client, real, CLIENT_SPEC)
  logModel('updated', 'client', client, actor, real, old, approvalId)
}

function deleteClient(client: ClientRow, actor: UserRow, approvalId?: number): void {
  remove(db().clients, client.id)
  logModel(
    'deleted',
    'client',
    client,
    actor,
    {},
    { discord_username: client.discord_username },
    approvalId,
  )
}

registerApplier('client', {
  spec: CLIENT_SPEC,
  find: (id) => find(db().clients, id),
  update: (row, changes, _relations, actor, approvalId) =>
    updateClient(row as ClientRow, changes, actor, approvalId),
  remove: (row, actor, approvalId) => deleteClient(row as ClientRow, actor, approvalId),
})

// ---------------------------------------------------------------------------
// Timeline (ClientTimelineController)
// ---------------------------------------------------------------------------

const VALUE_FIELDS = ['stage', 'status']

function headline(value: string): string {
  return value
    .split(/[_\s-]+/)
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function timeline(client: ClientRow, user: UserRow): Json[] {
  const leadIds = new Set(
    db()
      .leads.filter((l) => l.client_id === client.id && leadVisible(user, l))
      .map((l) => l.id),
  )
  const orders = new Map(
    db()
      .orders.filter((o) => o.client_id === client.id && orderVisible(user, o))
      .map((o) => [o.id, o.order_number]),
  )
  const payments = new Map(
    db()
      .payments.filter((p) => orders.has(p.order_id))
      .map((p) => [p.id, p]),
  )
  const users = new Map(db().users.map((u) => [u.id, u]))
  const actor = (id: number | null) => {
    const found = id === null ? undefined : users.get(id)
    return found ? { id: found.id, name: found.name } : null
  }

  const items: (Json & { at: string; sort: number })[] = []
  for (const activity of db().activities) {
    const subject = activity.subject
    if (!subject) continue
    const { type, id } = subject
    const relevant =
      (type === 'client' && id === client.id) ||
      (type === 'lead' && leadIds.has(id)) ||
      (type === 'order' && orders.has(id)) ||
      (type === 'payment' && payments.has(id))
    if (!relevant) continue

    const payment = payments.get(id)
    let label = 'Client'
    let link = `/clients/${client.id}`
    if (type === 'lead') {
      label = `Lead #${id}`
      link = '/leads'
    } else if (type === 'order') {
      label = `Order ${orders.get(id) ?? `#${id}`}`
      link = `/orders/${id}`
    } else if (type === 'payment') {
      label = `Payment ${payment ? payment.sequence : `#${id}`} of order ${payment ? (orders.get(payment.order_id) ?? '') : ''}`
      link = `/orders/${payment?.order_id ?? ''}`
    }
    const event = activity.event ?? activity.description
    const changes = activity.attribute_changes as { attributes?: Record<string, unknown> }
    const attributes = Array.isArray(changes) ? {} : (changes.attributes ?? {})
    let summary = `${type === 'client' ? 'Client' : label} ${event}`.trim()
    if (event === 'updated' && Object.keys(attributes).length > 0) {
      summary +=
        ': ' +
        Object.entries(attributes)
          .map(([field, value]) =>
            VALUE_FIELDS.includes(field) && typeof value === 'string'
              ? `${headline(field).toLowerCase()} → ${headline(value)}`
              : headline(field).toLowerCase(),
          )
          .join(', ')
    }
    items.push({
      id: `activity-${activity.id}`,
      kind: 'activity',
      at: activity.created_at ?? '',
      summary,
      link,
      actor: actor(activity.causer_id),
      sort: activity.id,
    })
  }
  for (const note of db().client_notes.filter((n) => n.client_id === client.id)) {
    items.push({
      id: `note-${note.id}`,
      kind: 'note',
      at: note.created_at ?? '',
      summary: `Added a note: ${note.body.length > 140 ? `${note.body.slice(0, 140)}...` : note.body}`,
      link: `/clients/${client.id}`,
      actor: actor(note.author_id),
      sort: note.id,
    })
  }
  return items
    .sort((a, b) => b.at.localeCompare(a.at) || b.sort - a.sort)
    .map(({ sort, ...item }) => {
      void sort
      return item
    })
}

function viewableClient(ctx: Parameters<Parameters<typeof route>[2]>[0]): ClientRow {
  const client = record(db().clients, ctx, 'client')
  authorize(canView(ctx.user, 'client', client))
  return client
}

export const clientHandlers = [
  route('get', '/clients', ({ user, url }) => {
    authorize(canViewAny(user, 'clients'))
    return json(paginate(query(visibleClients(user), url, CLIENT_LIST), url, presentClient))
  }),

  route('post', '/clients', async ({ user, body }) => {
    authorize(can(user, 'clients.create'))
    const v = new Validator(await body())
    v.required('discord_username')
    clientRules(v, user, null)
    v.validate()
    const client = createClientRow(v.only(CLIENT_FIELDS), user)
    return envelope(presentClient(client), 201)
  }),

  route('get', '/clients/:client', (ctx) =>
    envelope(presentClientDetail(viewableClient(ctx), ctx.user)),
  ),

  route('patch', '/clients/:client', async (ctx) => {
    const { user } = ctx
    const client = record(db().clients, ctx, 'client')
    authorize(mayChangeOrRequest(user, 'client', client, 'update'))
    const v = new Validator(await ctx.body())
    v.requiredIfPresent('discord_username').requiredIfPresent('owner_id')
    clientRules(v, user, client)
    const reason = reasonOf(v)
    v.validate()
    const changes = v.only(CLIENT_FIELDS)
    return updateOrRequest(
      user,
      'client',
      client,
      'update',
      () => {
        updateClient(client, changes, user)
        return envelope(presentClient(client))
      },
      () => submitUpdate(user, 'client', client, changes, {}, reason),
    )
  }),

  route('delete', '/clients/:client', async (ctx) => {
    const { user } = ctx
    const client = record(db().clients, ctx, 'client')
    authorize(mayChangeOrRequest(user, 'client', client, 'delete'))
    const v = new Validator(await ctx.body())
    const reason = reasonOf(v)
    v.validate()
    return updateOrRequest(
      user,
      'client',
      client,
      'delete',
      () => {
        deleteClient(client, user)
        return noContent()
      },
      () => submitDelete(user, 'client', client, reason),
    )
  }),

  // Notes: anyone who can view the client reads and adds them.
  route('get', '/clients/:client/notes', (ctx) => {
    const client = viewableClient(ctx)
    const notes = db()
      .client_notes.filter((n) => n.client_id === client.id)
      .sort(
        (a, b) =>
          Number(b.is_pinned) - Number(a.is_pinned) ||
          (b.created_at ?? '').localeCompare(a.created_at ?? '') ||
          b.id - a.id,
      )
    return json(paginate(notes, ctx.url, (note) => presentNote(note, ctx.user)))
  }),

  route('post', '/clients/:client/notes', async (ctx) => {
    const client = viewableClient(ctx)
    const v = new Validator(await ctx.body())
    v.required('body').string('body', 2000).boolean('is_pinned')
    v.validate()
    const now = isoNow()
    const note: NoteRow = {
      id: nextId(db().client_notes),
      client_id: client.id,
      body: String(v.value('body')).trim(),
      is_pinned: v.value('is_pinned') === true || v.value('is_pinned') === 1,
      author_id: ctx.user.id,
      created_at: now,
      updated_at: now,
    }
    db().client_notes.push(note)
    return envelope(presentNote(note, ctx.user), 201)
  }),

  route('patch', '/clients/:client/notes/:note', async (ctx) => {
    const client = record(db().clients, ctx, 'client')
    const note = record(db().client_notes, ctx, 'note')
    if (note.client_id !== client.id) throw notFound()
    const data = await ctx.body()
    const mayPin = clientVisible(ctx.user, client)
    authorize('body' in data ? mayPin && note.author_id === ctx.user.id : mayPin)
    const v = new Validator(data)
    v.requiredIfPresent('body').string('body', 2000).boolean('is_pinned')
    v.validate()
    if (v.has('body')) note.body = String(v.value('body')).trim()
    if (v.has('is_pinned'))
      note.is_pinned = v.value('is_pinned') === true || v.value('is_pinned') === 1
    note.updated_at = isoNow()
    return envelope(presentNote(note, ctx.user))
  }),

  route('delete', '/clients/:client/notes/:note', (ctx) => {
    const client = record(db().clients, ctx, 'client')
    const note = record(db().client_notes, ctx, 'note')
    if (note.client_id !== client.id) throw notFound()
    authorize(
      clientVisible(ctx.user, client) &&
        (note.author_id === ctx.user.id || can(ctx.user, 'clients.update')),
    )
    remove(db().client_notes, note.id)
    return noContent()
  }),

  route('get', '/clients/:client/timeline', (ctx) => {
    const client = viewableClient(ctx)
    return json(paginate(timeline(client, ctx.user), ctx.url, (item) => item))
  }),
]

/** CSV export rows (App\Exports\CsvExport::clients). */
export function clientCsvRow(client: ClientRow): unknown[] {
  return [
    client.id,
    client.discord_username,
    client.name,
    client.email,
    client.payment_name,
    client.country,
    find(db().users, client.owner_id)?.username ?? '',
    client.status.value,
    client.nurturing_rating,
    client.next_upsell_plan,
    client.expected_upsell_on,
    client.notes,
    (lifetimeValueCents(client.id) / 100).toFixed(2),
    client.created_at,
  ]
}
