import { useCallback, useMemo, useState } from 'react'
import { DownloadIcon, PlusIcon, TargetIcon } from 'lucide-react'
import type { Lead } from '@/api/types'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { downloadCsv } from '@/lib/csv'
import { leadStages } from '@/lib/enums'
import { LEAD_LIST_CONFIG, useLeads, useOwnerOptions } from '../api'
import { getLeadColumns } from '../components/lead-columns'
import { LeadFormSheet } from '../components/LeadFormSheet'

function exportLeads(leads: Lead[]) {
  downloadCsv('leads.csv', [
    [
      'ID',
      'Client',
      'Discord',
      'Stage',
      'Estimated value',
      'Owner',
      'Contacted on',
      'Next follow-up',
    ],
    ...leads.map((lead) => [
      lead.id,
      lead.client?.name,
      lead.client?.discord_username,
      lead.stage.label,
      lead.estimated_value?.formatted,
      lead.owner?.name,
      lead.contacted_on,
      lead.next_follow_up_on,
    ]),
  ])
}

/**
 * Reference list page. Every Phase 4 list screen follows this shape:
 * URL state (useDataTableParams) -> query hook -> memoised columns -> <DataTable>.
 */
export default function LeadsPage() {
  const { can } = useAuth()
  const table = useDataTableParams(LEAD_LIST_CONFIG)
  const leads = useLeads(table.params)
  const owners = useOwnerOptions({ enabled: can('users.view') })

  // The sheet serves both create (lead = null) and edit.
  const [sheet, setSheet] = useState<{ open: boolean; lead: Lead | null }>({
    open: false,
    lead: null,
  })
  const openCreate = () => setSheet({ open: true, lead: null })
  const openEdit = useCallback((lead: Lead) => setSheet({ open: true, lead }), [])

  const columns = useMemo(() => getLeadColumns({ onEdit: openEdit, selectable: true }), [openEdit])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Leads"
        description="Every conversation in the pipeline, from first message to won or lost."
        actions={
          <Can permission="leads.create">
            <Button onClick={openCreate}>
              <PlusIcon aria-hidden="true" />
              New lead
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Leads"
        columns={columns}
        query={leads}
        state={table}
        searchPlaceholder="Search client, email or message…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="stage"
              title="Stage"
              options={leadStages.options}
            />
            {can('users.view') ? (
              <DataTableFacetedFilter
                state={table}
                filterKey="owner"
                title="Owner"
                options={owners.data ?? []}
                isLoading={owners.isPending}
              />
            ) : null}
          </>
        }
        enableRowSelection
        bulkActions={(rows) => (
          <Button variant="outline" size="sm" onClick={() => exportLeads(rows)}>
            <DownloadIcon aria-hidden="true" />
            Export CSV
          </Button>
        )}
        emptyState={
          <EmptyState
            icon={TargetIcon}
            title="No leads yet"
            description="Leads you create, or that are assigned to you, show up here."
            action={
              <Can permission="leads.create">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New lead
                </Button>
              </Can>
            }
          />
        }
      />

      <LeadFormSheet
        open={sheet.open}
        lead={sheet.lead}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
    </div>
  )
}
