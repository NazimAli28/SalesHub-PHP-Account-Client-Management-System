/**
 * Platform and social accounts (routes/api/platform-accounts.php, social-accounts.php,
 * credentials.php, approvals.php `request-new`). Credentials never exist in the demo: a reveal
 * returns obvious placeholders and is written to the audit log, like the real one.
 */
import { isoNow } from '../dates'
import {
  applyFields,
  logActivity,
  logModel,
  mayChangeOrRequest,
  queued,
  rawValue,
  realChanges,
  registerApplier,
  submitAccountRequest,
  submitDelete,
  submitUpdate,
  updateOrRequest,
  type FieldSpec,
} from '../engine'
import { envelope, json, noContent, route, type Json } from '../http'
import {
  can,
  canOn,
  canView,
  canViewAny,
  platformAccountVisible,
  socialAccountVisible,
  tier,
} from '../permissions'
import { enumOf, presentPlatformAccount, presentSocialAccount } from '../present'
import { dateFrom, dateTo, exact, flag, paginate, query, type ListSpec } from '../query'
import { db, find, nextId, remove, today } from '../store'
import type { PlatformAccountRow, SocialAccountRow, UserRow } from '../types'
import { Validator } from '../validate'
import { activeWorkstation, authorize, reasonOf, record, visiblePlatformAccount } from './common'

const SECRETS_PA = {
  email_password: 'has_email_password',
  discord_password: 'has_discord_password',
  recovery_phone: 'has_recovery_phone',
  phone_holder_name: 'has_phone_holder_name',
}

export const PLATFORM_SPEC: FieldSpec = {
  enums: { standing: 'AccountStanding' },
  datetimes: ['standing_changed_at', 'assigned_at'],
  secrets: SECRETS_PA,
}

export const SOCIAL_SPEC: FieldSpec = {
  enums: { platform: 'SocialPlatform' },
  secrets: { password: 'has_password' },
}

const PA_LIST: ListSpec<PlatformAccountRow> = {
  filters: {
    standing: exact((a) => a.standing.value),
    workstation: exact((a) => a.workstation_id),
    team: exact((a) => find(db().workstations, a.workstation_id)?.team_id),
    assigned: flag((a) => a.workstation_id !== null),
    batch_from: dateFrom((a) => a.batch_date),
    batch_to: dateTo((a) => a.batch_date),
  },
  search: (a) => [a.email, a.discord_username],
  sorts: {
    batch_date: (a) => a.batch_date,
    standing: (a) => a.standing.value,
    standing_changed_at: (a) => a.standing_changed_at as string | null,
    assigned_at: (a) => a.assigned_at as string | null,
    email: (a) => a.email,
    created_at: (a) => a.created_at,
    id: (a) => a.id,
  },
  defaultSort: '-batch_date,-id',
}

const SA_LIST: ListSpec<SocialAccountRow> = {
  filters: {
    platform: exact((s) => s.platform.value),
    platform_account: exact((s) => s.platform_account_id),
    in_use: flag((s) => s.is_in_use),
  },
  search: (s) => {
    const parent = find(db().platform_accounts, s.platform_account_id)
    return [s.username, s.login_email, parent?.email, parent?.discord_username]
  },
  sorts: {
    created_at: (s) => s.created_at,
    created_on: (s) => s.created_on as string | null,
    username: (s) => s.username,
    platform: (s) => s.platform.value,
    is_in_use: (s) => s.is_in_use,
    id: (s) => s.id,
  },
  defaultSort: '-created_at,-id',
}

function updateAccount(
  account: PlatformAccountRow,
  changes: Json,
  actor: UserRow,
  approvalId?: number,
): void {
  const data = { ...changes }
  // UpdatePlatformAccount::prepare
  if (typeof data.standing === 'string' && data.standing !== account.standing.value) {
    data.standing_changed_at = isoNow()
  }
  if ('workstation_id' in data && data.workstation_id !== account.workstation_id) {
    data.assigned_at = data.workstation_id === null ? null : isoNow()
  }
  const real = realChanges(account, data, PLATFORM_SPEC)
  const old: Json = {}
  const logged: Json = {}
  for (const field of Object.keys(real)) {
    if (field in SECRETS_PA || field.endsWith('_at')) continue
    old[field] = rawValue(account, field, PLATFORM_SPEC)
    logged[field] = real[field]
  }
  applyFields(account, real, PLATFORM_SPEC)
  logModel('updated', 'platform_account', account, actor, logged, old, approvalId)
}

function updateSocial(
  account: SocialAccountRow,
  changes: Json,
  actor: UserRow,
  approvalId?: number,
): void {
  const real = realChanges(account, changes, SOCIAL_SPEC)
  const old: Json = {}
  const logged: Json = {}
  for (const field of Object.keys(real)) {
    if (field === 'password') continue
    old[field] = rawValue(account, field, SOCIAL_SPEC)
    logged[field] = real[field]
  }
  applyFields(account, real, SOCIAL_SPEC)
  logModel('updated', 'social_account', account, actor, logged, old, approvalId)
}

registerApplier('platform_account', {
  spec: PLATFORM_SPEC,
  find: (id) => find(db().platform_accounts, id),
  update: (row, changes, _r, actor, id) =>
    updateAccount(row as PlatformAccountRow, changes, actor, id),
  remove: (row, actor, id) => {
    remove(db().platform_accounts, row.id)
    logModel('deleted', 'platform_account', row, actor, {}, {}, id)
  },
})

registerApplier('social_account', {
  spec: SOCIAL_SPEC,
  find: (id) => find(db().social_accounts, id),
  update: (row, changes, _r, actor, id) =>
    updateSocial(row as SocialAccountRow, changes, actor, id),
  remove: (row, actor, id) => {
    remove(db().social_accounts, row.id)
    logModel('deleted', 'social_account', row, actor, {}, {}, id)
  },
})

function accountRules(v: Validator, ignoreId: number | null): void {
  v.string('email', 255)
    .email('email')
    .unique('email', (email) =>
      db().platform_accounts.some(
        (a) => a.id !== ignoreId && a.email.toLowerCase() === email.toLowerCase(),
      ),
    )
  v.string('discord_email', 255)
    .email('discord_email')
    .unique('discord_email', (email) =>
      db().platform_accounts.some(
        (a) =>
          a.id !== ignoreId && String(a.discord_email ?? '').toLowerCase() === email.toLowerCase(),
      ),
    )
  v.string('discord_username', 64)
  v.date('discord_created_on').notFuture('discord_created_on', today())
  v.string('recovery_email', 255).email('recovery_email')
  v.date('batch_date').string('notes', 5000)
  v.string('email_password', 255).string('discord_password', 255)
  v.string('recovery_phone', 64).string('phone_holder_name', 120)
}

function socialRules(v: Validator, user: UserRow, account: SocialAccountRow | null): void {
  v.integer('platform_account_id').exists('platform_account_id', visiblePlatformAccount(user))
  v.enum('platform', 'SocialPlatform')
  v.string('username', 100).string('login_email', 255).email('login_email')
  v.date('created_on').notFuture('created_on', today())
  v.boolean('is_in_use')
  if (!v.failed('platform') && !v.failed('username') && (v.has('platform') || v.has('username'))) {
    const platform = v.has('platform') ? v.value('platform') : account?.platform.value
    const username = v.has('username') ? v.value('username') : account?.username
    const taken = db().social_accounts.some(
      (s) => s.id !== account?.id && s.platform.value === platform && s.username === username,
    )
    if (taken) v.add('username', 'This username is already registered for that platform.')
  }
}

/** Obvious placeholders: the demo has no real credentials to reveal. */
function placeholder(field: string, id: number): string | null {
  switch (field) {
    case 'email_password':
      return `demo-email-password-${id}`
    case 'discord_password':
      return `demo-discord-password-${id}`
    case 'recovery_phone':
      return '+1 555 0100'
    case 'phone_holder_name':
      return 'Demo Holder'
    default:
      return `demo-password-${id}`
  }
}

function reveal(
  user: UserRow,
  subject: { type: string; id: number; label: string | null },
  fields: string[],
): Response {
  logActivity({
    logName: 'security',
    event: 'credentials_revealed',
    causer: user,
    subject,
    properties: { fields, ip: '203.0.113.x' },
  })
  return envelope(
    Object.fromEntries(fields.map((field) => [field, placeholder(field, subject.id)])),
  )
}

function revealFields(v: Validator, allowed: string[]): string[] {
  const fields = v.value('fields')
  if (!Array.isArray(fields) || fields.length === 0) {
    v.add('fields', 'The fields field is required.')
  } else {
    fields.forEach((field, index) => {
      if (!allowed.includes(String(field)))
        v.add(`fields.${index}`, `The selected fields.${index} is invalid.`)
    })
  }
  v.validate()
  return [...new Set((fields as unknown[]).map(String))]
}

export const accountHandlers = [
  route('get', '/platform-accounts', ({ user, url }) => {
    authorize(canViewAny(user, 'platform-accounts'))
    const rows = db().platform_accounts.filter((a) => platformAccountVisible(user, a))
    return json(paginate(query(rows, url, PA_LIST), url, (a) => presentPlatformAccount(a)))
  }),

  route('post', '/platform-accounts', async ({ user, body }) => {
    authorize(can(user, 'platform-accounts.create'))
    const v = new Validator(await body())
    v.required('email')
      .required('email_password')
      .required('discord_password')
      .required('batch_date')
    accountRules(v, null)
    v.integer('workstation_id').exists('workstation_id', activeWorkstation)
    v.enum('standing', 'AccountStanding')
    v.validate()
    const now = isoNow()
    const data = v.data
    const account: PlatformAccountRow = {
      id: nextId(db().platform_accounts),
      email: String(data.email),
      discord_email: (data.discord_email as string | null | undefined) ?? null,
      discord_username: (data.discord_username as string | null | undefined) ?? null,
      discord_created_on: (data.discord_created_on as string | null | undefined) ?? null,
      recovery_email: (data.recovery_email as string | null | undefined) ?? null,
      batch_date: String(data.batch_date),
      standing: enumOf('AccountStanding', (data.standing as string | undefined) ?? 'active')!,
      standing_changed_at: null,
      notes: (data.notes as string | null | undefined) ?? null,
      has_email_password: true,
      has_discord_password: true,
      has_recovery_phone: Boolean(data.recovery_phone),
      has_phone_holder_name: Boolean(data.phone_holder_name),
      workstation_id: (data.workstation_id as number | null | undefined) ?? null,
      assigned_at: data.workstation_id ? now : null,
      created_at: now,
      updated_at: now,
    }
    db().platform_accounts.push(account)
    logModel('created', 'platform_account', account, user, { email: account.email })
    return envelope(presentPlatformAccount(account), 201)
  }),

  route('post', '/platform-accounts/request-new', async ({ user, body }) => {
    authorize(can(user, 'platform-accounts.request-new'))
    const v = new Validator(await body())
    if (user.workstation_id === null) v.required('workstation_id')
    v.integer('workstation_id').exists('workstation_id', (id) => {
      const station = find(db().workstations, id)
      if (!station?.is_active) return false
      const scope = tier(user, 'platform-accounts')
      if (scope === 'all') return true
      if (scope === 'team') return station.team_id === user.team_id
      return station.id === user.workstation_id
    })
    v.required('quantity').integer('quantity', 1, 10)
    v.string('note', 500)
    const reason = reasonOf(v)
    v.validate()
    const workstationId = Number(v.value('workstation_id') ?? user.workstation_id)
    return queued(
      submitAccountRequest(
        user,
        workstationId,
        Number(v.value('quantity')),
        (v.value('note') as string | null | undefined) ?? null,
        reason,
      ),
    )
  }),

  route('get', '/platform-accounts/:id', (ctx) => {
    const account = record(db().platform_accounts, ctx, 'id')
    authorize(canView(ctx.user, 'platform_account', account))
    return envelope(presentPlatformAccount(account, true))
  }),

  route('patch', '/platform-accounts/:id', async (ctx) => {
    const { user } = ctx
    const account = record(db().platform_accounts, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'platform_account', account, 'update'))
    const v = new Validator(await ctx.body())
    v.prohibited(
      'workstation_id',
      'Use PATCH /api/platform-accounts/{id}/assign to change the workstation.',
    )
    v.prohibited(
      'standing',
      'Use PATCH /api/platform-accounts/{id}/standing to change the standing.',
    )
    if (!canOn(user, 'update', 'platform_account', account)) {
      for (const field of Object.keys(SECRETS_PA)) {
        v.prohibited(field, 'Credentials can only be changed by support or admin.')
      }
    }
    accountRules(v, account.id)
    const reason = reasonOf(v)
    v.validate()
    const changes = v.only([
      'email',
      'discord_email',
      'discord_username',
      'discord_created_on',
      'recovery_email',
      'batch_date',
      'notes',
      ...Object.keys(SECRETS_PA),
    ])
    return updateOrRequest(
      user,
      'platform_account',
      account,
      'update',
      () => {
        updateAccount(account, changes, user)
        return envelope(presentPlatformAccount(account))
      },
      () => submitUpdate(user, 'platform_account', account, changes, {}, reason),
    )
  }),

  route('delete', '/platform-accounts/:id', async (ctx) => {
    const { user } = ctx
    const account = record(db().platform_accounts, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'platform_account', account, 'delete'))
    const v = new Validator(await ctx.body())
    const reason = reasonOf(v)
    v.validate()
    return updateOrRequest(
      user,
      'platform_account',
      account,
      'delete',
      () => {
        remove(db().platform_accounts, account.id)
        logModel('deleted', 'platform_account', account, user)
        return noContent()
      },
      () => submitDelete(user, 'platform_account', account, reason),
    )
  }),

  route('patch', '/platform-accounts/:id/assign', async (ctx) => {
    const account = record(db().platform_accounts, ctx, 'id')
    authorize(
      can(ctx.user, 'platform-accounts.assign') && platformAccountVisible(ctx.user, account),
    )
    const v = new Validator(await ctx.body())
    if (!v.has('workstation_id'))
      v.add('workstation_id', 'The workstation id field must be present.')
    v.integer('workstation_id').exists('workstation_id', activeWorkstation)
    v.validate()
    const id = v.value('workstation_id')
    updateAccount(
      account,
      { workstation_id: id === null || id === '' ? null : Number(id) },
      ctx.user,
    )
    return envelope(presentPlatformAccount(account))
  }),

  route('patch', '/platform-accounts/:id/standing', async (ctx) => {
    const { user } = ctx
    const account = record(db().platform_accounts, ctx, 'id')
    const direct =
      can(user, 'platform-accounts.change-standing') && platformAccountVisible(user, account)
    const mayRequest = canOn(user, 'request-change', 'platform_account', account)
    authorize(direct || mayRequest)
    const v = new Validator(await ctx.body())
    v.required('standing').enum('standing', 'AccountStanding')
    const reason = reasonOf(v)
    v.validate()
    const standing = String(v.value('standing'))
    if (direct) {
      updateAccount(account, { standing }, user)
      return envelope(presentPlatformAccount(account))
    }
    return queued(submitUpdate(user, 'platform_account', account, { standing }, {}, reason))
  }),

  route('post', '/platform-accounts/:id/reveal', async (ctx) => {
    const account = record(db().platform_accounts, ctx, 'id')
    authorize(
      revealAllowed(ctx.user, 'platform-accounts', platformAccountVisible(ctx.user, account)),
    )
    const fields = revealFields(new Validator(await ctx.body()), Object.keys(SECRETS_PA))
    return reveal(
      ctx.user,
      { type: 'platform_account', id: account.id, label: account.email },
      fields,
    )
  }),

  // Social accounts
  route('get', '/social-accounts', ({ user, url }) => {
    authorize(canViewAny(user, 'social-accounts'))
    const rows = db().social_accounts.filter((s) => socialAccountVisible(user, s))
    return json(paginate(query(rows, url, SA_LIST), url, (s) => presentSocialAccount(s)))
  }),

  route('post', '/social-accounts', async ({ user, body }) => {
    authorize(can(user, 'social-accounts.create'))
    const v = new Validator(await body())
    v.required('platform_account_id').required('platform').required('username').required('password')
    v.string('password', 255)
    socialRules(v, user, null)
    v.validate()
    const now = isoNow()
    const data = v.data
    const account: SocialAccountRow = {
      id: nextId(db().social_accounts),
      platform: enumOf('SocialPlatform', String(data.platform))!,
      username: String(data.username),
      login_email: (data.login_email as string | null | undefined) ?? null,
      created_on: (data.created_on as string | null | undefined) ?? null,
      is_in_use: data.is_in_use === true || data.is_in_use === 1 || data.is_in_use === '1',
      has_password: true,
      platform_account_id: Number(data.platform_account_id),
      created_at: now,
      updated_at: now,
    }
    db().social_accounts.push(account)
    logModel('created', 'social_account', account, user, {
      username: account.username,
      platform: account.platform.value,
    })
    return envelope(presentSocialAccount(account), 201)
  }),

  route('get', '/social-accounts/:id', (ctx) => {
    const account = record(db().social_accounts, ctx, 'id')
    authorize(canView(ctx.user, 'social_account', account))
    return envelope(presentSocialAccount(account))
  }),

  route('patch', '/social-accounts/:id', async (ctx) => {
    const { user } = ctx
    const account = record(db().social_accounts, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'social_account', account, 'update'))
    const v = new Validator(await ctx.body())
    if (!canOn(user, 'update', 'social_account', account)) {
      v.prohibited('password', 'Credentials can only be changed by support or admin.')
    }
    v.string('password', 255)
    socialRules(v, user, account)
    const reason = reasonOf(v)
    v.validate()
    const changes = v.only([
      'platform_account_id',
      'platform',
      'username',
      'login_email',
      'password',
      'created_on',
      'is_in_use',
    ])
    return updateOrRequest(
      user,
      'social_account',
      account,
      'update',
      () => {
        updateSocial(account, changes, user)
        return envelope(presentSocialAccount(account))
      },
      () => submitUpdate(user, 'social_account', account, changes, {}, reason),
    )
  }),

  route('delete', '/social-accounts/:id', async (ctx) => {
    const { user } = ctx
    const account = record(db().social_accounts, ctx, 'id')
    authorize(mayChangeOrRequest(user, 'social_account', account, 'delete'))
    const v = new Validator(await ctx.body())
    const reason = reasonOf(v)
    v.validate()
    return updateOrRequest(
      user,
      'social_account',
      account,
      'delete',
      () => {
        remove(db().social_accounts, account.id)
        logModel('deleted', 'social_account', account, user)
        return noContent()
      },
      () => submitDelete(user, 'social_account', account, reason),
    )
  }),

  route('post', '/social-accounts/:id/reveal', async (ctx) => {
    const account = record(db().social_accounts, ctx, 'id')
    authorize(revealAllowed(ctx.user, 'social-accounts', socialAccountVisible(ctx.user, account)))
    const fields = revealFields(new Validator(await ctx.body()), ['password'])
    return reveal(
      ctx.user,
      { type: 'social_account', id: account.id, label: account.username },
      fields,
    )
  }),
]

function revealAllowed(user: UserRow, resource: string, visible: boolean): boolean {
  return can(user, `${resource}.reveal-credentials`) && visible
}
