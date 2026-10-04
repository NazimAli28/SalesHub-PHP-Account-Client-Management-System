/** Fictional fixtures for the Clients, Orders and Payments tests (src/test/* is shared and read-only). */
import type { Money } from '@/api/types'
import type { ClientDetail, ClientRecord } from '@/features/clients/types'
import type { Payment } from '@/features/payments/types'
import type { Order, OrderItem } from './types'

export function money(cents: number, currency = 'USD'): Money {
  return {
    amount_cents: cents,
    currency,
    formatted: new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100),
  }
}

export function makeClientRecord(id: number, overrides: Partial<ClientRecord> = {}): ClientRecord {
  return {
    id,
    discord_username: `streamer${id}`,
    name: `Streamer ${id}`,
    email: `streamer${id}@example.com`,
    payment_name: null,
    country: 'US',
    status: { value: 'active', label: 'Active' },
    nurturing_rating: 70,
    next_upsell_plan: 'Offer an overlay pack.',
    expected_upsell_on: '2026-11-01',
    lost_note: null,
    notes: 'Prefers pastel colours.',
    owner_id: 4,
    lifetime_value: money(120000),
    owner: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
    pending_change: null,
    created_at: '2026-08-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    ...overrides,
  }
}

export function makePayment(id: number, overrides: Partial<Payment> = {}): Payment {
  return {
    id,
    order_id: 7,
    sequence: id,
    amount: money(50000),
    currency: 'USD',
    due_date: '2026-10-20',
    status: { value: 'scheduled', label: 'Scheduled' },
    is_overdue: false,
    paid_at: null,
    method: null,
    reference: null,
    notes: null,
    recorded_by_id: null,
    order: {
      id: 7,
      order_number: 'ORD-0007',
      type: { value: 'fresh', label: 'Fresh' },
      status: { value: 'in_progress', label: 'In Progress' },
      client_id: 107,
      client: {
        id: 107,
        discord_username: 'streamer7',
        name: 'Streamer 7',
        status: null,
      },
      ordered_on: '2026-09-30',
      total: money(100000),
      amount_paid: money(0),
      balance: money(100000),
      overdue_payments_count: 0,
    },
    pending_change: null,
    created_at: '2026-09-30T10:00:00Z',
    updated_at: '2026-09-30T10:00:00Z',
    ...overrides,
  }
}

export function makeItem(id: number, overrides: Partial<OrderItem> = {}): OrderItem {
  return {
    id,
    order_id: 7,
    service_id: id + 10,
    description: null,
    quantity: 1,
    unit_price: money(50000),
    line_total: money(50000),
    service: {
      id: id + 10,
      name: `Emote pack ${id}`,
      slug: `emote-pack-${id}`,
      category: { value: 'emotes', label: 'Emotes' },
      base_price: { amount_cents: 50000, currency: 'USD', formatted: '$500.00' },
    },
    ...overrides,
  }
}

export function makeOrder(id: number, overrides: Partial<Order> = {}): Order {
  return {
    id,
    order_number: `ORD-${String(id).padStart(4, '0')}`,
    type: { value: 'fresh', label: 'Fresh' },
    status: { value: 'in_progress', label: 'In Progress' },
    currency: 'USD',
    subtotal: money(100000),
    discount: money(0),
    total: money(100000),
    amount_paid: money(0),
    balance: money(100000),
    overdue_payments_count: 0,
    ordered_on: '2026-09-30',
    delivered_at: null,
    notes: null,
    client_id: 107,
    owner_id: 4,
    closer_id: null,
    team_id: 1,
    platform_account_id: null,
    parent_order_id: null,
    client: { id: 107, discord_username: 'streamer7', name: 'Streamer 7', status: null },
    owner: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
    items: [makeItem(1, { quantity: 2, line_total: money(100000) })],
    payments: [makePayment(1)],
    pending_change: null,
    created_at: '2026-09-30T10:00:00Z',
    updated_at: '2026-09-30T10:00:00Z',
    ...overrides,
  }
}

export function makeClientDetail(id: number, overrides: Partial<ClientDetail> = {}): ClientDetail {
  const order = makeOrder(7, { client_id: id })
  return {
    ...makeClientRecord(id),
    counts: { leads: 1, orders: 1, open_orders: 1, overdue_payments: 2 },
    leads: [
      {
        id: 1,
        stage: { value: 'quoted', label: 'Quoted' },
        stage_changed_at: '2026-10-01T10:00:00Z',
        contacted_on: '2026-09-30',
        estimated_value: money(25000),
        next_follow_up_on: '2026-10-10',
        owner: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
      } as never,
    ],
    orders: [
      {
        id: order.id,
        order_number: order.order_number,
        type: order.type,
        status: order.status,
        client_id: id,
        ordered_on: order.ordered_on,
        total: money(100000),
        amount_paid: money(25000),
        balance: money(75000),
        overdue_payments_count: 2,
      },
    ],
    overdue_payments: [
      {
        id: 31,
        order_id: 7,
        order_number: 'ORD-0007',
        sequence: 1,
        amount: money(25000),
        due_date: '2026-09-15',
        status: { value: 'scheduled', label: 'Scheduled' },
        is_overdue: true,
      },
    ],
    upcoming_payments: [
      {
        id: 32,
        order_id: 7,
        order_number: 'ORD-0007',
        sequence: 2,
        amount: money(50000),
        due_date: '2026-11-15',
        status: { value: 'scheduled', label: 'Scheduled' },
        is_overdue: false,
      },
    ],
    ...overrides,
  }
}
