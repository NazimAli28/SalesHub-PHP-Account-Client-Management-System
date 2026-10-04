/**
 * Shared API types. Resource shapes come from the generated OpenAPI schema (`npm run api:types`),
 * so they stay in sync with the backend. A few fields the generator types loosely are narrowed here.
 */
import type { components } from './schema'

export type Schemas = components['schemas']

// ---------------------------------------------------------------------------
// Value formats (docs/api/conventions.md, "Value formats")
// ---------------------------------------------------------------------------

/** Backed enum as the API returns it. `TValue` narrows `value` to the enum's cases. */
export interface EnumValue<TValue extends string = string> {
  value: TValue
  label: string
}

/** Money as the API returns it. Requests send `*_cents` integers plus `currency` instead. */
export interface Money {
  amount_cents: number | null
  currency: string
  formatted: string
}

/** `YYYY-MM-DD` calendar date. */
export type IsoDate = string
/** ISO 8601 UTC timestamp with a `Z` suffix. */
export type IsoDateTime = string

// ---------------------------------------------------------------------------
// Envelopes
// ---------------------------------------------------------------------------

export interface Envelope<T> {
  data: T
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
  path: string | null
}

export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

/** Every list endpoint returns this (Laravel's paginator output). */
export interface Paginated<T> {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}

// ---------------------------------------------------------------------------
// Enums mirrored from backend/app/Enums (only the generated ones are in the schema)
// ---------------------------------------------------------------------------

export type RoleName = Schemas['RoleName']
export type LeadStage = Schemas['LeadStage']
export type LeadLostReason = Schemas['LeadLostReason']
export type AccountStanding = Schemas['AccountStanding']
export type PaymentMethod = Schemas['PaymentMethod']
export type ServiceCategory = Schemas['ServiceCategory']
export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'failed'
export type ApprovalAction = 'create' | 'update' | 'delete' | 'request_accounts'
export type ClientStatus = 'active' | 'nurturing' | 'dormant' | 'lost'
export type OrderStatus =
  'pending_payment' | 'in_progress' | 'delivered' | 'completed' | 'cancelled' | 'refunded'
export type PaymentStatus = 'scheduled' | 'paid' | 'void'

// ---------------------------------------------------------------------------
// Resources
// ---------------------------------------------------------------------------

/** The signed-in user (`GET /api/auth/me`). */
export type Me = Omit<Schemas['MeResource'], 'roles' | 'permissions'> & {
  roles: RoleName[]
  permissions: string[]
}

export type UserSummary = Schemas['UserSummaryResource']
export type User = Schemas['UserResource']
export type ClientSummary = Schemas['ClientSummaryResource']
export type Client = Schemas['ClientResource']
export type ServiceSummary = Schemas['ServiceSummaryResource']

/** What `pending_change` holds on approvable records. */
export interface PendingChange {
  id: number
  action: EnumValue<ApprovalAction>
  fields: string[]
  requested_by: UserSummary
  requested_at: IsoDateTime
}

export type Lead = Omit<
  Schemas['LeadResource'],
  'stage' | 'lost_reason' | 'estimated_value' | 'pending_change'
> & {
  stage: EnumValue<LeadStage>
  lost_reason: EnumValue<LeadLostReason> | null
  estimated_value: Money | null
  pending_change: PendingChange | null
}

export type ApprovalRequest = Omit<Schemas['ApprovalRequestResource'], 'status' | 'action'> & {
  status: EnumValue<ApprovalStatus>
  action: EnumValue<ApprovalAction>
}

export interface PendingApprovalsCount {
  /** Requests the user may review. */
  reviewable: number
  /** The user's own pending requests. */
  own: number
}

export interface UnreadNotificationsCount {
  unread: number
}
