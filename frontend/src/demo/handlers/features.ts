/**
 * Search, analytics, CSV exports and imports (docs/api/features.md).
 *
 * - Analytics: answered from snapshots precomputed by the real OverviewReport at export time (the
 *   nearest range preset, per user and team filter); the pending-approvals card is counted live.
 * - Exports: the CSV is built in the browser from the same filtered, scoped list as the API.
 * - Imports: uploads answer 403 `demo_mode` (nothing is processed in the browser demo); the
 *   history is empty and the templates download as usual.
 */
import { HttpResponse } from 'msw'
import { daysBetween } from '../dates'
import { envelope, forbidden, json, notFound, route, validationError } from '../http'
import {
  approvalVisible,
  can,
  canViewAny,
  clientVisible,
  leadVisible,
  orderVisible,
  platformAccountVisible,
  reviewable,
} from '../permissions'
import { config, db, find, today } from '../store'
import type { UserRow } from '../types'
import { paginate, query } from '../query'
import { authorize } from './common'
import { CLIENT_LIST, clientCsvRow, visibleClients } from './clients'
import { LEAD_LIST, leadCsvRow, visibleLeads } from './leads'

// ---------------------------------------------------------------------------
// Search (App\Search\RecordSearch)
// ---------------------------------------------------------------------------

const PER_GROUP = 5

function search(user: UserRow, term: string) {
  const needle = term.toLowerCase()
  const has = (...values: (string | null | undefined)[]) =>
    values.some((value) => (value ?? '').toLowerCase().includes(needle))
  const groups: { key: string; label: string; hits: unknown[] }[] = []
  const { clients, leads, orders, platform_accounts: accounts } = db()

  if (canViewAny(user, 'clients')) {
    const hits = clients
      .filter((c) => clientVisible(user, c) && has(c.name, c.discord_username))
      .sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''))
      .slice(0, PER_GROUP)
      .map((c) => ({
        type: 'client',
        id: c.id,
        title: c.name || c.discord_username,
        subtitle: c.name ? c.discord_username : c.email,
        url: `/clients/${c.id}`,
      }))
    groups.push({ key: 'clients', label: 'Clients', hits })
  }
  if (canViewAny(user, 'leads')) {
    const hits = leads
      .filter((l) => {
        const client = find(clients, l.client_id)
        return (
          leadVisible(user, l) && client !== undefined && has(client.name, client.discord_username)
        )
      })
      .sort((a, b) => b.contacted_on.localeCompare(a.contacted_on) || b.id - a.id)
      .slice(0, PER_GROUP)
      .map((l) => {
        const client = find(clients, l.client_id)
        const discord = client?.discord_username ?? ''
        return {
          type: 'lead',
          id: l.id,
          title: client ? client.name || discord : '',
          subtitle: `${l.stage.label} lead, ${discord}`,
          url: `/leads?search=${encodeURIComponent(discord)}`,
        }
      })
    groups.push({ key: 'leads', label: 'Leads', hits })
  }
  if (canViewAny(user, 'orders')) {
    const hits = orders
      .filter((o) => {
        const client = find(clients, o.client_id)
        return orderVisible(user, o) && has(o.order_number, client?.name, client?.discord_username)
      })
      .sort((a, b) => b.ordered_on.localeCompare(a.ordered_on) || b.id - a.id)
      .slice(0, PER_GROUP)
      .map((o) => {
        const client = find(clients, o.client_id)
        return {
          type: 'order',
          id: o.id,
          title: o.order_number,
          subtitle: client?.name || client?.discord_username || null,
          url: `/orders/${o.id}`,
        }
      })
    groups.push({ key: 'orders', label: 'Orders', hits })
  }
  if (canViewAny(user, 'platform-accounts')) {
    const hits = accounts
      .filter((a) => platformAccountVisible(user, a) && has(a.email, a.discord_username))
      .sort((a, b) => a.email.localeCompare(b.email))
      .slice(0, PER_GROUP)
      .map((a) => ({
        type: 'platform_account',
        id: a.id,
        title: a.email,
        subtitle: a.discord_username,
        url: `/platform-accounts/${a.id}`,
      }))
    groups.push({ key: 'platform-accounts', label: 'Platform accounts', hits })
  }
  return groups
}

// ---------------------------------------------------------------------------
// Analytics (snapshots)
// ---------------------------------------------------------------------------

const PRESETS: [string, number][] = [
  ['7d', 7],
  ['30d', 30],
  ['90d', 90],
  ['12m', 365],
]

function nearestPreset(days: number): string {
  return PRESETS.reduce((best, current) =>
    Math.abs(current[1] - days) < Math.abs(best[1] - days) ? current : best,
  )[0]
}

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

function csvCell(value: unknown): string {
  let text = value === null || value === undefined ? '' : String(value)
  // CsvSanitizer: formula-looking cells become text in spreadsheets.
  if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

function csv(rows: unknown[][]): string {
  return '﻿' + rows.map((row) => row.map(csvCell).join(',')).join('\n') + '\n'
}

function csvResponse(body: string, filename: string): Response {
  return new HttpResponse(body, {
    status: 200,
    headers: {
      'Content-Type': 'text/csv; charset=UTF-8',
      'Content-Disposition': `attachment; filename="${filename}"`,
    },
  })
}

const LEAD_HEADINGS = [
  'Id',
  'Client Discord username',
  'Client name',
  'Client email',
  'Owner',
  'Stage',
  'Contacted on',
  'Estimated value',
  'Currency',
  'Last message',
  'Next follow-up on',
  'Lost reason',
  'Lost note',
  'Services',
  'Created at',
]
const CLIENT_HEADINGS = [
  'Id',
  'Discord username',
  'Name',
  'Email',
  'Payment name',
  'Country',
  'Owner',
  'Status',
  'Nurturing rating',
  'Next upsell plan',
  'Expected upsell on',
  'Notes',
  'Lifetime value (USD)',
  'Created at',
]

const TEMPLATES: Record<string, [string[], string[]]> = {
  leads: [
    [
      'client_discord_username',
      'client_name',
      'client_email',
      'stage',
      'contacted_on',
      'estimated_value',
      'currency',
      'last_message',
      'next_follow_up_on',
      'lost_reason',
      'lost_note',
      'owner_username',
    ],
    [
      'pixelpanda42',
      'Jordan Rivera',
      'jordan.rivera@example.com',
      'new',
      '2026-09-28',
      '150.00',
      'USD',
      'Asked about a stream overlay pack.',
      '2026-10-12',
      '',
      '',
      '',
    ],
  ],
  clients: [
    [
      'discord_username',
      'name',
      'email',
      'payment_name',
      'country',
      'status',
      'nurturing_rating',
      'next_upsell_plan',
      'expected_upsell_on',
      'notes',
      'owner_username',
    ],
    [
      'pixelpanda42',
      'Jordan Rivera',
      'jordan.rivera@example.com',
      'Jordan Rivera',
      'US',
      'active',
      '60',
      '',
      '',
      '',
      '',
    ],
  ],
}

function importType(value: unknown): 'leads' | 'clients' {
  if (value !== 'leads' && value !== 'clients') throw notFound()
  return value
}

export const featureHandlers = [
  route('get', '/search', ({ user, url }) => {
    const q = (url.searchParams.get('q') ?? '').trim()
    if (q.length < 2) throw validationError({ q: ['The q field must be at least 2 characters.'] })
    if (q.length > 100)
      throw validationError({ q: ['The q field must not be greater than 100 characters.'] })
    return envelope(search(user, q))
  }),

  route('get', '/analytics/overview', ({ user, url }) => {
    const scope = can(user, 'reports.view-all')
      ? 'all'
      : can(user, 'reports.view-team')
        ? 'team'
        : can(user, 'reports.view-own')
          ? 'own'
          : null
    if (scope === null) throw forbidden()
    const from = url.searchParams.get('from') ?? today()
    const to = url.searchParams.get('to') ?? today()
    if (to < from)
      throw validationError({ to: ['The to field must be a date after or equal to from.'] })
    const days = daysBetween(from, to) + 1
    if (days > 366)
      throw validationError({ to: ['The date range may not be longer than 366 days.'] })

    const teamParam = url.searchParams.get('team_id')
    const teamId = teamParam ? Number(teamParam) : null
    if (teamId !== null && scope !== 'all' && teamId !== user.team_id) throw forbidden()
    const userParam = url.searchParams.get('user_id')
    if (userParam && scope === 'own' && Number(userParam) !== user.id) throw forbidden()

    const { snapshots, snapshotIndex } = config()
    const preset = nearestPreset(days)
    const key = `${user.id}|${preset}|${scope === 'all' && teamId !== null ? teamId : ''}`
    const hash = snapshotIndex[key] ?? snapshotIndex[`${user.id}|${preset}|`]
    const snapshot = hash ? snapshots[hash] : undefined
    if (!snapshot) {
      // Users created during the visit have no snapshot: an empty but valid dashboard.
      throw forbidden('Analytics are not available for accounts created in the browser demo.')
    }
    const approvals = db().approvals
    return envelope({
      ...snapshot,
      kpis: {
        ...snapshot.kpis,
        pending_approvals: {
          reviewable: approvals.filter((a) => approvalVisible(user, a) && reviewable(user, a))
            .length,
          submitted: approvals.filter(
            (a) => a.requested_by_id === user.id && a.status.value === 'pending',
          ).length,
        },
      },
    })
  }),

  route('get', '/exports/:type', ({ user, url, params }) => {
    const type = importType(params.type)
    authorize(can(user, 'reports.export') && canViewAny(user, type))
    const date = today()
    if (type === 'leads') {
      const rows = query(visibleLeads(user), url, LEAD_LIST).slice(0, 10_000)
      return csvResponse(csv([LEAD_HEADINGS, ...rows.map(leadCsvRow)]), `leads-${date}.csv`)
    }
    const rows = query(visibleClients(user), url, CLIENT_LIST).slice(0, 10_000)
    return csvResponse(csv([CLIENT_HEADINGS, ...rows.map(clientCsvRow)]), `clients-${date}.csv`)
  }),

  route('get', '/imports', ({ url }) => json(paginate([], url, (row) => row))),

  route('get', '/imports/templates/:type', ({ user, params }) => {
    const type = importType(params.type)
    authorize(can(user, `${type}.import`))
    const [header, example] = TEMPLATES[type]!
    return csvResponse(csv([header, example]).replace('﻿', ''), `${type}-import-template.csv`)
  }),

  route('post', '/imports', ({ user }) => {
    authorize(can(user, 'leads.import') || can(user, 'clients.import'))
    return json(
      {
        message:
          'The public demo runs in your browser, so CSV imports are switched off. Run the full Laravel stack to try them.',
        code: 'demo_mode',
        errors: {
          file: [
            'CSV imports are switched off in the browser demo. Run the full Laravel stack to try them.',
          ],
        },
      },
      403,
    )
  }),

  route('get', '/imports/:id', () => {
    throw notFound()
  }),
]
