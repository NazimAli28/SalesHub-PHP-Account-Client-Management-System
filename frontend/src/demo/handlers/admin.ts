/** Users, teams, workstations and services (routes/api/users.php, teams.php, ...). */
import { isoNow } from '../dates'
import { applyFields, logActivity, logModel, type FieldSpec } from '../engine'
import { demoMode, envelope, json, noContent, route, validationError, type Json } from '../http'
import {
  can,
  canAssignRole,
  canManageUser,
  hasRole,
  isDemoAccount,
  userVisible,
} from '../permissions'
import {
  enumOf,
  money,
  presentService,
  presentTeam,
  presentUser,
  presentWorkstation,
} from '../present'
import { dateFrom, dateTo, exact, paginate, query, type ListSpec } from '../query'
import { db, find, nextId, remove } from '../store'
import type { ServiceRow, TeamRow, UserRow, WorkstationRow } from '../types'
import { Validator } from '../validate'
import { authorize, record } from './common'
import { rememberPassword } from './passwords'

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

const USER_LIST: ListSpec<UserRow> = {
  filters: {
    role: (u, values) => u.roles.some((role) => values.includes(role)),
    team: exact((u) => u.team_id),
    active: exact((u) => u.is_active),
    created_from: dateFrom((u) => u.created_at),
    created_to: dateTo((u) => u.created_at),
  },
  search: (u) => [u.name, u.username, u.email],
  sorts: {
    name: (u) => u.name,
    username: (u) => u.username,
    email: (u) => u.email,
    last_login_at: (u) => u.last_login_at,
    created_at: (u) => u.created_at,
    id: (u) => u.id,
  },
  defaultSort: 'name,id',
}

function lower(data: Json): Json {
  const out = { ...data }
  for (const field of ['username', 'email']) {
    if (typeof out[field] === 'string') out[field] = (out[field] as string).trim().toLowerCase()
  }
  return out
}

function userRules(v: Validator, target: UserRow | null): void {
  v.string('name', 120)
  v.string('username', 50).regex('username', /^[a-z0-9._-]{3,50}$/)
  v.unique('username', (name) => db().users.some((u) => u.id !== target?.id && u.username === name))
  v.string('email', 255).email('email')
  v.unique('email', (email) => db().users.some((u) => u.id !== target?.id && u.email === email))
  if (v.filled('password')) {
    const password = String(v.value('password'))
    if (password.length < 10)
      v.add('password', 'The password field must be at least 10 characters.')
    if (!/[a-z]/.test(password) || !/[A-Z]/.test(password)) {
      v.add(
        'password',
        'The password field must contain at least one uppercase and one lowercase letter.',
      )
    }
    if (!/\d/.test(password))
      v.add('password', 'The password field must contain at least one number.')
    if (!/[^A-Za-z0-9]/.test(password))
      v.add('password', 'The password field must contain at least one symbol.')
  }
  v.enum('role', 'RoleName')
  v.integer('team_id').exists('team_id', (id) => find(db().teams, id) !== undefined)
  v.integer('workstation_id').exists(
    'workstation_id',
    (id) => find(db().workstations, id) !== undefined,
  )
  // The workstation must belong to the (new) team.
  const stationId = v.value('workstation_id')
  if (
    stationId !== undefined &&
    stationId !== null &&
    !v.failed('workstation_id') &&
    !v.failed('team_id')
  ) {
    const teamId = v.has('team_id') ? v.value('team_id') : (target?.team_id ?? null)
    const station = find(db().workstations, Number(stationId))
    if (teamId === null || station?.team_id !== Number(teamId)) {
      v.add('workstation_id', 'The workstation must belong to the selected team.')
    }
  }
}

function auditUser(event: string, actor: UserRow, target: UserRow, properties: Json = {}): void {
  logActivity({
    logName: 'user',
    event,
    causer: actor,
    subject: { type: 'user', id: target.id, label: target.username },
    properties,
  })
}

// ---------------------------------------------------------------------------
// Teams, workstations, services
// ---------------------------------------------------------------------------

const TEAM_SPEC: FieldSpec = { enums: { shift: 'Shift' } }
const SERVICE_SPEC: FieldSpec = {
  enums: { category: 'ServiceCategory' },
  money: { base_price_cents: 'base_price' },
}

function displayName(team: TeamRow): string {
  return `${team.name} (Floor ${team.floor} ${team.shift.label})`
}

const TEAM_LIST: ListSpec<TeamRow> = {
  filters: {
    shift: exact((t) => t.shift.value),
    floor: exact((t) => t.floor),
    team_lead: exact((t) => t.team_lead_id),
  },
  search: (t) => [t.name],
  sorts: {
    name: (t) => t.name,
    floor: (t) => t.floor,
    shift: (t) => t.shift.value,
    created_at: (t) => t.created_at,
    id: (t) => t.id,
  },
  defaultSort: 'name,id',
}

const WORKSTATION_LIST: ListSpec<WorkstationRow> = {
  filters: { team: exact((w) => w.team_id), active: exact((w) => w.is_active) },
  search: (w) => [w.code, w.label],
  sorts: {
    code: (w) => w.code,
    label: (w) => w.label,
    created_at: (w) => w.created_at,
    id: (w) => w.id,
  },
  defaultSort: 'code,id',
}

const SERVICE_LIST: ListSpec<ServiceRow> = {
  filters: { category: exact((s) => s.category.value), active: exact((s) => s.is_active) },
  search: (s) => [s.name, s.slug, s.description],
  sorts: {
    name: (s) => s.name,
    category: (s) => s.category.value,
    base_price_cents: (s) => s.base_price.amount_cents,
    created_at: (s) => s.created_at,
    id: (s) => s.id,
  },
  defaultSort: 'name,id',
}

function teamLeadRule(v: Validator, team: TeamRow | null): void {
  if (!v.filled('team_lead_id') || v.failed('team_lead_id')) return
  const id = Number(v.value('team_lead_id'))
  if (db().teams.some((t) => t.id !== team?.id && t.team_lead_id === id)) {
    v.add('team_lead_id', 'The team lead id has already been taken.')
    return
  }
  const lead = find(db().users, id)
  const valid =
    lead !== undefined &&
    lead.is_active &&
    hasRole(lead, 'team_lead') &&
    (team === null ? lead.team_id === null : lead.team_id === team.id)
  if (!valid) {
    v.add(
      'team_lead_id',
      team === null
        ? 'The team lead must be an active team lead who is not on a team yet.'
        : 'The team lead must be an active team lead who belongs to this team.',
    )
  }
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export const adminHandlers = [
  // Users
  route('get', '/users', ({ user, url }) => {
    authorize(can(user, 'users.view'))
    const rows = db().users.filter((u) => userVisible(user, u))
    return json(paginate(query(rows, url, USER_LIST), url, presentUser))
  }),

  route('post', '/users', async ({ user, body }) => {
    authorize(can(user, 'users.create'))
    const v = new Validator(lower(await body()))
    for (const field of ['name', 'username', 'email', 'password', 'role']) v.required(field)
    userRules(v, null)
    v.boolean('is_active')
    v.validate()
    const role = String(v.value('role'))
    authorize(canAssignRole(user, null, role))
    const now = isoNow()
    const created: UserRow = {
      id: nextId(db().users),
      name: String(v.value('name')),
      username: String(v.value('username')),
      email: String(v.value('email')),
      avatar_url: null,
      is_active: v.has('is_active')
        ? v.value('is_active') === true || v.value('is_active') === 1
        : true,
      team_id: (v.value('team_id') as number | null | undefined) ?? null,
      workstation_id: (v.value('workstation_id') as number | null | undefined) ?? null,
      last_login_at: null,
      roles: [role],
      created_at: now,
      updated_at: now,
    }
    db().users.push(created)
    rememberPassword(created.id, String(v.value('password')))
    auditUser('role_changed', user, created, { from: [], to: [role] })
    return envelope(presentUser(created), 201)
  }),

  route('get', '/users/:id', (ctx) => {
    const target = record(db().users, ctx, 'id')
    authorize(
      target.id === ctx.user.id || (can(ctx.user, 'users.view') && userVisible(ctx.user, target)),
    )
    return envelope(presentUser(target))
  }),

  route('patch', '/users/:id', async (ctx) => {
    const { user } = ctx
    const target = record(db().users, ctx, 'id')
    authorize(canManageUser(user, 'users.update', target))
    const v = new Validator(lower(await ctx.body()))
    for (const field of ['name', 'username', 'email', 'role']) v.requiredIfPresent(field)
    if (target.id === user.id) {
      v.prohibited(
        'password',
        'Change your own password from your profile (PUT /api/auth/password).',
      )
    }
    v.prohibited(
      'is_active',
      'Use the activate or deactivate endpoint to change the account status.',
    )
    userRules(v, target)
    v.validate()
    const role = v.has('role') ? String(v.value('role')) : null
    if (isDemoAccount(target)) {
      const locking =
        v.has('password') ||
        (v.has('username') && v.value('username') !== target.username) ||
        (v.has('email') && v.value('email') !== target.email) ||
        (role !== null && JSON.stringify(target.roles) !== JSON.stringify([role]))
      if (locking) {
        throw demoMode(
          'the password, username, email and role of the demo accounts cannot be changed',
        )
      }
    }
    if (role !== null && !target.roles.includes(role)) authorize(canAssignRole(user, target, role))

    const changes = v.only(['name', 'username', 'email', 'team_id', 'workstation_id'])
    const teamChanged = 'team_id' in changes && changes.team_id !== target.team_id
    if (teamChanged && !('workstation_id' in changes)) changes.workstation_id = null
    applyFields(target, changes, {})
    if (v.has('password')) {
      rememberPassword(target.id, String(v.value('password')))
      auditUser('password_reset', user, target)
    }
    const roleChanged = role !== null && JSON.stringify(target.roles) !== JSON.stringify([role])
    if (roleChanged) {
      auditUser('role_changed', user, target, { from: target.roles, to: [role] })
      target.roles = [role]
    }
    if (teamChanged || (roleChanged && role !== 'team_lead')) {
      for (const team of db().teams) if (team.team_lead_id === target.id) team.team_lead_id = null
    }
    return envelope(presentUser(target))
  }),

  route('delete', '/users/:id', (ctx) => {
    const target = record(db().users, ctx, 'id')
    authorize(canManageUser(ctx.user, 'users.delete', target))
    if (isDemoAccount(target)) throw demoMode('the demo accounts cannot be deleted')
    for (const team of db().teams) if (team.team_lead_id === target.id) team.team_lead_id = null
    auditUser('deleted', ctx.user, target)
    remove(db().users, target.id)
    return noContent()
  }),

  route('patch', '/users/:id/deactivate', (ctx) => {
    const target = record(db().users, ctx, 'id')
    authorize(canManageUser(ctx.user, 'users.deactivate', target))
    if (isDemoAccount(target)) throw demoMode('the demo accounts cannot be deactivated')
    if (target.is_active) {
      target.is_active = false
      target.updated_at = isoNow()
      auditUser('deactivated', ctx.user, target)
    }
    return envelope(presentUser(target))
  }),

  route('patch', '/users/:id/activate', (ctx) => {
    const target = record(db().users, ctx, 'id')
    authorize(canManageUser(ctx.user, 'users.deactivate', target))
    if (isDemoAccount(target))
      throw demoMode('the demo accounts cannot be activated or deactivated')
    if (!target.is_active) {
      target.is_active = true
      target.updated_at = isoNow()
      auditUser('activated', ctx.user, target)
    }
    return envelope(presentUser(target))
  }),

  // Teams
  route('get', '/teams', ({ user, url }) => {
    authorize(can(user, 'teams.view'))
    return json(paginate(query(db().teams, url, TEAM_LIST), url, presentTeam))
  }),

  route('post', '/teams', async ({ user, body }) => {
    authorize(can(user, 'teams.manage'))
    const v = new Validator(await body())
    v.required('name').required('floor').required('shift')
    v.string('name', 80).unique('name', (name) => db().teams.some((t) => t.name === name))
    v.integer('floor', 0, 255).enum('shift', 'Shift').integer('team_lead_id')
    teamLeadRule(v, null)
    v.validate()
    const now = isoNow()
    const team: TeamRow = {
      id: nextId(db().teams),
      name: String(v.value('name')),
      display_name: '',
      floor: Number(v.value('floor')),
      shift: enumOf('Shift', String(v.value('shift')))!,
      team_lead_id: (v.value('team_lead_id') as number | null | undefined) ?? null,
      created_at: now,
      updated_at: now,
    }
    team.display_name = displayName(team)
    db().teams.push(team)
    const lead = find(db().users, team.team_lead_id)
    if (lead) lead.team_id = team.id
    logModel('created', 'team', team, user, { name: team.name })
    return envelope(presentTeam(team), 201)
  }),

  route('get', '/teams/:id', (ctx) => {
    authorize(can(ctx.user, 'teams.view'))
    return envelope(presentTeam(record(db().teams, ctx, 'id')))
  }),

  route('patch', '/teams/:id', async (ctx) => {
    const team = record(db().teams, ctx, 'id')
    authorize(can(ctx.user, 'teams.manage'))
    const v = new Validator(await ctx.body())
    for (const field of ['name', 'floor', 'shift']) v.requiredIfPresent(field)
    v.string('name', 80).unique('name', (name) =>
      db().teams.some((t) => t.id !== team.id && t.name === name),
    )
    v.integer('floor', 0, 255).enum('shift', 'Shift').integer('team_lead_id')
    teamLeadRule(v, team)
    v.validate()
    applyFields(team, v.only(['name', 'floor', 'shift', 'team_lead_id']), TEAM_SPEC)
    team.display_name = displayName(team)
    return envelope(presentTeam(team))
  }),

  route('delete', '/teams/:id', (ctx) => {
    const team = record(db().teams, ctx, 'id')
    authorize(can(ctx.user, 'teams.manage'))
    if (
      db().users.some((u) => u.team_id === team.id) ||
      db().workstations.some((w) => w.team_id === team.id)
    ) {
      throw validationError({
        team: ['This team still has members or workstations. Move them to another team first.'],
      })
    }
    remove(db().teams, team.id)
    return noContent()
  }),

  // Workstations
  route('get', '/workstations', ({ user, url }) => {
    authorize(can(user, 'workstations.view'))
    return json(paginate(query(db().workstations, url, WORKSTATION_LIST), url, presentWorkstation))
  }),

  route('post', '/workstations', async ({ user, body }) => {
    authorize(can(user, 'workstations.manage'))
    const v = new Validator(await body())
    v.required('code').required('team_id')
    v.string('code', 20).unique('code', (code) => db().workstations.some((w) => w.code === code))
    v.integer('team_id').exists('team_id', (id) => find(db().teams, id) !== undefined)
    v.string('label', 80).boolean('is_active')
    v.validate()
    const now = isoNow()
    const station: WorkstationRow = {
      id: nextId(db().workstations),
      code: String(v.value('code')),
      label: (v.value('label') as string | null | undefined) ?? null,
      is_active: v.has('is_active')
        ? v.value('is_active') === true || v.value('is_active') === 1
        : true,
      team_id: Number(v.value('team_id')),
      created_at: now,
      updated_at: now,
    }
    db().workstations.push(station)
    return envelope(presentWorkstation(station), 201)
  }),

  route('get', '/workstations/:id', (ctx) => {
    authorize(can(ctx.user, 'workstations.view'))
    return envelope(presentWorkstation(record(db().workstations, ctx, 'id')))
  }),

  route('patch', '/workstations/:id', async (ctx) => {
    const station = record(db().workstations, ctx, 'id')
    authorize(can(ctx.user, 'workstations.manage'))
    const v = new Validator(await ctx.body())
    v.requiredIfPresent('code').requiredIfPresent('team_id')
    v.string('code', 20).unique('code', (code) =>
      db().workstations.some((w) => w.id !== station.id && w.code === code),
    )
    v.integer('team_id').exists('team_id', (id) => find(db().teams, id) !== undefined)
    v.string('label', 80).boolean('is_active')
    if (
      v.filled('team_id') &&
      !v.failed('team_id') &&
      Number(v.value('team_id')) !== station.team_id &&
      db().users.some((u) => u.workstation_id === station.id)
    ) {
      v.add('team_id', 'Users are seated on this workstation. Move them first.')
    }
    v.validate()
    applyFields(station, v.only(['code', 'team_id', 'label', 'is_active']), {})
    return envelope(presentWorkstation(station))
  }),

  route('delete', '/workstations/:id', (ctx) => {
    const station = record(db().workstations, ctx, 'id')
    authorize(can(ctx.user, 'workstations.manage'))
    if (
      db().users.some((u) => u.workstation_id === station.id) ||
      db().platform_accounts.some((a) => a.workstation_id === station.id)
    ) {
      throw validationError({
        workstation: [
          'This workstation still has users or platform accounts. Reassign them first.',
        ],
      })
    }
    remove(db().workstations, station.id)
    return noContent()
  }),

  // Services
  route('get', '/services', ({ user, url }) => {
    authorize(can(user, 'services.view'))
    return json(paginate(query(db().services, url, SERVICE_LIST), url, presentService))
  }),

  route('post', '/services', async ({ user, body }) => {
    authorize(can(user, 'services.manage'))
    const v = new Validator(await body())
    v.required('name').required('category').required('base_price_cents')
    v.string('name', 80).enum('category', 'ServiceCategory')
    v.string('slug', 80).regex('slug', /^[a-z0-9]+(?:-[a-z0-9]+)*$/)
    v.unique('slug', (slug) => db().services.some((s) => s.slug === slug))
    v.string('description', 2000).integer('base_price_cents', 0, 100_000_000)
    v.regex('currency', /^[A-Z]{3}$/).boolean('is_active')
    v.validate()
    const slug = (v.value('slug') as string | undefined) ?? slugify(String(v.value('name')))
    if (db().services.some((s) => s.slug === slug)) {
      throw validationError({ slug: ['The slug has already been taken.'] })
    }
    const now = isoNow()
    const currency = (v.value('currency') as string | undefined) ?? 'USD'
    const service: ServiceRow = {
      id: nextId(db().services),
      name: String(v.value('name')),
      slug,
      category: enumOf('ServiceCategory', String(v.value('category')))!,
      description: (v.value('description') as string | null | undefined) ?? null,
      base_price: money(Number(v.value('base_price_cents')), currency)!,
      currency,
      is_active: v.has('is_active')
        ? v.value('is_active') === true || v.value('is_active') === 1
        : true,
      created_at: now,
      updated_at: now,
    }
    db().services.push(service)
    return envelope(presentService(service), 201)
  }),

  route('get', '/services/:id', (ctx) => {
    authorize(can(ctx.user, 'services.view'))
    return envelope(presentService(record(db().services, ctx, 'id')))
  }),

  route('patch', '/services/:id', async (ctx) => {
    const service = record(db().services, ctx, 'id')
    authorize(can(ctx.user, 'services.manage'))
    const v = new Validator(await ctx.body())
    for (const field of ['name', 'slug', 'category', 'base_price_cents', 'currency'])
      v.requiredIfPresent(field)
    v.string('name', 80).enum('category', 'ServiceCategory')
    v.string('slug', 80).regex('slug', /^[a-z0-9]+(?:-[a-z0-9]+)*$/)
    v.unique('slug', (slug) => db().services.some((s) => s.id !== service.id && s.slug === slug))
    v.string('description', 2000).integer('base_price_cents', 0, 100_000_000)
    v.regex('currency', /^[A-Z]{3}$/).boolean('is_active')
    v.validate()
    const changes = v.only([
      'name',
      'slug',
      'category',
      'description',
      'base_price_cents',
      'currency',
      'is_active',
    ])
    if ('is_active' in changes)
      changes.is_active = changes.is_active === true || changes.is_active === 1
    applyFields(service, changes, SERVICE_SPEC)
    return envelope(presentService(service))
  }),

  route('delete', '/services/:id', (ctx) => {
    const service = record(db().services, ctx, 'id')
    authorize(can(ctx.user, 'services.manage'))
    remove(db().services, service.id)
    return noContent()
  }),
]
