import { useCallback, useMemo, useState } from 'react'
import { DownloadIcon, KanbanIcon, PlusIcon, TableIcon, TargetIcon } from 'lucide-react'
import type { Lead } from '@/api/types'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { ExportCsvButton } from '@/features/imports/components/ExportCsvButton'
import { Button } from '@/components/ui/button'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { downloadCsv } from '@/lib/csv'
import { leadStages } from '@/lib/enums'
import { LEAD_LIST_CONFIG, useLeads, useOwnerOptions } from '../api'
import { getLeadColumns } from '../components/lead-columns'
import { LeadFormSheet } from '../components/LeadFormSheet'
import { LeadBoardView } from '../board/LeadBoardView'
import { useLeadsView, type LeadsView } from '../board/view-preference'

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
  const { can, canAny } = useAuth()
  const canEdit = canAny(['leads.update', 'leads.request-change'])
  const table = useDataTableParams(LEAD_LIST_CONFIG)
  const [view, setView] = useLeadsView()
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
          <>
            <ViewSwitch view={view} onChange={setView} />
            <ExportCsvButton type="leads" params={table.params} />
            <Can permission="leads.create">
              <Button onClick={openCreate}>
                <PlusIcon aria-hidden="true" />
                New lead
              </Button>
            </Can>
          </>
        }
      />

      {view === 'board' ? (
        <LeadBoardView
          table={table}
          ownerOptions={owners.data}
          ownersLoading={owners.isPending}
          showOwnerFilter={can('users.view')}
          onOpen={canEdit ? openEdit : undefined}
        />
      ) : (
        <LeadsTableView table={table} owners={owners} columns={columns} onCreate={openCreate} />
      )}

      <LeadFormSheet
        open={sheet.open}
        lead={sheet.lead}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
    </div>
  )
}

function ViewSwitch({ view, onChange }: { view: LeadsView; onChange: (view: LeadsView) => void }) {
  return (
    <ToggleGroup
      type="single"
      variant="outline"
      value={view}
      // Radix reports an empty value when the active item is clicked again; keep the view.
      onValueChange={(value) => value && onChange(value as LeadsView)}
      aria-label="Leads view"
    >
      <ToggleGroupItem value="table" aria-label="Table view">
        <TableIcon aria-hidden="true" />
        Table
      </ToggleGroupItem>
      <ToggleGroupItem value="board" aria-label="Board view">
        <KanbanIcon aria-hidden="true" />
        Board
      </ToggleGroupItem>
    </ToggleGroup>
  )
}

interface LeadsTableViewProps {
  table: ReturnType<typeof useDataTableParams>
  owners: ReturnType<typeof useOwnerOptions>
  columns: ReturnType<typeof getLeadColumns>
  onCreate: () => void
}

function LeadsTableView({ table, owners, columns, onCreate }: LeadsTableViewProps) {
  const { can } = useAuth()
  const leads = useLeads(table.params)

  return (
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
              <Button size="sm" onClick={onCreate}>
                <PlusIcon aria-hidden="true" />
                New lead
              </Button>
            </Can>
          }
        />
      }
    />
  )
}
