/**
 * Approvals, notifications and the audit log (routes/api/approvals.php, notifications.php,
 * audit-log.php).
 */
import { isoNow } from '../dates'
import { approve, cancel, reject } from '../engine'
import { envelope, forbidden, json, notFound, route, validationError } from '../http'
import { approvalVisible, can, canReview, canViewAny, reviewable } from '../permissions'
import { presentActivity, presentApproval } from '../present'
import {
  dateFrom,
  dateTo,
  exact,
  flag,
  onlyWhenTrue,
  paginate,
  query,
  type ListSpec,
} from '../query'
import { db } from '../store'
import type { ActivityRow, ApprovalRow, NotificationRow, UserRow } from '../types'
import { authorize, record } from './common'

function approvalList(user: UserRow): ListSpec<ApprovalRow> {
  return {
    filters: {
      status: exact((a) => a.status.value),
      action: exact((a) => a.action.value),
      type: exact((a) => a.approvable?.type ?? null),
      record: exact((a) => a.approvable?.id ?? null),
      requester: exact((a) => a.requested_by_id),
      created_from: dateFrom((a) => a.created_at),
      created_to: dateTo((a) => a.created_at),
      reviewable: onlyWhenTrue((a) => reviewable(user, a)),
    },
    sorts: {
      created_at: (a) => a.created_at,
      reviewed_at: (a) => a.reviewed_at,
      id: (a) => a.id,
    },
    defaultSort: '-created_at,-id',
  }
}

const AUDIT_LIST: ListSpec<ActivityRow> = {
  filters: {
    causer: exact((a) => a.causer_id),
    subject_type: exact((a) => a.subject?.type ?? null),
    subject_id: exact((a) => a.subject?.id ?? null),
    log_name: exact((a) => a.log_name),
    event: exact((a) => a.event),
    from: dateFrom((a) => a.created_at),
    to: dateTo((a) => a.created_at),
  },
  search: (a) => [a.description],
  sorts: { created_at: (a) => a.created_at, id: (a) => a.id },
  defaultSort: '-created_at,-id',
}

const NOTIFICATION_LIST: ListSpec<NotificationRow> = {
  filters: { unread: flag((n) => !n.is_read) },
  sorts: { created_at: (n) => n.created_at },
  defaultSort: '-created_at',
}

function viewableApproval(ctx: Parameters<typeof record>[1]): ApprovalRow {
  const approval = record(db().approvals, ctx, 'approval')
  authorize(canViewAny(ctx.user, 'approvals') && approvalVisible(ctx.user, approval))
  return approval
}

function comment(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function ownNotifications(user: UserRow): NotificationRow[] {
  return db().notifications.filter((n) => n.user_id === user.id)
}

function requireNotifications(user: UserRow): void {
  if (!can(user, 'notifications.view')) throw forbidden()
}

export const workflowHandlers = [
  route('get', '/approvals', ({ user, url }) => {
    authorize(canViewAny(user, 'approvals'))
    const rows = db().approvals.filter((a) => approvalVisible(user, a))
    return json(paginate(query(rows, url, approvalList(user)), url, (a) => presentApproval(a)))
  }),

  route('get', '/approvals/pending-count', ({ user }) => {
    authorize(canViewAny(user, 'approvals'))
    const approvals = db().approvals
    return envelope({
      reviewable: approvals.filter((a) => approvalVisible(user, a) && reviewable(user, a)).length,
      own: approvals.filter((a) => a.requested_by_id === user.id && a.status.value === 'pending')
        .length,
    })
  }),

  route('get', '/approvals/:approval', (ctx) =>
    envelope(presentApproval(viewableApproval(ctx), ctx.user, true)),
  ),

  route('post', '/approvals/:approval/approve', async (ctx) => {
    const approval = record(db().approvals, ctx, 'approval')
    authorize(canReview(ctx.user, approval))
    const body = await ctx.body()
    if (typeof body.comment === 'string' && body.comment.length > 2000) {
      throw validationError({
        comment: ['The comment field must not be greater than 2000 characters.'],
      })
    }
    return envelope(presentApproval(approve(approval, ctx.user, comment(body.comment))))
  }),

  route('post', '/approvals/:approval/reject', async (ctx) => {
    const approval = record(db().approvals, ctx, 'approval')
    authorize(canReview(ctx.user, approval))
    const body = await ctx.body()
    const text = typeof body.comment === 'string' ? body.comment.trim() : ''
    if (text === '') throw validationError({ comment: ['The comment field is required.'] })
    if (text.length < 5)
      throw validationError({ comment: ['The comment field must be at least 5 characters.'] })
    return envelope(presentApproval(reject(approval, ctx.user, text)))
  }),

  route('post', '/approvals/:approval/cancel', (ctx) => {
    const approval = record(db().approvals, ctx, 'approval')
    authorize(approval.requested_by_id === ctx.user.id && approval.status.value === 'pending')
    return envelope(presentApproval(cancel(approval, ctx.user)))
  }),

  // Notifications (the signed-in user's own)
  route('get', '/notifications', ({ user, url }) => {
    requireNotifications(user)
    const rows = ownNotifications(user)
    return json(
      paginate(query(rows, url, NOTIFICATION_LIST), url, ({ user_id: userId, ...n }) => {
        void userId
        return n
      }),
    )
  }),

  route('get', '/notifications/unread-count', ({ user }) => {
    requireNotifications(user)
    return envelope({ unread: ownNotifications(user).filter((n) => !n.is_read).length })
  }),

  route('post', '/notifications/read-all', ({ user }) => {
    requireNotifications(user)
    const now = isoNow()
    let updated = 0
    for (const n of ownNotifications(user)) {
      if (n.is_read) continue
      n.is_read = true
      n.read_at = now
      updated++
    }
    return envelope({ updated })
  }),

  route('patch', '/notifications/:id/read', ({ user, params }) => {
    requireNotifications(user)
    const notification = db().notifications.find((n) => n.id === params.id)
    if (!notification) throw notFound()
    authorize(notification.user_id === user.id)
    if (!notification.is_read) {
      notification.is_read = true
      notification.read_at = isoNow()
    }
    const { user_id: userId, ...rest } = notification
    void userId
    return envelope(rest)
  }),

  // Audit log (admin only)
  route('get', '/audit-log', ({ user, url }) => {
    authorize(can(user, 'audit-log.view'))
    return json(paginate(query(db().activities, url, AUDIT_LIST), url, presentActivity))
  }),
]
