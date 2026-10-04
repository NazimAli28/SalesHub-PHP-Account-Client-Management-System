import { useDroppable } from '@dnd-kit/core'
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable'
import type { Lead, LeadStage } from '@/api/types'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { cn } from '@/lib/utils'
import { useBoardColumn, type BoardFilters } from './board-api'
import { SortableLeadCard } from './LeadCard'

const columnId = (stage: LeadStage) => `column:${stage}`

interface LeadBoardColumnProps {
  stage: LeadStage
  label: string
  filters: BoardFilters
  onOpen?: (lead: Lead) => void
  onMove?: (lead: Lead, stage: LeadStage) => void
}

export function LeadBoardColumn({ stage, label, filters, onOpen, onMove }: LeadBoardColumnProps) {
  const query = useBoardColumn(stage, filters)
  const { setNodeRef, isOver } = useDroppable({ id: columnId(stage) })
  const leads = query.data?.pages.flatMap((page) => page.data) ?? []
  const total = query.data?.pages[0]?.meta.total

  return (
    <section
      aria-label={`${label} column`}
      className="bg-muted/40 flex max-h-[calc(100vh-14rem)] min-h-48 w-72 shrink-0 flex-col rounded-xl border"
    >
      <header className="flex items-center justify-between gap-2 px-3 py-2.5">
        <StatusBadge kind="leadStage" value={stage} />
        <span
          className="text-muted-foreground text-sm tabular-nums"
          aria-label={`${total ?? 0} leads`}
        >
          {total ?? '–'}
        </span>
      </header>

      <div
        ref={setNodeRef}
        className={cn(
          'flex flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2 transition-colors',
          isOver && 'bg-primary/5 rounded-b-xl',
        )}
      >
        <SortableContext
          items={leads.map((lead) => lead.id)}
          strategy={verticalListSortingStrategy}
        >
          {query.isPending ? (
            <>
              <Skeleton className="h-24 w-full" />
              <Skeleton className="h-24 w-full" />
            </>
          ) : query.isError ? (
            <div className="space-y-2 p-2 text-sm">
              <p className="text-destructive">Could not load this column.</p>
              <Button size="sm" variant="outline" onClick={() => void query.refetch()}>
                Retry
              </Button>
            </div>
          ) : leads.length === 0 ? (
            <p className="text-muted-foreground px-2 py-6 text-center text-sm">No leads here</p>
          ) : (
            leads.map((lead) => (
              <SortableLeadCard key={lead.id} lead={lead} onOpen={onOpen} onMove={onMove} />
            ))
          )}
        </SortableContext>

        {query.hasNextPage ? (
          <Button
            variant="ghost"
            size="sm"
            disabled={query.isFetchingNextPage}
            onClick={() => void query.fetchNextPage()}
          >
            {query.isFetchingNextPage ? <Spinner aria-hidden="true" /> : null}
            Load more
          </Button>
        ) : null}
      </div>
    </section>
  )
}
