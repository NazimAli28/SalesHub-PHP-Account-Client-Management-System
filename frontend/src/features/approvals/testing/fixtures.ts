import type { ApprovalItem } from '../api'

/** Fictional approval request for tests. */
export function makeApproval(id: number, overrides: Partial<ApprovalItem> = {}): ApprovalItem {
  return {
    id,
    action: { value: 'update', label: 'Update' },
    status: { value: 'pending', label: 'Pending' },
    approvable: { type: 'lead', id: id + 40 },
    fields: ['stage', 'estimated_value_cents'],
    payload: null,
    diff: [
      { field: 'stage', before: 'new', after: 'quoted' },
      { field: 'estimated_value_cents', before: 25000, after: 30000 },
      { field: 'next_follow_up_on', before: '2026-10-10', after: '2026-10-10' },
    ],
    reason: 'Client agreed to a bigger pack.',
    requester: { id: 5, name: 'Ayla Mercer', username: 'agent1' },
    reviewer: null,
    reviewed_at: null,
    review_comment: null,
    applied_at: null,
    failure_message: null,
    created_at: '2026-10-03T09:00:00Z',
    updated_at: '2026-10-03T09:00:00Z',
    ...overrides,
  }
}
