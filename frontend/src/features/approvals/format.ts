import {
  CreditCardIcon,
  InboxIcon,
  KeyRoundIcon,
  Share2Icon,
  ShoppingBagIcon,
  TargetIcon,
  UsersIcon,
  type LucideIcon,
} from 'lucide-react'
import { detailPath, paths } from '@/app/paths'
import { formatCents, formatDate, formatDateTime } from '@/lib/format'
import type { ApprovalItem } from './api'

export interface ApprovableTypeMeta {
  value: string
  label: string
  icon: LucideIcon
}

/** The record types an approval can target (backend morph aliases). */
export const APPROVABLE_TYPES: readonly ApprovableTypeMeta[] = [
  { value: 'lead', label: 'Lead', icon: TargetIcon },
  { value: 'client', label: 'Client', icon: UsersIcon },
  { value: 'order', label: 'Order', icon: ShoppingBagIcon },
  { value: 'payment', label: 'Payment', icon: CreditCardIcon },
  { value: 'platform_account', label: 'Platform account', icon: KeyRoundIcon },
  { value: 'social_account', label: 'Social account', icon: Share2Icon },
]

export const APPROVAL_ACTIONS = [
  { value: 'create', label: 'Create' },
  { value: 'update', label: 'Update' },
  { value: 'delete', label: 'Delete' },
  { value: 'request_accounts', label: 'Request accounts' },
] as const

const REQUEST_ACCOUNTS_META: ApprovableTypeMeta = {
  value: 'request_accounts',
  label: 'Account request',
  icon: InboxIcon,
}

const capitalize = (word: string) => word.charAt(0).toUpperCase() + word.slice(1)

export function typeMeta(approval: Pick<ApprovalItem, 'approvable'>): ApprovableTypeMeta {
  const type = approval.approvable?.type
  if (!type) return REQUEST_ACCOUNTS_META
  return (
    APPROVABLE_TYPES.find((meta) => meta.value === type) ?? {
      value: type,
      label: humanizeValue(type),
      icon: InboxIcon,
    }
  )
}

/** "Update lead #42", "Request accounts". */
export function summarize(approval: ApprovalItem): string {
  if (!approval.approvable?.type) return approval.action.label
  const meta = typeMeta(approval)
  const id = approval.approvable.id
  return `${approval.action.label} ${meta.label.toLowerCase()}${id ? ` #${id}` : ''}`
}

/** Where the target record lives in the app (detail page when one exists, else its list). */
export function targetLink(
  approval: Pick<ApprovalItem, 'approvable'>,
): { to: string; label: string } | null {
  const { type, id } = approval.approvable ?? {}
  if (!type) return null
  switch (type) {
    case 'client':
      return id ? { to: detailPath.client(id), label: `Open client #${id}` } : null
    case 'order':
      return id ? { to: detailPath.order(id), label: `Open order #${id}` } : null
    case 'platform_account':
      return id
        ? { to: detailPath.platformAccount(id), label: `Open platform account #${id}` }
        : null
    case 'lead':
      return { to: paths.leads, label: 'Open leads list' }
    case 'payment':
      return { to: paths.payments, label: 'Open payments list' }
    case 'social_account':
      return { to: paths.socialAccounts, label: 'Open social accounts list' }
    default:
      return null
  }
}

// ---------------------------------------------------------------------------
// Diff value formatting
// ---------------------------------------------------------------------------

const UPPERCASE_WORDS = new Set(['id', 'url', 'ip', 'sku'])

/** `estimated_value_cents` -> "Estimated value", `next_follow_up_on` -> "Next follow up". */
export function humanizeField(field: string): string {
  const base = field.replace(/\./g, ' ').replace(/_(cents|on|at|id)$/, '')
  const words = base.split(/[_\s]+/).filter(Boolean)
  if (words.length === 0) return field
  return words
    .map((word, index) =>
      UPPERCASE_WORDS.has(word) ? word.toUpperCase() : index === 0 ? capitalize(word) : word,
    )
    .join(' ')
}

/** `chose_competitor` -> "Chose Competitor". */
export function humanizeValue(value: string): string {
  return value.split('_').filter(Boolean).map(capitalize).join(' ')
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/
const ISO_DATE_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/
const ENUM_FIELD = /status|stage|type|standing|method|reason|category|shift/

/** A readable string for one side of a diff row; `null`/missing become an em dash. */
export function formatDiffValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'number') {
    if (field.endsWith('_cents')) return formatCents(value)
    if (field.endsWith('_id')) return `#${value}`
    return String(value)
  }
  if (typeof value === 'string') {
    if (ISO_DATE.test(value)) return formatDate(value)
    if (ISO_DATE_TIME.test(value)) return formatDateTime(value)
    if (/^[a-z]+(_[a-z]+)+$/.test(value)) return humanizeValue(value)
    if (/^[a-z]+$/.test(value) && value.length < 20 && ENUM_FIELD.test(field)) {
      return capitalize(value)
    }
    return value
  }
  if (Array.isArray(value)) {
    return value.length === 0 ? '—' : value.map((item) => formatDiffValue(field, item)).join(', ')
  }
  return JSON.stringify(value)
}

export function isChanged(row: { before: unknown; after: unknown }): boolean {
  return JSON.stringify(row.before ?? null) !== JSON.stringify(row.after ?? null)
}
