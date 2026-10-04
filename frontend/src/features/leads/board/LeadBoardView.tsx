import { useMemo } from 'react'
import { XIcon } from 'lucide-react'
import type { Lead } from '@/api/types'
import {
  DataTableFacetedFilter,
  DataTableSearch,
  type DataTableParams,
  type FilterOption,
} from '@/components/data-table'
import { Button } from '@/components/ui/button'
import { LeadBoard } from './LeadBoard'

interface LeadBoardViewProps {
  table: DataTableParams
  ownerOptions: FilterOption[] | undefined
  ownersLoading: boolean
  showOwnerFilter: boolean
  onOpen?: (lead: Lead) => void
}

/** Search + owner filter (shared URL state with the table) above the Kanban board. */
export function LeadBoardView({
  table,
  ownerOptions,
  ownersLoading,
  showOwnerFilter,
  onOpen,
}: LeadBoardViewProps) {
  const { search, filters } = table.params
  const owner = filters.owner
  const boardFilters = useMemo(() => ({ search, owner: owner ?? [] }), [search, owner])

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <DataTableSearch state={table} placeholder="Search client, email or message…" />
        {showOwnerFilter ? (
          <DataTableFacetedFilter
            state={table}
            filterKey="owner"
            title="Owner"
            options={ownerOptions ?? []}
            isLoading={ownersLoading}
          />
        ) : null}
        {table.hasActiveFilters ? (
          <Button variant="ghost" size="sm" onClick={table.resetFilters}>
            Clear filters
            <XIcon aria-hidden="true" />
          </Button>
        ) : null}
      </div>
      <LeadBoard filters={boardFilters} onOpen={onOpen} />
    </div>
  )
}
