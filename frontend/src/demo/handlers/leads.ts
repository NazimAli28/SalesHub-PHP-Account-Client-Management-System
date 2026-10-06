/** Leads (routes/api/leads.php): CRUD, stage moves and reassignment. */
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
import { envelope, json, noContent, route, type Json } from '../http'
import { can, canView, canViewAny, clientVisible, hasRole, leadVisible } from '../permissions'
import { enumOf, money, presentLead } from '../present'
import { dateFrom, dateTo, exact, onlyWhenTrue, paginate, query, type ListSpec } from '../query'
import { db, find, nextId, remove, today } from '../store'
import type { LeadRow, UserRow } from '../types'
import { Validator } from '../validate'
import {
  activeService,
  activeVisibleUser,
  authorize,
  reasonOf,
  record,
  visiblePlatformAccount,
} from './common'
import { createClientRow } from './clients'

export const LEAD_SPEC: FieldSpec = {
  enums: { stage: 'LeadStage', lost_reason: 'LeadLostReason' },
  money: { estimated_value_cents: 'estimated_value' },
  datetimes: ['stage_changed_at'],
}

const OPEN = (stage: string) => stage !== 'won' && stage !== 'lost'

export const LEAD_LIST: ListSpec<LeadRow> = {
  filters: {
    stage: exact((l) => l.stage.value),
    owner: exact((l) => l.owner_id),
    client: exact((l) => l.client_id),
    platform_account: exact((l) => l.platform_account_id),
    contacted_from: dateFrom((l) => l.contacted_on),
    contacted_to: dateTo((l) => l.contacted_on),
    follow_up_from: dateFrom((l) => l.next_follow_up_on),
    follow_up_to: dateTo((l) => l.next_follow_up_on),
    open: onlyWhenTrue((l) => OPEN(l.stage.value)),
  },
  search: (l) => {
    const client = find(db().clients, l.client_id)
    return [l.last_message, l.lost_note, client?.discord_username, client?.name, client?.email]
  },
  sorts: {
    contacted_on: (l) => l.contacted_on,
    stage_changed_at: (l) => l.stage_changed_at,
    next_follow_up_on: (l) => l.next_follow_up_on,
    estimated_value_cents: (l) => l.estimated_value?.amount_cents ?? null,
    created_at: (l) => l.created_at,
    id: (l) => l.id,
  },
  defaultSort: '-contacted_on,-id',
}

export function visibleLeads(user: UserRow): LeadRow[] {
  return db().leads.filter((lead) => leadVisible(user, lead))
}

/** LeadRules::leadAttributeRules (+ lostReasonCheck). */
function leadRules(v: Validator, user: UserRow, lead: LeadRow | null, allowWon = false): void {
  v.integer('closer_id').exists('closer_id', activeVisibleUser(user))
  v.integer('platform_account_id').exists('platform_account_id', visiblePlatformAccount(user))
  v.enum('stage', 'LeadStage', allowWon ? [] : ['won'])
  v.date('contacted_on').notFuture('contacted_on', today())
  v.integer('estimated_value_cents', 0, 100_000_000)
  v.regex('currency', /^[A-Z]{3}$/)
  v.string('last_message', 5000)
  v.date('next_follow_up_on')
  v.enum('lost_reason', 'LeadLostReason')
  v.string('lost_note', 2000)
  if (v.has('service_ids')) {
    const ids = v.value('service_ids')
    if (!Array.isArray(ids)) v.add('service_ids', 'The service ids field must be an array.')
    else if (ids.length > 50)
      v.add('service_ids', 'The service ids field must not have more than 50 items.')
    else
      ids.forEach((id, index) => {
        if (!activeService(Number(id)))
          v.add(`service_ids.${index}`, `The selected service_ids.${index} is invalid.`)
      })
  }
  const stage = v.has('stage') ? v.value('stage') : (lead?.stage.value ?? 'new')
  const reason = v.has('lost_reason') ? v.value('lost_reason') : (lead?.lost_reason?.value ?? null)
  if (stage === 'lost' && (reason === null || reason === '')) {
    v.add('lost_reason', 'A lost reason is required when the lead is lost.')
  }
}

/** UpdateLead::prepare: lost fields only on lost leads, follow-ups only on open ones. */
function prepare(lead: LeadRow, data: Json): Json {
  const out = { ...data }
  const stage = typeof out.stage === 'string' ? out.stage : lead.stage.value
  if (stage !== 'lost') {
    if (lead.lost_reason !== null || 'lost_reason' in out) out.lost_reason = null
    if (lead.lost_note !== null || 'lost_note' in out) out.lost_note = null
  }
  if (!OPEN(stage) && (lead.next_follow_up_on !== null || 'next_follow_up_on' in out)) {
    out.next_follow_up_on = null
  }
  return out
}

/** UpdateLead::handle (+ the model's stage_changed_at hook and activity log). */
export function updateLead(
  lead: LeadRow,
  changes: Json,
  serviceIds: number[] | null,
  actor: UserRow,
  approvalId?: number,
): void {
  const real = realChanges(lead, changes, LEAD_SPEC)
  const old: Json = {}
  for (const field of Object.keys(real)) old[field] = rawValue(lead, field, LEAD_SPEC)
  if ('stage' in real && !('stage_changed_at' in real)) real.stage_changed_at = isoNow()
  applyFields(lead, real, LEAD_SPEC)
  if (serviceIds !== null)
    lead.service_ids = [...new Set(serviceIds.map(Number))].sort((a, b) => a - b)
  const logged = { ...real }
  delete logged.stage_changed_at
  logModel('updated', 'lead', lead, actor, logged, old, approvalId)
}

function deleteLead(lead: LeadRow, actor: UserRow, approvalId?: number): void {
  remove(db().leads, lead.id)
  logModel('deleted', 'lead', lead, actor, {}, { stage: lead.stage.value }, approvalId)
}

registerApplier('lead', {
  spec: LEAD_SPEC,
  find: (id) => find(db().leads, id),
  update: (row, changes, relations, actor, approvalId) =>
    updateLead(row as LeadRow, changes, relations.services ?? null, actor, approvalId),
  remove: (row, actor, approvalId) => deleteLead(row as LeadRow, actor, approvalId),
  relations: (row) => ({ services: [...(row as LeadRow).service_ids] }),
})

export const leadHandlers = [
  route('get', '/leads', ({ user, url }) => {
    authorize(canViewAny(user, 'leads'))
    return json(paginate(query(visibleLeads(user), url, LEAD_LIST), url, presentLead))
  }),

  route('post', '/leads', async ({ user, body }) => {
    authorize(can(user, 'leads.create'))
    const v = new Validator(await body())
    const newClient = v.value('client') as Json | undefined
    if (!v.filled('client_id') && !newClient) {
      v.add('client_id', 'The client id field is required when client is not present.')
    }
    if (v.filled('client_id') && newClient) {
      v.add('client_id', 'The client id field prohibits client from being present.')
    }
    v.integer('client_id').exists('client_id', (id) => {
      const client = find(db().clients, id)
      return client !== undefined && clientVisible(user, client)
    })
    if (newClient && typeof newClient === 'object') {
      const c = new Validator(newClient)
      c.required('discord_username').string('discord_username', 64)
      c.unique('discord_username', (name) =>
        db().clients.some((cl) => cl.discord_username.toLowerCase() === name.toLowerCase()),
      )
      c.string('name', 120).email('email').string('email', 255)
      for (const [field, messages] of Object.entries(c.errors)) {
        for (const message of messages)
          v.add(
            `client.${field}`,
            message.replace(
              `The ${field.replace(/_/g, ' ')}`,
              `The client.${field.replace(/_/g, ' ')}`,
            ),
          )
      }
    }
    v.integer('owner_id').exists('owner_id', activeVisibleUser(user))
    leadRules(v, user, null)
    v.validate()

    const data = v.data
    const ownerId = (data.owner_id as number | undefined) ?? user.id
    let clientId = Number(data.client_id)
    if (newClient) {
      clientId = createClientRow(
        {
          discord_username: newClient.discord_username,
          name: newClient.name ?? null,
          email: newClient.email ?? null,
          owner_id: ownerId,
        },
        user,
      ).id
    }
    const stage = (data.stage as string | undefined) ?? 'new'
    const currency = (data.currency as string | undefined) ?? 'USD'
    const now = isoNow()
    const lead: LeadRow = {
      id: nextId(db().leads),
      stage: enumOf('LeadStage', stage)!,
      stage_changed_at: now,
      contacted_on: (data.contacted_on as string | undefined) ?? today(),
      estimated_value: money(
        data.estimated_value_cents === undefined || data.estimated_value_cents === null
          ? null
          : Number(data.estimated_value_cents),
        currency,
      ),
      currency,
      last_message: (data.last_message as string | null | undefined) ?? null,
      next_follow_up_on: OPEN(stage)
        ? ((data.next_follow_up_on as string | null | undefined) ?? null)
        : null,
      lost_reason: stage === 'lost' ? enumOf('LeadLostReason', data.lost_reason as string) : null,
      lost_note: stage === 'lost' ? ((data.lost_note as string | null | undefined) ?? null) : null,
      client_id: clientId,
      owner_id: ownerId,
      closer_id: (data.closer_id as number | null | undefined) ?? null,
      platform_account_id: (data.platform_account_id as number | null | undefined) ?? null,
      order_id: null,
      service_ids: Array.isArray(data.service_ids)
        ? [...new Set((data.service_ids as unknown[]).map(Number))].sort((a, b) => a - b)
        : [],
      created_at: now,
      updated_at: now,
    }
    db().leads.push(lead)
    logModel('created', 'lead', lead, user, { stage, client_id: clientId, owner_id: ownerId })
    return envelope(presentLead(lead), 201)
  }),

  route('get', '/leads/:id', (ctx) => {
    const lead = record(db().leads, ctx, 'id')
    authorize(canView(ctx.user, 'lead', lead))
    return envelope(presentLead(lead))
  }),

  route('patch', '/leads/:id', async (ctx) => {
    const { user } = ctx
    const lead = record(db().leads, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'lead', lead, 'update'))
    const v = new Validator(await ctx.body())
    v.prohibited('owner_id', 'Use PATCH /api/leads/{lead}/owner to reassign a lead.')
    v.prohibited('order_id', 'The order is linked when the lead is won.')
    v.integer('client_id').exists('client_id', (id) => {
      const client = find(db().clients, id)
      return client !== undefined && clientVisible(user, client)
    })
    leadRules(v, user, lead)
    const reason = reasonOf(v)
    v.validate()

    const changes = prepare(
      lead,
      v.only([
        'client_id',
        'closer_id',
        'platform_account_id',
        'stage',
        'contacted_on',
        'estimated_value_cents',
        'currency',
        'last_message',
        'next_follow_up_on',
        'lost_reason',
        'lost_note',
      ]),
    )
    const serviceIds = v.has('service_ids')
      ? (v.value('service_ids') as unknown[]).map(Number)
      : null
    return updateOrRequest(
      user,
      'lead',
      lead,
      'update',
      () => {
        updateLead(lead, changes, serviceIds, user)
        return envelope(presentLead(lead))
      },
      () =>
        submitUpdate(
          user,
          'lead',
          lead,
          changes,
          serviceIds ? { services: serviceIds } : {},
          reason,
        ),
    )
  }),

  route('delete', '/leads/:id', async (ctx) => {
    const { user } = ctx
    const lead = record(db().leads, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'lead', lead, 'delete'))
    const v = new Validator(await ctx.body())
    const reason = reasonOf(v)
    v.validate()
    return updateOrRequest(
      user,
      'lead',
      lead,
      'delete',
      () => {
        deleteLead(lead, user)
        return noContent()
      },
      () => submitDelete(user, 'lead', lead, reason),
    )
  }),

  route('patch', '/leads/:id/stage', async (ctx) => {
    const { user } = ctx
    const lead = record(db().leads, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'lead', lead, 'update'))
    const v = new Validator(await ctx.body())
    v.required('stage').enum('stage', 'LeadStage')
    v.enum('lost_reason', 'LeadLostReason')
    v.string('lost_note', 2000)
    if (v.value('stage') === 'won' && !v.filled('order_id')) {
      v.add('order_id', 'The order id field is required when stage is won.')
    }
    v.integer('order_id').exists('order_id', (id) => {
      const order = find(db().orders, id)
      return (
        order !== undefined && order.client_id === lead.client_id && canView(user, 'order', order)
      )
    })
    const reason = reasonOf(v)
    const stage = v.has('stage') ? v.value('stage') : lead.stage.value
    const lostReason = v.has('lost_reason')
      ? v.value('lost_reason')
      : (lead.lost_reason?.value ?? null)
    if (stage === 'lost' && (lostReason === null || lostReason === '')) {
      v.add('lost_reason', 'A lost reason is required when the lead is lost.')
    }
    v.validate()

    const input = v.only(['stage', 'lost_reason', 'lost_note', 'order_id'])
    if (input.stage !== 'won') delete input.order_id
    const changes = prepare(lead, input)
    return updateOrRequest(
      user,
      'lead',
      lead,
      'update',
      () => {
        updateLead(lead, changes, null, user)
        return envelope(presentLead(lead))
      },
      () => submitUpdate(user, 'lead', lead, changes, {}, reason),
    )
  }),

  route('patch', '/leads/:id/owner', async (ctx) => {
    const { user } = ctx
    const lead = record(db().leads, ctx, 'id')
    authorize(can(user, 'leads.reassign') && leadVisible(user, lead))
    const v = new Validator(await ctx.body())
    v.required('owner_id')
      .integer('owner_id')
      .exists('owner_id', (id) => {
        const target = find(db().users, id)
        return (
          target !== undefined &&
          activeVisibleUser(user)(id) &&
          hasRole(target, 'sales_executive', 'team_lead')
        )
      })
    v.validate()
    updateLead(lead, { owner_id: Number(v.value('owner_id')) }, null, user)
    return envelope(presentLead(lead))
  }),
]

/** CSV export rows (App\Exports\CsvExport::leads). */
export function leadCsvRow(lead: LeadRow): unknown[] {
  const client = find(db().clients, lead.client_id)
  const services = db().services.filter((s) => lead.service_ids.includes(s.id))
  return [
    lead.id,
    client?.discord_username ?? '',
    client?.name ?? '',
    client?.email ?? '',
    find(db().users, lead.owner_id)?.username ?? '',
    lead.stage.value,
    lead.contacted_on,
    lead.estimated_value ? (lead.estimated_value.amount_cents / 100).toFixed(2) : '',
    lead.currency,
    lead.last_message,
    lead.next_follow_up_on,
    lead.lost_reason?.value ?? null,
    lead.lost_note,
    services.map((s) => s.name).join('; '),
    lead.created_at,
  ]
}
