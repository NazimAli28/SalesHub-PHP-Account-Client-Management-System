import { useCallback, useEffect, useMemo, useState } from 'react'
import { CheckIcon, ClipboardCheckIcon, XIcon } from 'lucide-react'
import { useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { useQueryClient } from '@tanstack/react-query'
import { errorMessage } from '@/api/errors'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { approvalStatuses } from '@/lib/enums'
import {
  APPROVAL_LIST_CONFIG,
  decideApproval,
  invalidateAfterDecision,
  useApprovals,
  useRequesterOptions,
  type ApprovalItem,
  type ApprovalTab,
} from '../api'
import { getApprovalColumns } from '../components/approval-columns'
import { DecisionDialog } from '../components/DecisionDialog'
import { APPROVABLE_TYPES, APPROVAL_ACTIONS, summarize } from '../format'

const TAB_LABELS: Record<ApprovalTab, string> = {
  review: 'Needs my review',
  mine: 'My requests',
  all: 'All (history)',
}

interface BulkState {
  kind: 'approve' | 'reject'
  rows: ApprovalItem[]
  skipped: number
  clear: () => void
}

export default function ApprovalsPage() {
  const { user, can, canAny } = useAuth()
  const queryClient = useQueryClient()
  const table = useDataTableParams(APPROVAL_LIST_CONFIG)
  const [searchParams, setSearchParams] = useSearchParams()

  const canReview = canAny(['approvals.review-all', 'approvals.review-team'])
  const canSeeOthers = canAny(['approvals.view-all', 'approvals.view-team'])
  const tabs = useMemo<ApprovalTab[]>(
    () => [
      ...(canReview ? (['review'] as const) : []),
      'mine',
      ...(canSeeOthers ? (['all'] as const) : []),
    ],
    [canReview, canSeeOthers],
  )

  const tabParam = searchParams.get('tab')
  const tab = (tabs as string[]).includes(tabParam ?? '') ? (tabParam as ApprovalTab) : tabs[0]!

  // First visit: pin the default tab (and "pending" status) into the URL so it can be shared.
  useEffect(() => {
    if (searchParams.has('tab')) return
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current)
        next.set('tab', tab)
        if (tab !== 'all' && !next.has('status')) next.set('status', 'pending')
        return next
      },
      { replace: true },
    )
  }, [searchParams, setSearchParams, tab])

  const changeTab = (value: string) => {
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current)
        next.set('tab', value)
        next.delete('page')
        next.delete('requester')
        if (value === 'all') next.delete('status')
        else next.set('status', 'pending')
        return next
      },
      { preventScrollReset: true },
    )
  }

  const approvals = useApprovals(table.params, {
    reviewableOnly: tab === 'review',
    requesterId: tab === 'mine' ? user?.id : undefined,
  })
  const requesters = useRequesterOptions({ enabled: can('users.view') && tab !== 'mine' })

  const columns = useMemo(() => getApprovalColumns({ selectable: canReview }), [canReview])

  // ---- bulk decisions: the single endpoints, one after another --------------------------------
  const [bulk, setBulk] = useState<BulkState | null>(null)
  const [bulkPending, setBulkPending] = useState(false)

  const openBulk = useCallback(
    (kind: 'approve' | 'reject', rows: ApprovalItem[], clear: () => void) => {
      const eligible = rows.filter(
        (row) => row.status.value === 'pending' && row.requester?.id !== user?.id,
      )
      if (eligible.length === 0) {
        toast.info('Nothing to decide', {
          description: 'Only pending requests from other people can be approved or rejected.',
        })
        return
      }
      setBulk({ kind, rows: eligible, skipped: rows.length - eligible.length, clear })
    },
    [user?.id],
  )

  async function runBulk(comment: string) {
    if (!bulk) return
    setBulkPending(true)
    let succeeded = 0
    const failures: string[] = []
    for (const row of bulk.rows) {
      try {
        await decideApproval(row.id, bulk.kind, comment || null)
        succeeded += 1
      } catch (error) {
        failures.push(`${summarize(row)}: ${errorMessage(error)}`)
      }
    }
    await invalidateAfterDecision(queryClient)
    setBulkPending(false)

    const verb = bulk.kind === 'approve' ? 'Approved' : 'Rejected'
    const total = bulk.rows.length
    const skippedNote =
      bulk.skipped > 0 ? ` ${bulk.skipped} skipped (not pending or your own).` : ''
    if (failures.length === 0) {
      toast.success(`${verb} ${succeeded} ${succeeded === 1 ? 'request' : 'requests'}`, {
        description: skippedNote.trim() || undefined,
      })
    } else {
      const title = `${verb} ${succeeded} of ${total}, ${failures.length} failed`
      const description = `${failures.join('\n')}${skippedNote}`
      if (succeeded === 0) toast.error(title, { description })
      else toast.warning(title, { description })
    }
    bulk.clear()
    setBulk(null)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Approvals"
        description="Maker-checker queue: changes that need a second pair of eyes before they apply."
      />

      <Tabs value={tab} onValueChange={changeTab}>
        <TabsList aria-label="Approval views">
          {tabs.map((value) => (
            <TabsTrigger key={value} value={value}>
              {TAB_LABELS[value]}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <DataTable
        label="Approvals"
        columns={columns}
        query={approvals}
        state={table}
        searchPlaceholder={false}
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="status"
              title="Status"
              options={approvalStatuses.options}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="type"
              title="Type"
              options={APPROVABLE_TYPES.map(({ value, label }) => ({ value, label }))}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="action"
              title="Action"
              options={APPROVAL_ACTIONS.map(({ value, label }) => ({ value, label }))}
            />
            {can('users.view') && tab !== 'mine' ? (
              <DataTableFacetedFilter
                state={table}
                filterKey="requester"
                title="Requester"
                options={requesters.data ?? []}
                isLoading={requesters.isPending}
              />
            ) : null}
            <Input
              type="date"
              className="w-auto"
              aria-label="Requested from"
              value={table.params.filters.created_from?.[0] ?? ''}
              onChange={(event) =>
                table.setFilter('created_from', event.target.value ? [event.target.value] : [])
              }
            />
            <Input
              type="date"
              className="w-auto"
              aria-label="Requested to"
              value={table.params.filters.created_to?.[0] ?? ''}
              onChange={(event) =>
                table.setFilter('created_to', event.target.value ? [event.target.value] : [])
              }
            />
          </>
        }
        enableRowSelection={canReview}
        bulkActions={
          canReview
            ? (rows, clear) => (
                <>
                  <Button size="sm" onClick={() => openBulk('approve', rows, clear)}>
                    <CheckIcon aria-hidden="true" />
                    Approve selected
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => openBulk('reject', rows, clear)}
                  >
                    <XIcon aria-hidden="true" />
                    Reject selected
                  </Button>
                </>
              )
            : undefined
        }
        emptyState={
          <EmptyState
            icon={ClipboardCheckIcon}
            title={tab === 'review' ? 'Nothing waiting for you' : 'No requests'}
            description={
              tab === 'review'
                ? 'New change requests that need your review will show up here.'
                : 'Requests you send for approval will show up here.'
            }
          />
        }
      />

      <DecisionDialog
        mode={bulk?.kind ?? 'approve'}
        open={bulk !== null}
        onOpenChange={(open) => {
          if (!open && !bulkPending) setBulk(null)
        }}
        subject={`${bulk?.rows.length ?? 0} ${bulk?.rows.length === 1 ? 'request' : 'requests'}`}
        pending={bulkPending}
        onSubmit={runBulk}
      />
    </div>
  )
}
