/**
 * Shapes of the static demo data set (`data/demo-data.json`, written by the backend command
 * `php artisan demo:export-static`). Rows are the real API Resources with nested relation objects
 * reduced to their IDs; the in-browser API (./handlers) rebuilds them on every response.
 */

export interface EnumObj {
  value: string
  label: string
}

export interface MoneyObj {
  amount_cents: number
  currency: string
  formatted: string
}

/** Every stored row: an `id` plus whatever the Resource returned. */
export interface Row {
  id: number
  created_at?: string | null
  updated_at?: string | null
  [key: string]: unknown
}

export interface UserRow extends Row {
  name: string
  username: string
  email: string
  is_active: boolean
  team_id: number | null
  workstation_id: number | null
  roles: string[]
  last_login_at: string | null
}

export interface TeamRow extends Row {
  name: string
  display_name: string
  floor: number
  shift: EnumObj
  team_lead_id: number | null
}

export interface WorkstationRow extends Row {
  code: string
  label: string | null
  is_active: boolean
  team_id: number | null
}

export interface ServiceRow extends Row {
  name: string
  slug: string
  category: EnumObj
  description: string | null
  base_price: MoneyObj
  is_active: boolean
}

export interface ClientRow extends Row {
  discord_username: string
  name: string | null
  email: string | null
  status: EnumObj
  owner_id: number | null
  expected_upsell_on: string | null
  nurturing_rating: number | null
}

export interface NoteRow extends Row {
  client_id: number
  body: string
  is_pinned: boolean
  author_id: number | null
}

export interface LeadRow extends Row {
  stage: EnumObj
  stage_changed_at: string | null
  contacted_on: string
  estimated_value: MoneyObj | null
  currency: string
  last_message: string | null
  next_follow_up_on: string | null
  lost_reason: EnumObj | null
  lost_note: string | null
  client_id: number
  owner_id: number | null
  closer_id: number | null
  platform_account_id: number | null
  order_id: number | null
  service_ids: number[]
}

export interface OrderRow extends Row {
  order_number: string
  type: EnumObj
  status: EnumObj
  currency: string
  subtotal: MoneyObj
  discount: MoneyObj
  total: MoneyObj
  ordered_on: string
  delivered_at: string | null
  client_id: number
  owner_id: number | null
  closer_id: number | null
  team_id: number | null
  platform_account_id: number | null
  parent_order_id: number | null
}

export interface ItemRow extends Row {
  order_id: number
  service_id: number
  description: string | null
  quantity: number
  unit_price: MoneyObj
  line_total: MoneyObj
}

export interface PaymentRow extends Row {
  order_id: number
  sequence: number
  amount: MoneyObj
  currency: string
  due_date: string
  status: EnumObj
  paid_at: string | null
  method: EnumObj | null
  reference: string | null
  notes: string | null
  recorded_by_id: number | null
}

export interface PlatformAccountRow extends Row {
  email: string
  discord_username: string | null
  standing: EnumObj
  workstation_id: number | null
  batch_date: string
}

export interface SocialAccountRow extends Row {
  platform: EnumObj
  username: string
  login_email: string | null
  is_in_use: boolean
  platform_account_id: number
}

export interface ApprovalRow extends Row {
  action: EnumObj
  status: EnumObj
  approvable: { type: string; id: number | null } | null
  fields: string[]
  payload: Record<string, unknown> | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  reason: string | null
  reviewed_at: string | null
  review_comment: string | null
  applied_at: string | null
  failure_message: string | null
  requested_by_id: number
  reviewed_by_id: number | null
}

export interface NotificationRow {
  id: string
  type: string
  data: Record<string, unknown>
  is_read: boolean
  read_at: string | null
  created_at: string
  user_id: number
}

export interface ActivityRow extends Row {
  log_name: string | null
  event: string | null
  description: string
  causer_id: number | null
  subject: { type: string; id: number; label: string | null } | null
  properties: Record<string, unknown> | unknown[]
  attribute_changes: Record<string, unknown> | unknown[]
}

/** The tables the demo changes; persisted to sessionStorage after every write. */
export interface Tables {
  users: UserRow[]
  teams: TeamRow[]
  workstations: WorkstationRow[]
  services: ServiceRow[]
  clients: ClientRow[]
  client_notes: NoteRow[]
  leads: LeadRow[]
  orders: OrderRow[]
  order_items: ItemRow[]
  payments: PaymentRow[]
  platform_accounts: PlatformAccountRow[]
  social_accounts: SocialAccountRow[]
  approvals: ApprovalRow[]
  notifications: NotificationRow[]
  activities: ActivityRow[]
}

export type Overview = Record<string, unknown> & {
  kpis: Record<string, unknown>
}

export interface DemoData extends Tables {
  version: number
  exported_at: string
  export_date: string
  demo_usernames: string[]
  enums: Record<string, Record<string, string>>
  role_permissions: Record<string, string[]>
  me: Record<string, Record<string, unknown>>
  analytics: { snapshots: Record<string, Overview>; index: Record<string, string> }
}
